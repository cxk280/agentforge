"""Async FHIR R4 client for OpenEMR with OAuth 2.0 password grant.

Production note on grant type: OpenEMR's FHIR API advertises only
authorization_code, password, and refresh_token grants. client_credentials
requires SMART Backend Services JWT assertions, which we don't implement.
We use the password grant with a dedicated service credential and two
defensive measures to prevent fail-counter lockout:
  1. Reset login_fail_counter to 0 after every successful token acquisition.
  2. On a 401 from the FHIR API, clear the token cache and retry once.
"""

import time
import asyncio
import aiomysql
import httpx
from dataclasses import dataclass
from config import settings


@dataclass
class _TokenCache:
    access_token: str = ""
    expires_at: float = 0.0


_cache = _TokenCache()

# DB pool for fail-counter reset — created lazily
_db_pool: aiomysql.Pool | None = None

# Shared httpx client. Reusing a single client across FHIR + token calls
# keeps TCP + TLS connections warm — a fresh AsyncClient per call adds a
# full handshake (~100-300ms) on every tool invocation, which the agent
# can't afford in the 90-second-between-rooms target.
_http_client: httpx.AsyncClient | None = None
_http_lock = asyncio.Lock()


async def _get_http_client() -> httpx.AsyncClient:
    global _http_client
    if _http_client is None:
        async with _http_lock:
            if _http_client is None:
                _http_client = httpx.AsyncClient(
                    verify=False,
                    timeout=httpx.Timeout(15.0, connect=5.0),
                    limits=httpx.Limits(
                        max_connections=20,
                        max_keepalive_connections=10,
                        keepalive_expiry=60.0,
                    ),
                )
    return _http_client


async def aclose_http_client() -> None:
    """Close the shared HTTP client. Call from FastAPI lifespan shutdown."""
    global _http_client
    if _http_client is not None:
        await _http_client.aclose()
        _http_client = None


async def get_db_pool() -> aiomysql.Pool | None:
    """Shared aiomysql pool. Returns None when no db_host is configured.

    Reused by main.py:resolve_patient and fhir_client._reset_fail_counter
    so we don't pay the per-call connection-setup cost (40-1200ms on
    docker, depending on whether the network is warm).
    """
    global _db_pool
    if _db_pool is None and settings.db_host:
        _db_pool = await aiomysql.create_pool(
            host=settings.db_host,
            port=settings.db_port,
            user=settings.db_user,
            password=settings.db_password,
            db=settings.db_name,
            minsize=2,
            maxsize=10,
            connect_timeout=5,
            # Recycle connections every 5 min so we don't keep stale ones
            # past MariaDB/MySQL's wait_timeout (usually 8h, but Railway's
            # managed MySQL has been observed to drop idle connections
            # earlier).
            pool_recycle=300,
        )
    return _db_pool


# Backwards-compat alias for the original private name (still used
# inside this module by _reset_fail_counter).
_get_db_pool = get_db_pool


async def aclose_db_pool() -> None:
    """Close the shared db pool. Call from FastAPI lifespan shutdown."""
    global _db_pool
    if _db_pool is not None:
        _db_pool.close()
        await _db_pool.wait_closed()
        _db_pool = None


async def _reset_fail_counter() -> None:
    """Reset login_fail_counter after a successful token so lockout can't accumulate."""
    try:
        pool = await _get_db_pool()
        if pool is None:
            return
        async with pool.acquire() as conn:
            async with conn.cursor() as cur:
                await cur.execute(
                    "UPDATE users_secure SET login_fail_counter = 0 WHERE username = %s",
                    (settings.openemr_username,),
                )
                await conn.commit()
    except Exception:
        pass  # fail counter reset is best-effort; don't break the request


async def _fetch_token(client: httpx.AsyncClient) -> str:
    resp = await client.post(
        f"{settings.openemr_base_url}/oauth2/default/token",
        data={
            "grant_type": "password",
            "client_id": settings.openemr_client_id,
            "client_secret": settings.openemr_client_secret,
            "username": settings.openemr_username,
            "password": settings.openemr_password,
            "user_role": "users",
            "scope": (
                "openid api:fhir "
                "user/Patient.read user/Observation.read "
                "user/MedicationRequest.read user/Condition.read "
                "user/AllergyIntolerance.read user/Encounter.read"
            ),
        },
        headers={"Content-Type": "application/x-www-form-urlencoded"},
        timeout=10,
    )
    resp.raise_for_status()
    data = resp.json()
    _cache.access_token = data["access_token"]
    _cache.expires_at = time.time() + data.get("expires_in", 3600)
    asyncio.create_task(_reset_fail_counter())
    return _cache.access_token


async def _get_token(client: httpx.AsyncClient) -> str:
    if _cache.access_token and time.time() < _cache.expires_at - 30:
        return _cache.access_token
    return await _fetch_token(client)


async def fhir_get(path: str, params: dict | None = None) -> dict:
    """GET a FHIR resource or search bundle. Retries once on 401."""
    client = await _get_http_client()
    token = await _get_token(client)
    url = f"{settings.openemr_base_url}/apis/default/fhir/{path.lstrip('/')}"
    headers = {
        "Authorization": f"Bearer {token}",
        "Accept": "application/fhir+json",
    }
    resp = await client.get(url, params=params, headers=headers)

    if resp.status_code == 401:
        # Token may have been revoked or expired early — clear cache and retry once
        _cache.access_token = ""
        _cache.expires_at = 0.0
        token = await _fetch_token(client)
        headers["Authorization"] = f"Bearer {token}"
        resp = await client.get(url, params=params, headers=headers)

    resp.raise_for_status()
    return resp.json()


def bundle_entries(bundle: dict) -> list[dict]:
    """Extract resource entries from a FHIR Bundle."""
    return [e["resource"] for e in bundle.get("entry", [])]
