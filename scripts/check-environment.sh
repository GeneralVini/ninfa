#!/usr/bin/env bash
# Valida o ambiente do próprio Ninfa sem alterar o projeto consumidor.
#
# Verifica runtime, arquivos obrigatórios e permissões dos entrypoints locais.
# Em caso de falha, apresenta um comando de reparo específico quando houver
# correção operacional segura e conhecida.
set -euo pipefail

# @var ROOT absolute-path — raiz física do repositório Ninfa.
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

# @function ok — registra uma validação concluída com sucesso.
ok() {
    printf '[OK] %s\n' "$1"
}

# @function error — registra uma falha de validação em stderr.
error() {
    printf '[ERRO] %s\n' "$1" >&2
}

# @function repair — apresenta um comando operacional de correção sem executá-lo.
repair() {
    printf '\nPara corrigir:\n\n  %s\n' "$1" >&2
}

# @function show_noexec_hint — diagnostica montagem noexec quando findmnt estiver disponível.
show_noexec_hint() {
    # A ausência de findmnt não impede o diagnóstico principal.
    if ! command -v findmnt >/dev/null 2>&1; then
        return
    fi

    # @var MOUNT_OPTIONS mount-options — opções da montagem que contém a raiz do Ninfa.
    MOUNT_OPTIONS="$(findmnt -no OPTIONS --target "$ROOT" 2>/dev/null || true)"
    # noexec explica falhas persistentes mesmo após chmod e exige ação no filesystem.
    if [[ ",$MOUNT_OPTIONS," == *,noexec,* ]]; then
        printf '\n[DIAGNÓSTICO] O filesystem do Ninfa está montado com noexec.\n' >&2
        printf 'Confirme com:\n\n  findmnt -no OPTIONS --target "%s"\n' "$ROOT" >&2
        printf '\nMova o repositório para um filesystem executável ou ajuste a montagem conforme a política do host.\n' >&2
    fi
}

# @function require_command — exige comando funcional e mostra reparo conhecido quando indisponível.
require_command() {
    local name="$1"
    local label="$2"
    local repair_command="$3"

    # Ausência do comando é distinguida de falha de execução.
    if ! command -v "$name" >/dev/null 2>&1; then
        error "$label não encontrado."
        repair "$repair_command"
        exit 1
    fi

    # Um binário no PATH precisa conseguir iniciar para ser considerado saudável.
    if ! "$name" --version >/dev/null 2>&1; then
        error "$label foi encontrado, mas não pôde ser executado."
        show_noexec_hint
        repair "$repair_command"
        exit 1
    fi

    ok "$label encontrado e funcional"
}

# @function require_file — exige arquivo interno presente e legível.
require_file() {
    local path="$1"
    local label="$2"

    # Arquivo ausente indica checkout incompleto ou estrutura incompatível.
    if [[ ! -f "$path" ]]; then
        error "$label não encontrado: $path"
        exit 1
    fi

    # Permissão de leitura inadequada recebe reparo localizado.
    if [[ ! -r "$path" ]]; then
        error "$label existe, mas não está legível: $path"
        repair "chmod u+r '$path'"
        exit 1
    fi

    ok "$label encontrado e legível"
}

# @function require_executable — exige entrypoint local presente e com bit de execução.
require_executable() {
    local path="$1"
    local label="$2"

    # Ausência do entrypoint é diferente de erro de permissão.
    if [[ ! -e "$path" ]]; then
        error "$label não encontrado: $path"
        exit 1
    fi

    # O bit +x é requisito para entrypoints públicos ou scripts diretamente executáveis.
    if [[ ! -x "$path" ]]; then
        error "$label está sem permissão de execução: $path"
        show_noexec_hint
        repair "chmod +x '$path'"
        exit 1
    fi

    ok "$label executável"
}

printf '==================================================\n'
printf ' Ninfa - Diagnóstico do ambiente local\n'
printf '==================================================\n\n'

require_command php PHP 'sudo apt install php-cli'
# @var PHP_OK boolean-string — "1" quando o runtime atende ao mínimo PHP 8.2.
PHP_OK="$(php -r 'echo PHP_VERSION_ID >= 80200 ? "1" : "0";')"
# O próprio código do Ninfa utiliza recursos compatíveis com PHP 8.2+.
if [[ "$PHP_OK" != "1" ]]; then
    error 'O Ninfa requer PHP 8.2+ para executar sua base atual.'
    php --version >&2 || true
    exit 1
fi
ok 'Versão do PHP compatível (8.2+)'

require_command git Git 'sudo apt install git'
require_command python3 Python 'sudo apt install python3 python3-venv'

require_executable bin/ninfa 'CLI bin/ninfa'
require_executable scripts/install-security-tools.sh 'Instalador de ferramentas de segurança'

require_file Makefile 'Makefile'
require_file README.md 'README'
require_file ecs.php 'Configuração ECS'
require_file rector.php 'Configuração Rector'
require_file phpstan.neon.dist 'Configuração PHPStan'
require_file psalm.xml 'Configuração Psalm'
require_file phpunit.xml.dist 'Configuração PHPUnit'

printf '\n[OK] Ambiente básico do Ninfa validado.\n'
