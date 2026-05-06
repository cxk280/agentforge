from pydantic_settings import BaseSettings


class Settings(BaseSettings):
    openemr_base_url: str = "http://localhost:8300"
    openemr_client_id: str = "clinicalcopilot"
    openemr_client_secret: str = ""
    openemr_username: str = "admin"
    openemr_password: str = "pass"
    # Shared secret used by the agent to fetch document bytes from
    # `copilot_documents_serve.php` without an OpenEMR session. Both
    # the agent and the OpenEMR container must have this value set to
    # the same string. The agent and the OpenEMR container have
    # separate filesystems in every deploy (Mac docker dev,
    # Railway dev/qa/prod), so the agent's only general way to reach
    # uploaded PDF bytes is via this HTTP route.
    copilot_internal_token: str = ""
    anthropic_api_key: str
    model: str = "claude-sonnet-4-6"
    langfuse_public_key: str = ""
    langfuse_secret_key: str = ""
    langfuse_host: str = "https://cloud.langfuse.com"
    # Direct DB access for fail-counter reset (optional — omit to disable)
    db_host: str = ""
    db_port: int = 3306
    db_user: str = "root"
    db_password: str = ""
    db_name: str = "openemr"

    class Config:
        env_file = ".env"


settings = Settings()
