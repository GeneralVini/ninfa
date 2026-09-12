#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

SEMGREP_BIN="${NINFA_SEMGREP_BIN:-$ROOT/.tools/semgrep/bin/semgrep}"

if [[ ! -x "$SEMGREP_BIN" ]]; then
    if command -v semgrep >/dev/null 2>&1; then
        SEMGREP_BIN="$(command -v semgrep)"
    else
        printf '[ERRO] Semgrep não encontrado. Execute: make setup\n' >&2
        exit 1
    fi
fi

TARGETS=()
if [[ -n "${NINFA_SEMGREP_TARGET:-}" ]]; then
    TARGETS+=("$NINFA_SEMGREP_TARGET")
elif [[ -s "$ROOT/.ninfa/paths.txt" ]]; then
    while IFS= read -r path; do
        [[ -n "$path" ]] && TARGETS+=("$path")
    done < "$ROOT/.ninfa/paths.txt"
else
    TARGETS+=(.)
fi

exec "$SEMGREP_BIN" \
    --config "$ROOT/security/semgrep.yml" \
    --error \
    --metrics=off \
    --exclude vendor \
    --exclude .tools \
    --exclude runtime \
    --exclude public/assets \
    "${TARGETS[@]}"
