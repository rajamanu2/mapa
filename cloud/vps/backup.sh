#!/bin/sh
# Scheduled private logical backup. Never stops or restarts operational services.
set -eu
umask 077
release=${MAPA_RELEASE_DIRECTORY:-/opt/mapa-staging/release}
cd -- "$release"
: "${MAPA_EXPECTED_MYSQL_VOLUME:?Set the authoritative final MySQL volume}"
: "${MAPA_EXPECTED_UPLOADS_VOLUME:?Set the authoritative final uploads volume}"
: "${RESTIC_REPOSITORY:?Configure an encrypted off-server restic repository}"
: "${RESTIC_PASSWORD_FILE:?Use a root-readable restic password file}"
case "$RESTIC_REPOSITORY" in s3:*|sftp:*|rest:https://*|azure:*|gs:*|rclone:*) ;; *) echo 'Off-server repository required' >&2; exit 1;; esac
test -s "$RESTIC_PASSWORD_FILE"
test "$(stat -c %a "$RESTIC_PASSWORD_FILE")" = 600 || { echo 'Password file must have mode 0600' >&2; exit 1; }
test -f compose.yml && test -f compose.override.yml && test -f staging.yml || { echo 'Required compose files missing' >&2; exit 1; }
compose() { docker compose --project-name mapa-vps -f compose.yml -f compose.override.yml -f staging.yml "$@"; }
# Fail closed before reading data if a compose override targets old staging volumes.
database=$(compose ps -q database)
api=$(compose ps -q api)
test -n "$database" && test -n "$api" || { echo 'Required running services missing' >&2; exit 1; }
actual_mysql=$(docker inspect --format '{{range .Mounts}}{{if eq .Destination "/var/lib/mysql"}}{{.Name}}{{end}}{{end}}' "$database")
actual_uploads=$(docker inspect --format '{{range .Mounts}}{{if eq .Destination "/fleetbase/api/storage/app"}}{{.Name}}{{end}}{{end}}' "$api")
test "$actual_mysql" = "$MAPA_EXPECTED_MYSQL_VOLUME" && test "$actual_uploads" = "$MAPA_EXPECTED_UPLOADS_VOLUME" || { echo 'Authoritative volume mismatch; backup refused' >&2; exit 1; }
command -v restic >/dev/null
command -v flock >/dev/null
mkdir -p /var/backups/mapa
exec 9>/var/backups/mapa/backup.lock
flock -n 9 || { echo 'Another backup is in progress'; exit 1; }
stage=$(mktemp -d /var/backups/mapa/.stage.XXXXXXXX)
cleanup() { case "$stage" in /var/backups/mapa/.stage.*) rm -rf -- "$stage";; esac; }
trap cleanup EXIT
compose exec -T database sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqldump -uroot --single-transaction --quick --hex-blob --routines --triggers --events --no-tablespaces --set-gtid-purged=OFF fleetbase' > "$stage/database.sql"
test -s "$stage/database.sql"
compose exec -T api tar -C /fleetbase/api/storage/app -cf - . > "$stage/uploads.tar"
test -s "$stage/uploads.tar"
cp .env "$stage/compose.env"
mkdir "$stage/private"
cp private/api.env "$stage/private/api.env"
if test -d private/provider-secrets; then cp -R private/provider-secrets "$stage/private/provider-secrets"; fi
# Do not include restic passwords or storage credentials in the backup payload.
cp compose.yml compose.override.yml staging.yml "$stage/"
compose images --format json > "$stage/images.json"
cp runtime/manifest.json "$stage/runtime-manifest.json"
printf 'Online logical snapshot; SQL and uploads are not one atomic transaction. Rehearse restore.\n' > "$stage/CONSISTENCY.txt"
(cd "$stage" && find . -type f ! -name SHA256SUMS -exec sha256sum '{}' \; > SHA256SUMS)
# Repository encryption happens on this host; no plaintext copy goes to remote storage.
# Restic init is a one-time operator action. Never prune snapshots automatically here.
restic backup --quiet --tag mapa-vps --host mapa-vps "$stage"
date -u +%FT%TZ > /var/backups/mapa/last-success
echo 'Encrypted off-server backup completed; restore verification is a separate gate.'
