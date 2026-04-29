# Followups

(In-repo notes for items that aren't ready to act on yet but shouldn't be forgotten.)

## Deploy self-hosted Langfuse to Railway

When deploying to Railway again (next push to the existing
`openemr-production-971e.up.railway.app` stack, or any new Railway service
for the agent / Co-Pilot), also stand up the Langfuse self-hosted stack
alongside it. Otherwise the Railway-deployed agent has no observability
endpoint reachable.

Source for the stack: `docker/langfuse/docker-compose.yml` in this repo.

Decision recorded 2026-04-29: option (a) from the three Langfuse hosting
choices — same Railway project as OpenEMR + agent, not a separate
VPS, not Langfuse Cloud.
