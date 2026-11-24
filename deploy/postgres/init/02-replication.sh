#!/bin/sh
set -eu

psql -v ON_ERROR_STOP=1 --username shorturl --dbname shorturl \
    -c "CREATE ROLE replicator WITH REPLICATION LOGIN PASSWORD 'replicator'"

echo "host replication replicator 0.0.0.0/0 scram-sha-256" >> "$PGDATA/pg_hba.conf"
