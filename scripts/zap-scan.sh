#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

TARGET="${NINFA_ZAP_TARGET:-}"
ZAP_BIN="${NINFA_ZAP_BIN:-$ROOT/.tools/zap/zap.sh}"
REPORT="${NINFA_ZAP_REPORT:-$ROOT/runtime/security/zap-report.html}"

if [[ -z "$TARGET" ]]; then
    printf '[ERRO] Defina NINFA_ZAP_TARGET para uma URL local de desenvolvimento.\n' >&2
    printf 'Exemplo: NINFA_ZAP_TARGET=http://127.0.0.1:8080 composer security:dast\n' >&2
    exit 1
fi

if [[ ! "$TARGET" =~ ^https?://(127\.0\.0\.1|localhost)(:[0-9]+)?(/|$) ]]; then
    printf '[ERRO] O DAST padrão do Ninfa aceita somente localhost/127.0.0.1.\n' >&2
    exit 1
fi

if [[ ! -x "$ZAP_BIN" ]]; then
    if command -v zaproxy >/dev/null 2>&1; then
        ZAP_BIN="$(command -v zaproxy)"
    else
        printf '[ERRO] OWASP ZAP não encontrado. Execute: make setup\n' >&2
        exit 1
    fi
fi

mkdir -p "$(dirname "$REPORT")"
printf '[NINFA] OWASP ZAP active scan local: %s\n' "$TARGET"
printf '[NINFA] Relatório: %s\n' "$REPORT"
exec "$ZAP_BIN" -cmd -quickurl "$TARGET" -quickout "$REPORT" -quickprogress
