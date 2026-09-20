#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TOOLS="$ROOT/.tools"
SEMGREP_VERSION="${NINFA_SEMGREP_VERSION:-1.177.0}"

mkdir -p "$TOOLS"

command -v python3 >/dev/null || { echo '[ERRO] Python 3.10+ é necessário para Semgrep.' >&2; exit 1; }
PYTHON_OK="$(python3 -c 'import sys; print(1 if sys.version_info >= (3, 10) else 0)')"
[[ "$PYTHON_OK" == "1" ]] || { echo '[ERRO] Python 3.10+ é necessário para Semgrep.' >&2; exit 1; }

if [[ ! -x "$TOOLS/semgrep/bin/semgrep" ]]; then
    python3 -m venv "$TOOLS/semgrep" || { echo '[ERRO] Instale python3-venv e repita make security-tools.' >&2; exit 1; }
    "$TOOLS/semgrep/bin/python" -m pip install --disable-pip-version-check "semgrep==${SEMGREP_VERSION}"
fi
printf '[NINFA] Semgrep: '
"$TOOLS/semgrep/bin/semgrep" --version

case "${NINFA_INSTALL_ZAP:-0}" in
    1|true|TRUE|yes|YES|on|ON)
        printf '[NINFA] DAST/OWASP ZAP está desabilitado no fluxo oficial do Ninfa; NINFA_INSTALL_ZAP é ignorado.\n' >&2
        ;;
esac
