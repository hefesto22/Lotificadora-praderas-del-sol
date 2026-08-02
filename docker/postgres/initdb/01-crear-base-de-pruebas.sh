#!/bin/sh
# ─────────────────────────────────────────────────────────────────
# Crea la base de pruebas y habilita CREATEDB en el rol.
#
# Corre UNA sola vez: el entrypoint de Postgres ejecuta este
# directorio solo cuando PGDATA está vacío (primer arranque o
# después de `docker compose down -v`).
#
# CREATEDB es obligatorio porque `pest --parallel` crea bases
# sufijadas praderas_test_1..N en cada corrida (§7.2).
# ─────────────────────────────────────────────────────────────────
set -e

TEST_DB="${POSTGRES_TEST_DB:-praderas_test}"

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<-EOSQL
    CREATE DATABASE "$TEST_DB";
    ALTER ROLE "$POSTGRES_USER" CREATEDB;
EOSQL

echo "initdb: base de pruebas '$TEST_DB' creada y CREATEDB habilitado en '$POSTGRES_USER'."
