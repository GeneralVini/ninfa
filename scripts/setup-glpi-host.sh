#!/usr/bin/env bash
# Prepara um host GLPI 11 para testes/uso do profile glpi-plugin.
#
# Entradas de ambiente:
# - NINFA_GLPI_ROOT: destino do host; default <RUNNER_TEMP>/ninfa-glpi ou /tmp/ninfa-glpi.
# - NINFA_GLPI_VERSION: tag/branch GLPI; default 11.0.8 e obrigatoriamente 11.x.
# - RUNNER_TEMP: base usada apenas quando NINFA_GLPI_ROOT não foi informado.
#
# Efeitos externos:
# - clona glpi-project/glpi no destino quando ainda não há host reconhecível;
# - executa composer install --no-dev dentro do host clonado;
# - não modifica o plugin consumidor.
#
# Se o destino já contiver src/autoload/constants.php, considera o host pronto.
# Se o caminho existir sem esse marcador, ou a versão solicitada não for 11.x,
# encerra com erro para não reutilizar um host ambíguo.
set -euo pipefail

# A precedência do destino permite CI isolado sem exigir configuração explícita,
# mas mantém NINFA_GLPI_ROOT como override principal para execução local.
GLPI_ROOT="${NINFA_GLPI_ROOT:-${RUNNER_TEMP:-/tmp}/ninfa-glpi}"
GLPI_VERSION="${NINFA_GLPI_VERSION:-11.0.8}"

# O profile ativo é específico de GLPI 11; aceitar outra major faria os stubs e
# integrações estáticas representarem um host diferente do contrato suportado.
if [[ "$GLPI_VERSION" != 11.* ]]; then
    printf '[ERRO] O profile glpi-plugin suporta somente GLPI 11. Solicitado: %s\n' "$GLPI_VERSION" >&2
    exit 1
fi

# Um host com o marcador oficial de constantes é reutilizado para evitar clone e
# instalação repetidos em execuções subsequentes.
if [[ -f "$GLPI_ROOT/src/autoload/constants.php" ]]; then
    printf '[OK] Host GLPI já disponível em %s.\n' "$GLPI_ROOT"
    exit 0
fi

# Um caminho pré-existente sem o marcador não é sobrescrito. Essa barreira evita
# apagar ou misturar conteúdo que não foi criado/validado como host GLPI.
if [[ -e "$GLPI_ROOT" ]]; then
    printf '[ERRO] NINFA_GLPI_ROOT já existe, mas não contém um host GLPI reconhecível: %s\n' "$GLPI_ROOT" >&2
    exit 1
fi

# O clone é superficial e preso à versão solicitada; dependências de desenvolvimento
# do host não são necessárias para fornecer o contexto runtime usado pelo profile.
git clone --depth 1 --branch "$GLPI_VERSION" https://github.com/glpi-project/glpi.git "$GLPI_ROOT"
composer install --working-dir="$GLPI_ROOT" --no-dev --no-interaction --prefer-dist --no-progress

printf '[OK] GLPI %s preparado em %s.\n' "$GLPI_VERSION" "$GLPI_ROOT"
