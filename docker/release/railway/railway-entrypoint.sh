#!/usr/bin/env bash
#
# Railway boot wrapper for the OpenEMR demo deployment.
#
# Runs as the platform startCommand, ahead of the stock openemr.sh. It exists
# because the demo ships with a pre-seeded database: the stock entrypoint would
# otherwise see an uninitialised sqlconf.php and run a fresh install over the
# top of it.
#
# Sequence:
#   1. Repopulate the documents/ tree, which the Railway volume mount shadows
#   2. Wait for the database
#   3. Decide whether to restore the seed (SEED_RESET)
#   4. Restore
#   5. Write sqlconf.php with $config = 1 so openemr.sh skips auto_configure
#   6. Rotate the admin password away from the baked-in default
#   7. exec openemr.sh
#
# FAIL-FAST IS LOAD BEARING. If any step fails this script must exit non-zero
# and take the container with it. It must never fall through to openemr.sh,
# because that path would run the fresh installer -- which needs
# contrib/util/language_translations, a 32MB tree deliberately excluded from
# the build context to fit under Railway's upload cap. A silent fallthrough
# would produce an empty EMR with a default admin password.
set -euo pipefail

OE_ROOT=/var/www/localhost/htdocs/openemr
SITE_DIR="${OE_ROOT}/sites/default"
DOCS_DIR="${SITE_DIR}/documents"
METHODS_DIR="${DOCS_DIR}/logs_and_misc/methods"
SEED_DIR=/opt/openemr-seed
HARDEN_PHP=/opt/openemr-railway/harden.php

log() { printf '[railway] %s\n' "$*"; }
die() { printf '[railway] FATAL: %s\n' "$*" >&2; exit 1; }

# ---------------------------------------------------------------------------
# Required configuration
# ---------------------------------------------------------------------------
: "${MYSQL_HOST:?MYSQL_HOST is required}"
: "${MYSQL_USER:?MYSQL_USER is required}"
: "${MYSQL_PASS:?MYSQL_PASS is required}"
: "${MYSQL_DATABASE:?MYSQL_DATABASE is required}"
MYSQL_PORT="${MYSQL_PORT:-3306}"
MYSQL_COLLATION="${MYSQL_COLLATION:-utf8mb4_general_ci}"
SEED_RESET="${SEED_RESET:-if-changed}"

# The stock entrypoint defaults OE_PASS to "pass". This deployment is publicly
# reachable, so refuse to boot without an explicit admin password.
[ -n "${OE_ADMIN_PASSWORD:-}" ] \
    || die "OE_ADMIN_PASSWORD is unset -- refusing to boot with a default admin password"

SEED_FILE="$(find "${SEED_DIR}" -maxdepth 1 -name '*.sql.gz' -type f 2>/dev/null | sort | head -1)"
[ -n "${SEED_FILE}" ] || die "no seed dump found in ${SEED_DIR}"

# mariadb-client is installed in the image. --skip-ssl because Railway's private
# network is already isolated and the server may not present a cert.
db() {
    mariadb --skip-ssl \
        --host="${MYSQL_HOST}" --port="${MYSQL_PORT}" \
        --user="${MYSQL_USER}" --password="${MYSQL_PASS}" "$@"
}

# ---------------------------------------------------------------------------
# 1. Repopulate the volume-shadowed documents tree
#
# Railway mounts volumes at container start, and the mount SHADOWS whatever the
# image had at that path -- there is no copy-on-first-use. The Dockerfile rsyncs
# the whole sites tree to /swarm-pieces, so restore documents/ from there.
#
# The volume is deliberately mounted at documents/ rather than sites/. Mounting
# at sites/ would blank sites/default/docker-version, and check_upgrade() in
# openemr.sh compares it against /root/docker-version -- a blank read of 0 makes
# "11 > 0" true and fires fsupgrade-1.sh .. fsupgrade-11.sh against the freshly
# restored seed on every single boot.
# ---------------------------------------------------------------------------
if [ ! -d "${METHODS_DIR}" ]; then
    log "documents/ is empty (fresh volume) -- restoring from /swarm-pieces"
    rsync -a --links /swarm-pieces/sites/default/documents/ "${DOCS_DIR}/" \
        || die "failed to restore documents/ from /swarm-pieces"
fi

