#!/usr/bin/env bash
set -euo pipefail

# Uso: ./scripts/backup-crypto-tax-db.sh [diretório-de-destino]
# Este script é deliberadamente manual: testes nunca o invocam.

project_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
output_dir="${1:-${project_dir}/storage/backups}"

cd "$project_dir"

if [[ ! -f .env ]]; then
  echo "Erro: arquivo .env não encontrado em $project_dir" >&2
  exit 1
fi

set -a
# shellcheck disable=SC1091
source <(grep -E '^(DB_CONNECTION|DB_HOST|DB_PORT|DB_DATABASE|DB_USERNAME|DB_PASSWORD)=' .env)
set +a

: "${DB_CONNECTION:?DB_CONNECTION não está configurada}"
: "${DB_DATABASE:?DB_DATABASE não está configurada}"
: "${DB_USERNAME:?DB_USERNAME não está configurada}"

if [[ "$DB_DATABASE" == *test* || "$DB_DATABASE" == ":memory:" ]]; then
  echo "Erro: este script é destinado ao banco da aplicação, não a um banco de testes." >&2
  exit 1
fi

mkdir -p "$output_dir"
timestamp="$(date +%Y%m%d_%H%M%S)"
backup_file="$output_dir/${DB_DATABASE}_${timestamp}.sql.gz"
checksum_file="${backup_file}.sha256"

case "$DB_CONNECTION" in
  pgsql)
    command -v pg_dump >/dev/null || { echo "Erro: pg_dump não está instalado." >&2; exit 1; }
    PGPASSWORD="${DB_PASSWORD:-}" pg_dump \
      --host="${DB_HOST:-127.0.0.1}" \
      --port="${DB_PORT:-5432}" \
      --username="$DB_USERNAME" \
      --format=plain \
      --no-owner \
      --no-privileges \
      "$DB_DATABASE" | gzip -c > "$backup_file"
    ;;
  mysql|mariadb)
    command -v mysqldump >/dev/null || { echo "Erro: mysqldump não está instalado." >&2; exit 1; }
    mysqldump \
      --host="${DB_HOST:-127.0.0.1}" \
      --port="${DB_PORT:-3306}" \
      --user="$DB_USERNAME" \
      --password="${DB_PASSWORD:-}" \
      --single-transaction \
      --routines \
      --events \
      "$DB_DATABASE" | gzip -c > "$backup_file"
    ;;
  *)
    echo "Erro: conexão não suportada para backup verificável: $DB_CONNECTION" >&2
    exit 1
    ;;
esac

gzip -t "$backup_file"
sha256sum "$backup_file" > "$checksum_file"

printf 'Backup criado e verificado:\n- %s\n- %s\n' "$backup_file" "$checksum_file"
