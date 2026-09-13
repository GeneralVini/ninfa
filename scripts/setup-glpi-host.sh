#!/usr/bin/env bash
set -euo pipefail

GLPI_ROOT="${NINFA_GLPI_ROOT:-${RUNNER_TEMP:-/tmp}/ninfa-glpi}"
GLPI_VERSION="${NINFA_GLPI_VERSION:-11.0.8}"

if [[ "$GLPI_VERSION" != 11.* ]]; then
    printf '[ERRO] O profile glpi-plugin suporta somente GLPI 11. Solicitado: %s\n' "$GLPI_VERSION" >&2
    exit 1
fi

if [[ -f "$GLPI_ROOT/src/autoload/constants.php" ]]; then
    printf '[OK] Host GLPI já disponível em %s.\n' "$GLPI_ROOT"
    exit 0
fi

if [[ -e "$GLPI_ROOT" ]]; then
    printf '[ERRO] NINFA_GLPI_ROOT já existe, mas não contém um host GLPI reconhecível: %s\n' "$GLPI_ROOT" >&2
    exit 1
fi

git clone --depth 1 --branch "$GLPI_VERSION" https://github.com/glpi-project/glpi.git "$GLPI_ROOT"
composer install --working-dir="$GLPI_ROOT" --no-dev --no-interaction --prefer-dist --no-progress

printf '[OK] GLPI %s preparado em %s.\n' "$GLPI_VERSION" "$GLPI_ROOT"
