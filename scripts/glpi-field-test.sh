#!/usr/bin/env bash
# Field test controlado do profile GLPI: executa somente sobre cópia descartável.
#
# Uso: bash scripts/glpi-field-test.sh /var/www/psglpi/plugins/sigaps assist
# Modes: check, assist, security, fix (todos exclusivamente sobre a cópia).
# Rejeita symlinks para evitar que ferramentas escrevam fora da sandbox.
set -euo pipefail

# @var NINFA_ROOT absolute-path — repositório do field test.
NINFA_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# @var SOURCE absolute-path — original, nunca alvo da execução.
SOURCE="${1:-/var/www/psglpi/plugins/sigaps}"
# @var MODE enum — operação exclusiva da cópia.
MODE="${2:-assist}"

if [[ $# -gt 2 ]]; then
    printf '[ERRO] Uso: %s [plugin-root] [check|assist|security|fix]\n' "$0" >&2
    exit 2
fi
case "$MODE" in
    check|assist|security|fix) ;;
    *)
        printf '[ERRO] Operação inválida: %s\n' "$MODE" >&2
        exit 2
        ;;
esac
if [[ ! -d "$SOURCE" || ! -f "$SOURCE/setup.php" || -L "$SOURCE" ]]; then
    printf '[ERRO] Plugin GLPI não reconhecido ou path simbólico: %s\n' "$SOURCE" >&2
    exit 2
fi
SOURCE="$(realpath -- "$SOURCE")"

# Um symlink no projeto pode escapar da cópia e alcançar arquivos do original.
if [[ -n "$(find "$SOURCE" -type l -print -quit)" ]]; then
    printf '[ERRO] Projeto contém symlinks; isolamento de escrita não comprovado.\n' >&2
    exit 2
fi

# @var SANDBOX absolute-path — cópia, configurações, logs e evidências.
SANDBOX="$(mktemp -d /tmp/ninfa-glpi-field-test.XXXXXXXX)"
# @var CLONE absolute-path — único projeto apontado ao CLI do NINFA.
CLONE="$SANDBOX/plugin"
# @var BEFORE absolute-path — estado inicial do original.
BEFORE="$SANDBOX/original-before.sha256"
# @var AFTER absolute-path — estado final do original.
AFTER="$SANDBOX/original-after.sha256"

# @function snapshot — lê hashes e metadata do consumidor sem gravar nele.
snapshot() {
    local root="$1"
    local destination="$2"
    {
        (cd "$root" && find . -printf '%P|%y|%m|%s|%T@|%u|%g\n' | LC_ALL=C sort)
        (cd "$root" && find . -type f -print0 | LC_ALL=C sort -z | xargs -0 -r sha256sum)
    } > "$destination"
}

# @function finish — verifica integridade inclusive em caso de erro de ferramenta.
finish() {
    local status=$?
    trap - EXIT
    if [[ -f "$BEFORE" ]]; then
        if ! snapshot "$SOURCE" "$AFTER"; then
            printf '[ERRO] Falha ao inventariar o original após a execução.\n' >&2
            status=90
        elif ! cmp -s "$BEFORE" "$AFTER"; then
            printf '[ERRO] O estado do projeto original mudou! Inspecione a diferença.\n' >&2
            diff -u "$BEFORE" "$AFTER" > "$SANDBOX/original-diff.txt" || true
            status=90
        else
            printf '[OK] Integridade do original preservada (hash e metadata).\n'
        fi
    fi
    printf '[NINFA] Evidências e cópia: %s\n' "$SANDBOX"
    exit "$status"
}
trap finish EXIT

snapshot "$SOURCE" "$BEFORE"
mkdir -p "$CLONE"
cp -a -- "$SOURCE/." "$CLONE/"
mkdir -p "$SANDBOX/workspace"

# Host é contexto de análise, nunca alvo de install/uninstall.
export NINFA_GLPI_ROOT="${NINFA_GLPI_ROOT:-$(dirname "$(dirname "$SOURCE")")}"
export NINFA_WORKSPACE_ROOT="$SANDBOX/workspace"
export TMPDIR="$SANDBOX"

printf '[NINFA] Original somente leitura: %s\n' "$SOURCE"
printf '[NINFA] Cópia de trabalho: %s\n' "$CLONE"
printf '[NINFA] Host GLPI: %s\n' "$NINFA_GLPI_ROOT"
printf '[NINFA] Operação: %s\n' "$MODE"

php "$NINFA_ROOT/bin/ninfa" "$MODE" "$CLONE"
