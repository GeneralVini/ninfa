#!/usr/bin/env bash
# Prepara e valida a ferramenta SAST gerenciada pelo próprio Ninfa.
#
# Entradas de ambiente:
# - NINFA_SEMGREP_VERSION: versão do Semgrep instalada no venv; default 1.177.0.
# - NINFA_INSTALL_ZAP: legado; quando verdadeiro apenas emite aviso e é ignorado.
#
# Efeitos externos:
# - cria/usa <repo>/.tools/semgrep como virtualenv Python;
# - instala Semgrep via pip quando a instalação ainda não existe;
# - valida existência, permissão, execução e versão efetiva;
# - não altera o projeto consumidor e não instala OWASP ZAP.
set -euo pipefail

# @var ROOT absolute-path — raiz física do repositório Ninfa.
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# @var TOOLS absolute-path — diretório de ferramentas privadas gerenciadas pelo Ninfa.
TOOLS="$ROOT/.tools"
# @var SEMGREP_VERSION version-string — versão exata do Semgrep esperada pelo Ninfa.
SEMGREP_VERSION="${NINFA_SEMGREP_VERSION:-1.177.0}"
# @var SEMGREP_DIR absolute-path — raiz do virtualenv privado do Semgrep.
SEMGREP_DIR="$TOOLS/semgrep"
# @var SEMGREP_PYTHON executable-path — interpretador Python do virtualenv gerenciado.
SEMGREP_PYTHON="$SEMGREP_DIR/bin/python"
# @var SEMGREP_BIN executable-path — launcher Semgrep gerenciado pelo Ninfa.
SEMGREP_BIN="$SEMGREP_DIR/bin/semgrep"

# @function error — registra falha de preparação em stderr.
error() {
    printf '[ERRO] %s\n' "$1" >&2
}

# @function repair — apresenta comando de correção sem executá-lo automaticamente.
repair() {
    printf '\nPara corrigir:\n\n  %s\n' "$1" >&2
}

# @function show_noexec_hint — diagnostica filesystem noexec quando findmnt estiver disponível.
show_noexec_hint() {
    # A ausência de findmnt não impede o diagnóstico principal.
    if ! command -v findmnt >/dev/null 2>&1; then
        return
    fi

    # @var MOUNT_OPTIONS mount-options — opções da montagem que contém o Ninfa.
    MOUNT_OPTIONS="$(findmnt -no OPTIONS --target "$ROOT" 2>/dev/null || true)"
    # noexec invalida chmod como reparo suficiente e precisa ser informado explicitamente.
    if [[ ",$MOUNT_OPTIONS," == *,noexec,* ]]; then
        printf '\n[DIAGNÓSTICO] O filesystem do Ninfa está montado com noexec.\n' >&2
        printf 'Confirme com:\n\n  findmnt -no OPTIONS --target "%s"\n' "$ROOT" >&2
        printf '\nMova o Ninfa para um filesystem executável ou ajuste a montagem conforme a política do host.\n' >&2
    fi
}

mkdir -p "$TOOLS"

# Python precisa existir antes de qualquer tentativa de criar ou reparar o virtualenv.
if ! command -v python3 >/dev/null 2>&1; then
    error 'Python 3.10+ é necessário para Semgrep.'
    repair 'sudo apt install python3 python3-venv'
    exit 1
fi

# @var PYTHON_OK boolean-string — "1" quando o interpretador atende ao mínimo 3.10.
PYTHON_OK="$(python3 -c 'import sys; print(1 if sys.version_info >= (3, 10) else 0)')"
# Versão insuficiente não deve produzir um virtualenv parcialmente utilizável.
if [[ "$PYTHON_OK" != "1" ]]; then
    error 'Python 3.10+ é necessário para Semgrep.'
    python3 --version >&2 || true
    exit 1
fi

# O módulo venv precisa estar funcional antes de qualquer criação do ambiente privado.
if ! python3 -m venv --help >/dev/null 2>&1; then
    error 'O módulo venv do Python não está disponível.'
    repair 'sudo apt install python3-venv'
    exit 1