# The crypto drive keys live here and must be creatable by apache on first boot.
# They are NOT shipped: CryptoGen stores them as ciphertext wrapped under the
# database key, so with the files absent and the keys table restored from the
# dump, createDriveKey() mints a fresh, self-consistent pair. Shipping keys from
# another database instead throws CryptoGenException at runtime.
mkdir -p "${METHODS_DIR}"
chown -R apache:apache "${DOCS_DIR}"
find "${DOCS_DIR}" -type d -exec chmod 700 {} +
find "${DOCS_DIR}" -type f -exec chmod 600 {} +

# ---------------------------------------------------------------------------
# 2. Wait for the database (private DNS needs a moment at boot)
# ---------------------------------------------------------------------------
log "waiting for database at ${MYSQL_HOST}:${MYSQL_PORT}"
for attempt in $(seq 1 90); do
    if mariadb-admin --skip-ssl --silent \
        --host="${MYSQL_HOST}" --port="${MYSQL_PORT}" \
        --user="${MYSQL_USER}" --password="${MYSQL_PASS}" ping >/dev/null 2>&1; then
        log "database reachable after ${attempt} attempt(s)"
        break
    fi
    [ "${attempt}" -eq 90 ] && die "database never became reachable (180s)"
    sleep 2
done

# ---------------------------------------------------------------------------
# 3. Decide whether to restore
#
#   if-changed (default) : restore when the DB is empty OR the dump's sha256
#                          differs from what was last loaded. Ordinary code
#                          redeploys therefore leave demo data untouched.
#   if-empty             : restore only into an empty database
#   force                : always restore (the "reset demo data" path)
#   never                : never restore
# ---------------------------------------------------------------------------
seed_sha="$(sha256sum "${SEED_FILE}" | cut -d' ' -f1)"
table_count="$(db --skip-column-names --batch --execute \
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${MYSQL_DATABASE}'" \
    2>/dev/null || echo 0)"
marker_sha="$(db --skip-column-names --batch --database="${MYSQL_DATABASE}" \
    --execute "SELECT sha FROM railway_seed_marker LIMIT 1" 2>/dev/null || true)"

should_restore=no
case "${SEED_RESET}" in
    force)      should_restore=yes ;;
    never)      should_restore=no ;;
    if-empty)   [ "${table_count}" -eq 0 ] && should_restore=yes ;;
    if-changed) { [ "${table_count}" -eq 0 ] || [ "${marker_sha}" != "${seed_sha}" ]; } && should_restore=yes ;;
    *)          die "unrecognised SEED_RESET='${SEED_RESET}' (want: if-changed|if-empty|force|never)" ;;
esac

