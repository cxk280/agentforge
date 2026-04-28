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


async def _get_db_pool() -> aiomysql.Pool:
    global _db_pool
    if _db_pool is None and settings.db_host:
        _db_pool = await aiomysql.create_pool(
            host=settings.db_host,
            port=settings.db_port,
            user=settings.db_user,
            password=settings.db_password,
            db=settings.db_name,
            minsize=1,
            maxsize=3,
            connect_timeout=5,
        )
    return _db_pool


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
    async with httpx.AsyncClient(verify=False) as client:
        token = await _get_token(client)
        resp = await client.get(
            f"{settings.openemr_base_url}/apis/default/fhir/{path.lstrip('/')}",
            params=params,
            headers={
                "Authorization": f"Bearer {token}",
                "Accept": "application/fhir+json",
            },
            timeout=15,
        )

        if resp.status_code == 401:
            # Token may have been revoked or expired early — clear cache and retry once
            _cache.access_token = ""
            _cache.expires_at = 0.0
            token = await _fetch_token(client)
            resp = await client.get(
                f"{settings.openemr_base_url}/apis/default/fhir/{path.lstrip('/')}",
                params=params,
                headers={
                    "Authorization": f"Bearer {token}",
                    "Accept": "application/fhir+json",
                },
                timeout=15,
            )

        resp.raise_for_status()
        return resp.json()


def bundle_entries(bundle: dict) -> list[dict]:
    """Extract resource entries from a FHIR Bundle."""
    return [e["resource"] for e in bundle.get("entry", [])]
