#!/bin/sh
# Run on the destination server as its Docker administrator.
# Output contains private SQL/uploads/provider state. Copy it to protected off-server storage.
set -eu
umask 077
cd "$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
out="backup-$(date -u +%Y%m%dT%H%M%SZ)"
mkdir "$out"
docker compose --project-name mapa-cloud exec -T database sh -c \
  'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqldump -uroot --single-transaction --routines --triggers --events --no-tablespaces --set-gtid-purged=OFF fleetbase' > "$out/database.sql"
cp .env "$out/cloud.env"
# A live SQL backup is consistent; coordinate a maintenance window for a matching
# uploads/journal snapshot using the migration procedure. Do not copy an open SQLite file.
sha256sum "$out/database.sql" "$out/cloud.env" > "$out/SHA256SUMS"
printf 'Private database/config backup created: %s\n' "$out"
