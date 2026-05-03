#!/bin/sh
# Container entrypoint for the OpenEMR PHP app on Railway.
#
# Two responsibilities:
#   1. Start the New Relic Infrastructure agent as a side-process so
#      the container shows up in NR's Infrastructure UI alongside the
#      PHP APM data.
#   2. Make sure sites/default/sqlconf.php has live MySQL credentials
#      (with $config=1) before deferring to ./openemr.sh. Without
#      this, a Railway container restart can land us with the volume
#      preserving an old/empty sqlconf.php — OpenEMR then thinks it
#      isn't configured and silently lands every PHP request on a
#      "mysqli prepare on false" 500 because the connection was never
#      attempted with real creds.
#
# Both blocks no-op when their relevant env vars are unset, so the
# same image works in local dev (no NR, no Railway-managed MySQL).

set -e

# --- 1. NR Infrastructure agent ---
if [ -n "${NEW_RELIC_LICENSE_KEY}" ]; then
    export NRIA_LICENSE_KEY="${NEW_RELIC_LICENSE_KEY}"
    export NRIA_DISPLAY_NAME="${NRIA_DISPLAY_NAME:-${NEW_RELIC_APP_NAME:-agentforge-openemr}}"
    export NRIA_CUSTOM_ATTRIBUTES="${NRIA_CUSTOM_ATTRIBUTES:-{\"app\":\"openemr\",\"env\":\"${NEW_RELIC_ENVIRONMENT:-production}\"}}"
    newrelic-infra > /tmp/newrelic-infra.log 2>&1 &
fi

# --- 2. One-shot recovery: drop openemr DB when CLEAR_OPENEMR_DB=yes ---
# Set this env var, restart the container ONCE, then unset. Used to
# recover from a partial / corrupt openemr schema (e.g. after a MySQL
# OOM that leaves tables in a half-migrated state). The container's
# normal openemr.sh path will then re-bootstrap the DB via auto_setup
# (requires MANUAL_SETUP=no for that single restart).
if [ "${CLEAR_OPENEMR_DB:-}" = "yes" ] && [ -n "${MYSQL_HOST}" ] && [ -n "${MYSQL_ROOT_PASS}" ]; then
    DBNAME="${MYSQL_DATABASE:-openemr}"
    DBPORT="${MYSQL_PORT:-3306}"
    echo "[start-with-nri] CLEAR_OPENEMR_DB=yes — dropping database '$DBNAME' on $MYSQL_HOST:$DBPORT"
    # We use PHP's mysqli (not the mariadb CLI) because the Alpine
    # openemr image ships the MariaDB *client*, but the Railway DB is
    # MySQL 9.4 which defaults to caching_sha2_password auth — older
    # MariaDB clients hang during that handshake. PHP's mysqli is
    # what OpenEMR itself uses to talk to the same DB, so we know
    # the protocol works.
    timeout 30 php -r "
        \$m = @new mysqli(getenv('MYSQL_HOST'), 'root', getenv('MYSQL_ROOT_PASS'), '', (int)(getenv('MYSQL_PORT') ?: 3306));
        if (\$m->connect_errno) { fwrite(STDERR, '[start-with-nri] connect: '.\$m->connect_error.PHP_EOL); exit(1); }
        \$db = getenv('MYSQL_DATABASE') ?: 'openemr';
        if (\$m->query('DROP DATABASE IF EXISTS \`'.\$db.'\`')) {
            fwrite(STDOUT, '[start-with-nri] drop succeeded'.PHP_EOL);
        } else {
            fwrite(STDERR, '[start-with-nri] drop failed: '.\$m->error.PHP_EOL);
        }
        \$m->close();
    " || echo "[start-with-nri] drop process failed or timed out (continuing — auto_setup may still recover)"
    # Wipe sqlconf.php so the auto-write block below + auto_setup see a clean slate.
    rm -f /var/www/localhost/htdocs/openemr/sites/default/sqlconf.php
fi

# --- 3. Idempotently ensure sqlconf.php has live credentials ---
# Only when MANUAL_SETUP=yes — otherwise we're letting openemr.sh's
# auto_setup own sqlconf.php write (and we'd race it by writing
# config=1 before the schema is loaded, which causes auto_setup to
# skip and leave the DB empty).
SQLCONF=/var/www/localhost/htdocs/openemr/sites/default/sqlconf.php
if [ "${MANUAL_SETUP:-no}" = "yes" ] \
    && [ -n "${MYSQL_HOST}" ] && [ -n "${MYSQL_USER}" ] && [ -n "${MYSQL_PASS}" ]; then
    : "${MYSQL_PORT:=3306}"
    : "${MYSQL_DATABASE:=openemr}"
    : "${MYSQL_DB_ENCODING:=utf8mb4}"
    : "${MYSQL_COLLATION:=utf8mb4_general_ci}"
    NEEDS_WRITE=1
    if [ -f "$SQLCONF" ] && grep -q '\$config\s*=\s*1' "$SQLCONF" 2>/dev/null \
        && grep -q "\$host\s*=\s*['\"]${MYSQL_HOST}['\"]" "$SQLCONF" 2>/dev/null \
        && grep -q "\$login\s*=\s*['\"]${MYSQL_USER}['\"]" "$SQLCONF" 2>/dev/null; then
        NEEDS_WRITE=0
    fi
    if [ "$NEEDS_WRITE" = "1" ]; then
        echo "[start-with-nri] writing fresh sqlconf.php from env vars"
        mkdir -p "$(dirname "$SQLCONF")"
        cat > "$SQLCONF" <<PHP
<?php
//  OpenEMR sqlconf.php — managed by docker/start-with-nri.sh
//  Regenerated from MYSQL_* env vars on every container start.
\$host        = '${MYSQL_HOST}';
\$port        = '${MYSQL_PORT}';
\$login       = '${MYSQL_USER}';
\$pass        = '${MYSQL_PASS}';
\$dbase       = '${MYSQL_DATABASE}';
\$db_encoding = '${MYSQL_DB_ENCODING}';
\$collation   = '${MYSQL_COLLATION}';

\$sqlconf = array();
global \$sqlconf;
\$sqlconf["host"]        = \$host;
\$sqlconf["port"]        = \$port;
\$sqlconf["login"]       = \$login;
\$sqlconf["pass"]        = \$pass;
\$sqlconf["dbase"]       = \$dbase;
\$sqlconf["db_encoding"] = \$db_encoding;
\$sqlconf["collation"]   = \$collation;
//  DO NOT TOUCH BELOW — \$config = 1 marks the install as complete and
//  prevents openemr.sh from re-running auto_configure.php on restart.
\$config = 1;
?>
PHP
        chmod 666 "$SQLCONF"
        # apache user owns sites/ on the openemr base image — match it
        chown apache:apache "$SQLCONF" 2>/dev/null || true
    fi
fi

exec "$@"
