#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

command -v php >/dev/null || { echo '[ERRO] PHP não encontrado.' >&2; exit 1; }
command -v composer >/dev/null || { echo '[ERRO] Composer não encontrado.' >&2; exit 1; }
command -v git >/dev/null || { echo '[ERRO] Git não encontrado.' >&2; exit 1; }

composer install --no-interaction --prefer-dist
php scripts/ninfa-configure.php "$ROOT"
composer validate --no-interaction

for binary in ecs rector phpstan psalm phpunit; do
    [[ -x "$ROOT/vendor/bin/$binary" ]] || {
        printf '[ERRO] vendor/bin/%s não encontrado. Instale as dependências de desenvolvimento do Ninfa.\n' "$binary" >&2
        exit 1
    }
done

bash scripts/install-security-tools.sh

if command -v lefthook >/dev/null 2>&1; then
    lefthook install
else
    printf '[AVISO] Lefthook não encontrado. Os hooks locais não foram instalados.\n'
fi

composer check

printf '\n[NINFA] Setup concluído.\n'
printf 'Comandos: composer qa | composer security | composer check | composer security:dast\n'
