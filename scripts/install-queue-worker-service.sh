#!/usr/bin/env bash
set -euo pipefail

if [[ "${EUID}" -eq 0 ]]; then
  echo "Execute este script como o usuário da aplicação, sem sudo. Ele solicitará sudo apenas ao instalar o serviço." >&2
  exit 1
fi

PROJECT_PATH="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
APP_USER="${APP_USER:-${SUDO_USER:-$(id -un)}}"
APP_GROUP="${APP_GROUP:-$(id -gn)}"
PHP_BINARY="${PHP_BINARY:-$(command -v php)}"
SERVICE_NAME="crypto-tax-manager-queue"
TEMPLATE="${PROJECT_PATH}/deploy/systemd/${SERVICE_NAME}.service"
SERVICE_PATH="/etc/systemd/system/${SERVICE_NAME}.service"

if [[ ! -x "${PHP_BINARY}" ]]; then
  echo "Não foi possível localizar o binário PHP." >&2
  exit 1
fi

if [[ ! -f "${TEMPLATE}" ]]; then
  echo "Arquivo de serviço não encontrado: ${TEMPLATE}" >&2
  exit 1
fi

if ! grep -q '^QUEUE_CONNECTION=database$' "${PROJECT_PATH}/.env"; then
  echo "Defina QUEUE_CONNECTION=database no arquivo .env antes de instalar o worker." >&2
  exit 1
fi

escaped_project_path="$(printf '%s' "${PROJECT_PATH}" | sed 's/[&/]/\\&/g')"
escaped_php_binary="$(printf '%s' "${PHP_BINARY}" | sed 's/[&/]/\\&/g')"
escaped_app_user="$(printf '%s' "${APP_USER}" | sed 's/[&/]/\\&/g')"
escaped_app_group="$(printf '%s' "${APP_GROUP}" | sed 's/[&/]/\\&/g')"

temporary_unit="$(mktemp)"
trap 'rm -f "${temporary_unit}"' EXIT

sed \
  -e "s/__PROJECT_PATH__/${escaped_project_path}/g" \
  -e "s/__PHP_BINARY__/${escaped_php_binary}/g" \
  -e "s/__APP_USER__/${escaped_app_user}/g" \
  -e "s/__APP_GROUP__/${escaped_app_group}/g" \
  "${TEMPLATE}" > "${temporary_unit}"

sudo install -m 0644 "${temporary_unit}" "${SERVICE_PATH}"
sudo systemctl daemon-reload
sudo systemctl enable --now "${SERVICE_NAME}"
sudo systemctl status "${SERVICE_NAME}" --no-pager

echo
echo "Worker configurado. A sincronização Binance será executada automaticamente ao ser solicitada pela interface."
