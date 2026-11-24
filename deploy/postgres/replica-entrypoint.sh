#!/bin/sh
set -eu

PGDATA="${PGDATA:-/var/lib/postgresql/data}"
PRIMARY_HOST="${PRIMARY_HOST:-postgres}"
REPLICATION_USER="${REPLICATION_USER:-replicator}"
REPLICATION_PASSWORD="${REPLICATION_PASSWORD:-replicator}"

mkdir -p "$PGDATA"
chown postgres:postgres "$PGDATA"
chmod 700 "$PGDATA"

if [ ! -s "$PGDATA/PG_VERSION" ]; then
    echo "waiting for primary ${PRIMARY_HOST}"
    until su-exec postgres pg_isready -h "$PRIMARY_HOST" -p 5432 -U "$REPLICATION_USER"; do
        sleep 2
    done

    export PGPASSWORD="$REPLICATION_PASSWORD"
    until su-exec postgres pg_basebackup \
        -h "$PRIMARY_HOST" \
        -p 5432 \
        -U "$REPLICATION_USER" \
        -D "$PGDATA" \
        -Fp -Xs -P -R \
        --checkpoint=fast
    do
        echo "basebackup failed, retrying"
        find "$PGDATA" -mindepth 1 -delete
        chown postgres:postgres "$PGDATA"
        sleep 2
    done
    unset PGPASSWORD
    chown -R postgres:postgres "$PGDATA"
    chmod 700 "$PGDATA"
fi

exec su-exec postgres postgres