fi

# Ausência do Python do venv distingue primeira instalação de ambiente parcial/corrompido.
if [[ ! -e "$SEMGREP_PYTHON" ]]; then
    # Diretório existente sem interpretador indica instalação incompleta e deve ser recriado explicitamente.
    if [[ -d "$SEMGREP_DIR" ]]; then
        error 'O ambiente virtual do Semgrep está incompleto.'
        repair "rm -rf '$SEMGREP_DIR' && make security-tools"
        exit 1
    fi

    printf '[NINFA] Criando ambiente virtual do Semgrep...\n'
    python3 -m venv "$SEMGREP_DIR"
fi

# O interpretador privado precisa ser executável antes de instalar ou validar pacotes.
if [[ ! -x "$SEMGREP_PYTHON" ]]; then
    error "Python do ambiente Semgrep está sem permissão de execução: $SEMGREP_PYTHON"
    show_noexec_hint
    repair "chmod +x '$SEMGREP_PYTHON'"
    exit 1
fi

# Launcher ausente caracteriza instalação ainda não concluída e autoriza pip install no venv do Ninfa.
if [[ ! -e "$SEMGREP_BIN" ]]; then
    printf '[NINFA] Instalando Semgrep %s...\n' "$SEMGREP_VERSION"
    "$SEMGREP_PYTHON" -m pip install --disable-pip-version-check "semgrep==${SEMGREP_VERSION}"
fi

# Launcher presente sem +x recebe reparo localizado, sem chmod recursivo indiscriminado.
if [[ ! -x "$SEMGREP_BIN" ]]; then
    error "Semgrep está instalado, mas sem permissão de execução: $SEMGREP_BIN"
    show_noexec_hint
    repair "chmod +x '$SEMGREP_BIN'"
    exit 1
fi

# @var SEMGREP_VERSION_OUTPUT text — saída capturada de `semgrep --version` para health-check e auditoria.
SEMGREP_VERSION_OUTPUT=""
# Executabilidade do wrapper não basta: o probe detecta binário interno sem +x ou instalação inconsistente.
if ! SEMGREP_VERSION_OUTPUT="$($SEMGREP_BIN --version 2>&1)"; then
    error 'Semgrep foi encontrado, mas não consegue iniciar corretamente.'
    printf '\nSaída do teste:\n%s\n' "$SEMGREP_VERSION_OUTPUT" >&2
    show_noexec_hint
    printf '\nSe o erro for de permissão em binário interno, execute:\n\n' >&2
    printf "  find '%s' -type f \\( -name 'semgrep-core' -o -name 'osemgrep' \\) -exec chmod +x {} +\n" "$SEMGREP_DIR" >&2
    printf '\nValide em seguida:\n\n  %s --version\n' "$SEMGREP_BIN" >&2
    printf '\nSe a instalação estiver inconsistente, recrie-a:\n\n  rm -rf %q\n  make security-tools\n' "$SEMGREP_DIR" >&2
    exit 1
fi

# Versão diferente da homologada exige recriação para manter execução determinística.
if [[ "$SEMGREP_VERSION_OUTPUT" != *"$SEMGREP_VERSION"* ]]; then
    error "Versão inesperada do Semgrep. Esperada: $SEMGREP_VERSION. Encontrada: $SEMGREP_VERSION_OUTPUT"
    repair "rm -rf '$SEMGREP_DIR' && make security-tools"
    exit 1
fi

printf '[OK] Semgrep %s instalado, executável e funcional\n' "$SEMGREP_VERSION"

# Compatibilidade antiga não pode reativar DAST no pipeline público do Ninfa.
case "${NINFA_INSTALL_ZAP:-0}" in
    1|true|TRUE|yes|YES|on|ON)
        printf '[NINFA] DAST/OWASP ZAP está desabilitado no fluxo oficial do Ninfa; NINFA_INSTALL_ZAP é ignorado.\n' >&2
        ;;
esac
