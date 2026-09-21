#!/usr/bin/env bash
# Prepara a ferramenta SAST gerenciada pelo próprio Ninfa.
#
# Entradas de ambiente:
# - NINFA_SEMGREP_VERSION: versão do Semgrep instalada no venv; default 1.177.0.
# - NINFA_INSTALL_ZAP: legado; quando verdadeiro apenas emite aviso e é ignorado.
#
# Efeitos externos:
# - cria/usa <repo>/.tools/semgrep como virtualenv Python;
# - instala Semgrep via pip quando o binário ainda não existe;
# - não altera o projeto consumidor e não instala OWASP ZAP.
#
# Falha com exit code não zero se Python 3.10+ ou python3-venv não estiverem
# disponíveis, ou se qualquer comando de preparação falhar.
set -euo pipefail

# Resolve caminhos somente dentro do repositório Ninfa; ferramentas gerenciadas
# não são instaladas no projeto consumidor.
# @var ROOT absolute-path — raiz física do repositório Ninfa.
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# @var TOOLS absolute-path — diretório de ferramentas privadas gerenciadas pelo Ninfa.
TOOLS="$ROOT/.tools"
# @var SEMGREP_VERSION version-string — versão exata do Semgrep a instalar/reutilizar.
SEMGREP_VERSION="${NINFA_SEMGREP_VERSION:-1.177.0}"

# A base de ferramentas é criada antes de validar/instalar o virtualenv e nunca
# aponta para o projeto consumidor.
mkdir -p "$TOOLS"

# Python 3.10+ é pré-condição do Semgrep usado pelo Ninfa. A validação ocorre
# antes de criar o virtualenv para não deixar uma instalação parcialmente criada.
command -v python3 >/dev/null || { echo '[ERRO] Python 3.10+ é necessário para Semgrep.' >&2; exit 1; }
# @var PYTHON_OK boolean-string — "1" quando o interpretador atende a versão mínima.
PYTHON_OK="$(python3 -c 'import sys; print(1 if sys.version_info >= (3, 10) else 0)')"
[[ "$PYTHON_OK" == "1" ]] || { echo '[ERRO] Python 3.10+ é necessário para Semgrep.' >&2; exit 1; }

# O virtualenv é idempotente: uma instalação existente é reutilizada para evitar
# reinstalação em toda execução de `make security-tools`.
if [[ ! -x "$TOOLS/semgrep/bin/semgrep" ]]; then
    python3 -m venv "$TOOLS/semgrep" || { echo '[ERRO] Instale python3-venv e repita make security-tools.' >&2; exit 1; }
    "$TOOLS/semgrep/bin/python" -m pip install --disable-pip-version-check "semgrep==${SEMGREP_VERSION}"
fi

# Exibe a versão efetivamente resolvida para tornar a preparação auditável.
printf '[NINFA] Semgrep: '
"$TOOLS/semgrep/bin/semgrep" --version

# A variável de instalação do ZAP é preservada apenas como compatibilidade
# explícita. DAST continua fora do fluxo oficial e não deve ser reinstalado aqui.
case "${NINFA_INSTALL_ZAP:-0}" in
    1|true|TRUE|yes|YES|on|ON)
        printf '[NINFA] DAST/OWASP ZAP está desabilitado no fluxo oficial do Ninfa; NINFA_INSTALL_ZAP é ignorado.\n' >&2
        ;;
esac
