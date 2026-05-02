# AgentForge CI/CD — CircleCI

Pipeline: `unit-test` → `deploy-dev` (auto) → `eval-smoke-dev` →
`hold-qa` (manual) → `deploy-qa` → `eval-full-qa` → `hold-prod`
(manual) → `deploy-prod` → `eval-full-prod`.

Auto-deploy on push to `master`; manual buttons in the CircleCI UI
gate the QA and Prod promotions.

## One-time setup steps

### 1. Connect the project to CircleCI

The repo lives on self-hosted gitlab (`labs.gauntletai.com`).
CircleCI's standard integrations cover GitHub and GitLab Cloud
(`gitlab.com`) but not arbitrary self-hosted GitLab. Two paths:

- **(Easier)** Mirror the gitlab repo to GitHub. CircleCI picks up
  pushes via the GitHub integration. The mirror can be one-way
  (gitlab → github) via a `git push` from CI, or via a passive
  GitLab → GitHub mirror in gitlab settings.
- **(Heavier)** Run a self-hosted CircleCI runner inside the
  Gauntlet network so it can poll your gitlab instance. CircleCI
  Cloud + self-hosted runners is supported but adds infra.

Recommend **GitHub mirror** for the demo timeline.

### 2. CircleCI project env vars

CircleCI dashboard → Project Settings → Environment Variables:

| Name | Source | Notes |
|---|---|---|
| `ANTHROPIC_API_KEY` | Anthropic console | judge model + agent fallback |
| `LANGFUSE_PUBLIC_KEY` | self-hosted Langfuse | dataset uploads |
| `LANGFUSE_SECRET_KEY` | self-hosted Langfuse |  |
| `LANGFUSE_HOST` | https://langfuse-web-production-368f.up.railway.app |  |
| `RAILWAY_TOKEN_DEV` | Railway dashboard, scoped to `dev` env | least-privilege |
| `RAILWAY_TOKEN_QA` | Railway dashboard, scoped to `qa` env |  |
| `RAILWAY_TOKEN_PROD` | Railway dashboard, scoped to `prod` env |  |

To generate a scoped Railway token: Railway dashboard → Project →
Settings → Tokens → New Token → assign to a single environment.

### 3. First push

After the env vars are set, push to `master`. The pipeline runs
unit tests + deploy-dev automatically. Approve the QA and Prod
gates manually in the CircleCI UI when ready.

## Local equivalents

The same checks run locally via the pre-push hook (`scripts/pre-push.sh`):
phpunit-isolated. To run an eval smoke locally:

```bash
cd copilot/agent/evals
source .venv/bin/activate
export ANTHROPIC_API_KEY=... LANGFUSE_*=...
python run_evals.py --smoke
```

## Promotion semantics

Each `deploy-*` job currently runs `railway up` against the
target env, which builds + deploys from source. A truer
"promotion" (re-deploy the same image that passed Dev/QA) would
push a built image to a registry and deploy by digest. That's
a follow-up — for now we accept that the same source revision
gets rebuilt per environment.

## What blocks promotion

- `eval-smoke-dev` failure → blocks the QA approval button.
- `eval-full-qa` failure (< 23/25 passed) → blocks the Prod
  approval button.
- Manual approval is still required even when evals pass — humans
  decide when QA is ready to go to Prod.

## Failure recovery

If a deploy fails mid-pipeline, the previous deployment stays
serving traffic on the affected env (Railway only swaps after a
successful health check). Re-running the failing job in CircleCI
is safe.