if [ "${should_restore}" = yes ]; then
    log "restoring seed ${seed_sha} into '${MYSQL_DATABASE}' (SEED_RESET=${SEED_RESET}, ${table_count} tables present)"
    db --execute "DROP DATABASE IF EXISTS \`${MYSQL_DATABASE}\`;
                  CREATE DATABASE \`${MYSQL_DATABASE}\`
                      CHARACTER SET utf8mb4 COLLATE ${MYSQL_COLLATION};" \
        || die "could not recreate database '${MYSQL_DATABASE}'"

    # PIPESTATUS check: without it a failing mariadb import is masked by gzip's
    # successful exit, and the container would boot onto a half-restored DB.
    set +e
    gzip -dc "${SEED_FILE}" | db --database="${MYSQL_DATABASE}"
    rc=("${PIPESTATUS[@]}")
    set -e
    [ "${rc[0]}" -eq 0 ] || die "failed to decompress ${SEED_FILE}"
    [ "${rc[1]}" -eq 0 ] || die "seed import failed -- database is incomplete"

    db --database="${MYSQL_DATABASE}" --execute \
        "CREATE TABLE IF NOT EXISTS railway_seed_marker (sha CHAR(64) NOT NULL PRIMARY KEY);
         DELETE FROM railway_seed_marker;
         INSERT INTO railway_seed_marker (sha) VALUES ('${seed_sha}');" \
        || die "could not record seed marker"

    # The restore replaced the `keys` table. The drive key files under
    # documents/logs_and_misc/methods/ are not raw keys -- they are ciphertext
    # wrapped under that database key. Files left over from a previous seed can
    # no longer be decrypted, and CryptoGen fails hard with
    # "Key in drive is not compatible (ie. can not be decrypted) with key in
    # database - Exiting." Delete them so collectDriveKey() regenerates a pair
    # consistent with the database keys we just restored.
    if [ -d "${METHODS_DIR}" ]; then
        find "${METHODS_DIR}" -maxdepth 1 -type f ! -name 'README.md' -delete
        log "cleared stale drive keys for regeneration"
    fi

    log "restore complete"
else
    log "skipping restore (SEED_RESET=${SEED_RESET}, ${table_count} tables present)"
fi

# ---------------------------------------------------------------------------
# 4. Write sqlconf.php with $config = 1
#
# openemr.sh re-reads $config and skips run_auto_configure entirely when it is
# 1, which is what keeps the installer from overwriting the restored seed.
# ---------------------------------------------------------------------------
log "writing sqlconf.php"
chmod 0666 "${SITE_DIR}/sqlconf.php" 2>/dev/null || true
cat > "${SITE_DIR}/sqlconf.php" <<PHPCONF
<?php
// Generated by railway-entrypoint.sh on container start. Do not edit by hand:
// it is rewritten from the environment on every boot.
\$host   = '${MYSQL_HOST}';
\$port   = '${MYSQL_PORT}';
\$login  = '${MYSQL_USER}';
\$pass   = '${MYSQL_PASS}';
\$dbase  = '${MYSQL_DATABASE}';

global \$sqlconf;
\$sqlconf = array();
\$sqlconf["host"]  = \$host;
\$sqlconf["port"]  = \$port;
\$sqlconf["login"] = \$login;
\$sqlconf["pass"]  = \$pass;
\$sqlconf["dbase"] = \$dbase;

\$config = 1;
PHPCONF
chown apache:apache "${SITE_DIR}/sqlconf.php"
chmod 0600 "${SITE_DIR}/sqlconf.php"

# ---------------------------------------------------------------------------
# 5. Register the Clinical Co-Pilot module
#
# Runs on EVERY boot, not just after a restore. The demo seed was captured
# before this module existed, so a restored database has no `modules` row for
# it -- and ModulesApplication only loads custom modules where
# mod_active = 1 AND type != 1. Without this the co-pilot would silently vanish
# after any re-seed.
#
# Written as plain idempotent SQL rather than the module's table.sql, because
# table.sql uses OpenEMR's #IfNotTable preprocessor directives, which the mysql
# client cannot parse -- those are interpreted by the Modules Manager installer.
# ---------------------------------------------------------------------------
log "registering clinical co-pilot module"
db --database="${MYSQL_DATABASE}" <<'MODULESQL' || die "module registration failed"
CREATE TABLE IF NOT EXISTS `clinical_copilot_log` (
  `id`             BIGINT(20)   NOT NULL AUTO_INCREMENT,
  `correlation_id` VARCHAR(36)      NULL,
  `pid`            BIGINT(20)   NOT NULL,
  `user`           VARCHAR(255) NOT NULL,
  `asked_at`       DATETIME     NOT NULL,
  `question`       TEXT         NOT NULL,
  `reply`          MEDIUMTEXT       NULL,
  `tools_used`     VARCHAR(255)     NULL,
  `model`          VARCHAR(64)      NULL,
  `success`        TINYINT(1)   NOT NULL DEFAULT 0,
  `latency_ms`     INT UNSIGNED     NULL,
  `verification_passed` TINYINT(1) NULL,
  PRIMARY KEY (`id`),
  KEY `pid_asked_at` (`pid`, `asked_at`),
  KEY `correlation_id` (`correlation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Idempotent upgrade for a demo volume seeded before these columns existed.
-- Uses INFORMATION_SCHEMA + dynamic SQL rather than "ADD COLUMN IF NOT
-- EXISTS": the `mariadb` CLI here is just the client binary -- the actual
-- Railway database is MySQL, and that DDL sugar does not parse against it
-- (confirmed via a production crash-loop). A fresh CREATE TABLE above
-- already has all three columns, so each block below is a no-op there.
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clinical_copilot_log'
     AND COLUMN_NAME = 'correlation_id'
);
SET @ddl := IF(@col_exists = 0,
  'ALTER TABLE `clinical_copilot_log` ADD COLUMN `correlation_id` VARCHAR(36) NULL AFTER `id`',
  'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clinical_copilot_log'
     AND COLUMN_NAME = 'latency_ms'
);
SET @ddl := IF(@col_exists = 0,
  'ALTER TABLE `clinical_copilot_log` ADD COLUMN `latency_ms` INT UNSIGNED NULL AFTER `success`',
  'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clinical_copilot_log'
     AND COLUMN_NAME = 'verification_passed'
);
SET @ddl := IF(@col_exists = 0,
  'ALTER TABLE `clinical_copilot_log` ADD COLUMN `verification_passed` TINYINT(1) NULL AFTER `latency_ms`',
  'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Multi-turn conversation state (table.sql's clinical_copilot_conversation).
