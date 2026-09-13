#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TOOLS="$ROOT/.tools"
SEMGREP_VERSION="${NINFA_SEMGREP_VERSION:-1.177.0}"
INSTALL_ZAP="${NINFA_INSTALL_ZAP:-0}"
ZAP_VERSION="${NINFA_ZAP_VERSION:-2.17.0}"
ZAP_SHA256="${NINFA_ZAP_SHA256:-efe799aaa3627db683b43f00c9c210aea0b75c00cc8f0a0f0434d12bb3ddde5a}"
ZAP_ARCHIVE="ZAP_${ZAP_VERSION}_Linux.tar.gz"
ZAP_URL="https://github.com/zaproxy/zaproxy/releases/download/v${ZAP_VERSION}/${ZAP_ARCHIVE}"

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

if [[ "$INSTALL_ZAP" != "1" ]]; then
    printf '[NINFA] OWASP ZAP não instalado por padrão. Use: make security-tools-with-zap\n'
    exit 0
fi

command -v java >/dev/null || { echo '[ERRO] Java 17+ é necessário para OWASP ZAP.' >&2; exit 1; }
JAVA_VERSION="$(java -version 2>&1 | head -n 1 | sed -E 's/.*version "([0-9]+).*/\1/')"
[[ "$JAVA_VERSION" =~ ^[0-9]+$ ]] && (( JAVA_VERSION >= 17 )) || { echo '[ERRO] Java 17+ é necessário para OWASP ZAP.' >&2; exit 1; }

if [[ ! -x "$TOOLS/zap/zap.sh" ]]; then
    command -v curl >/dev/null || { echo '[ERRO] curl é necessário para instalar OWASP ZAP.' >&2; exit 1; }
    command -v sha256sum >/dev/null || { echo '[ERRO] sha256sum é necessário para validar OWASP ZAP.' >&2; exit 1; }
    tmp="$(mktemp -d)"
    trap 'rm -rf "$tmp"' EXIT
    curl -fL "$ZAP_URL" -o "$tmp/$ZAP_ARCHIVE"
    printf '%s  %s\n' "$ZAP_SHA256" "$tmp/$ZAP_ARCHIVE" | sha256sum -c -
    tar -xzf "$tmp/$ZAP_ARCHIVE" -C "$tmp"
    rm -rf "$TOOLS/zap"
    mv "$tmp/ZAP_${ZAP_VERSION}" "$TOOLS/zap"
fi
printf '[NINFA] OWASP ZAP: '
"$TOOLS/zap/zap.sh" -version
