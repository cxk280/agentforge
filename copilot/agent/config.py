from pydantic_settings import BaseSettings


class Settings(BaseSettings):
    openemr_base_url: str = "http://localhost:8300"
    openemr_client_id: str = "clinicalcopilot"
    openemr_client_secret: str = ""
    openemr_username: str = "admin"
    openemr_password: str = "pass"
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