-- Same reason as clinical_copilot_log above: table.sql's #IfNotTable directive
-- is never interpreted on this boot path, so without a hand-rolled equivalent
-- here this table has never existed on Railway -- every read/write has been
-- silently failing (caught by SqlConversationStore, degrading to memory-less
-- turns) since the conversation feature shipped.
CREATE TABLE IF NOT EXISTS `clinical_copilot_conversation` (
  `id`             BIGINT(20)   NOT NULL AUTO_INCREMENT,
  `session_uuid`   VARCHAR(36)  NOT NULL,
  `pid`            BIGINT(20)   NOT NULL,
  `turns_json`     MEDIUMTEXT   NOT NULL,
  `created_at`     DATETIME     NOT NULL,
  `last_updated`   DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `session_pid` (`session_uuid`, `pid`),
  KEY `last_updated` (`last_updated`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Document ingestion (table.sql's clinical_copilot_extracted_document).
-- Same reason as the two tables above: table.sql's #IfNotTable directive is
-- never interpreted on this boot path, so without this hand-rolled
-- equivalent, DocumentIngestionPipeline would silently fail every save() on
-- Railway the same way clinical_copilot_conversation did before this file
-- was fixed for it.
CREATE TABLE IF NOT EXISTS `clinical_copilot_extracted_document` (
  `id`             BIGINT(20)   NOT NULL AUTO_INCREMENT,
  `pid`            BIGINT(20)   NOT NULL,
  `document_id`    BIGINT(20)       NULL,
  `doc_type`       VARCHAR(32)  NOT NULL,
  `fields_json`    MEDIUMTEXT   NOT NULL,
  `created_at`     DATETIME     NOT NULL,
  `last_updated`   DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `pid` (`pid`),
  KEY `doc_type` (`doc_type`),
  KEY `document_id` (`document_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `modules`
    (`mod_name`, `mod_directory`, `mod_parent`, `mod_type`, `mod_active`,
     `mod_ui_name`, `mod_relative_link`, `mod_ui_order`, `mod_ui_active`,
     `mod_description`, `mod_nick_name`, `mod_enc_menu`, `directory`, `date`,
     `sql_run`, `type`, `sql_version`, `acl_version`)
SELECT 'Clinical Co-Pilot', 'oe-module-clinical-copilot', '', '', 1,
       'Clinical Co-Pilot', '', 0, 1,
       'AI clinical co-pilot for the patient chart', 'copilot', 'no',
       'oe-module-clinical-copilot', NOW(), 1, 0, '1.0.0', '1.0.0'
  FROM DUAL
 WHERE NOT EXISTS (
     SELECT 1 FROM `modules` WHERE `mod_directory` = 'oe-module-clinical-copilot'
 );

UPDATE `modules`
   SET `mod_active` = 1, `mod_ui_active` = 1, `type` = 0
 WHERE `mod_directory` = 'oe-module-clinical-copilot';
MODULESQL

# ---------------------------------------------------------------------------
# 6. Rotate the admin password
#
# Dropped to apache so anything globals.php touches stays apache-owned.
# ---------------------------------------------------------------------------
log "rotating admin password"
su-exec apache php "${HARDEN_PHP}" || die "admin password rotation failed"

# ---------------------------------------------------------------------------
# 7. Hand off to the stock entrypoint
#
# From here openemr.sh runs unmodified. With $config = 1 it skips the installer,
# applies every OPENEMR_SETTING_* variable via setGlobalSettings (this is what
# turns the REST/FHIR/OAuth APIs back off after the full-dump restore), deletes
# the setup scripts from the web root, and starts Apache.
# ---------------------------------------------------------------------------
log "handing off to openemr.sh"
cd "${OE_ROOT}"
exec ./openemr.sh
