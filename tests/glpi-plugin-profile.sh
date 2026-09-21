#!/usr/bin/env bash
# Fixture de integração do profile GLPI Plugin 11.
#
# O teste monta um host GLPI mínimo e um plugin fora de qualquer instalação
# real, executa `ninfa-configure.php` com workspace temporário e verifica:
# - detecção do profile glpi-plugin e níveis PHPStan/Psalm 8;
# - uso dos arquivos/stubs do host nas configurações geradas;
# - declaração do global $DB no Psalm;
# - ausência de boilerplate/configuração do Ninfa dentro do plugin consumidor.
#
# Todos os artefatos são criados em um diretório temporário removido no EXIT.
set -euo pipefail

# @var NINFA_ROOT absolute-path — raiz física do repositório Ninfa usado pela fixture.
NINFA_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# @var FIXTURE_ROOT absolute-path — sandbox temporário que contém host, plugin e workspace.
FIXTURE_ROOT="$(mktemp -d /tmp/ninfa-glpi-profile.XXXXXX)"
# @var PROJECT_ROOT absolute-path — raiz do plugin consumidor sintético.
PROJECT_ROOT="$FIXTURE_ROOT/glpi/plugins/example"
# @var GLPI_ROOT absolute-path — raiz do host GLPI sintético.
GLPI_ROOT="$FIXTURE_ROOT/glpi"
# @var WORKSPACE_ROOT absolute-path — base externa onde o Ninfa deve gerar artefatos.
WORKSPACE_ROOT="$FIXTURE_ROOT/ninfa-workspace"

# @function cleanup — remove integralmente a sandbox temporária criada pela fixture.
cleanup() {
    rm -rf "$FIXTURE_ROOT"
}

# A limpeza é registrada antes de criar qualquer fixture para que falhas posteriores
# não deixem host/plugin temporários acumulados em /tmp.
trap cleanup EXIT

# Materializa somente os diretórios necessários para exercitar detecção e geração
# de configuração; a fixture evita depender de uma instalação GLPI real.
mkdir -p "$PROJECT_ROOT/src" "$PROJECT_ROOT/front" "$GLPI_ROOT/src/autoload" "$GLPI_ROOT/src" "$GLPI_ROOT/inc" "$GLPI_ROOT/stubs"

# Grava o conjunto mínimo de arquivos que o detector e os geradores consultam.
printf "%s\n" "<?php define('GLPI_VERSION', '11.0.8');" > "$GLPI_ROOT/src/autoload/constants.php"
printf "%s\n" '<?php class DBmysql {}' > "$GLPI_ROOT/src/DBmysql.php"
printf "%s\n" '<?php' > "$GLPI_ROOT/inc/includes.php"
printf "%s\n" '<?php namespace GlpiPlugin\Example; final class Example {}' > "$PROJECT_ROOT/src/Example.php"
printf "%s\n" '<?php function plugin_init_example(): void {}' > "$PROJECT_ROOT/setup.php"
printf "%s\n" '<?php function plugin_example_install(): bool { return true; }' > "$PROJECT_ROOT/hook.php"
printf '%s\n' '{"name":"example/plugin","type":"glpi-plugin","require":{"php":">=8.2"},"require-dev":{"glpi-project/phpstan-glpi":"^1.3"}}' > "$PROJECT_ROOT/composer.json"

# @var OUTPUT string — stdout do configurador, usado para validar contexto e localizar workspace.
OUTPUT="$(NINFA_GLPI_ROOT="$GLPI_ROOT" NINFA_WORKSPACE_ROOT="$WORKSPACE_ROOT" php "$NINFA_ROOT/scripts/ninfa-configure.php" "$PROJECT_ROOT" --force)"
# @var WORKSPACE absolute-path — workspace efetivamente reportado pelo configurador.
WORKSPACE="$(printf '%s\n' "$OUTPUT" | sed -n 's/^\[NINFA\] Workspace: //p')"

# O workspace precisa existir dentro da base temporária, provando que nenhum
# artefato foi direcionado para dentro do plugin consumidor.
[[ -n "$WORKSPACE" ]]
[[ "$WORKSPACE" == "$WORKSPACE_ROOT"/* ]]

# Valida o contrato específico do profile GLPI 11 nas saídas e configurações.
grep -q '^\[NINFA\] Profile: glpi-plugin$' <<< "$OUTPUT"
grep -q '^\[NINFA\] PHPStan level: 8$' <<< "$OUTPUT"
grep -q '^\[NINFA\] Psalm level: 8$' <<< "$OUTPUT"
grep -q 'level: 8' "$WORKSPACE/phpstan.neon"
grep -q "$GLPI_ROOT/src" "$WORKSPACE/phpstan.neon"
grep -q '<var name="DB" type="DBmysql" />' "$WORKSPACE/psalm.xml"
grep -q "$GLPI_ROOT/inc/includes.php" "$WORKSPACE/psalm.xml"

# Nenhum boilerplate/configuração gerada pelo Ninfa pode escapar do workspace
# externo e contaminar a raiz do consumidor sintético.
for forbidden in .ninfa phpstan.neon.dist psalm.xml ecs.php rector.php phpunit.xml.dist; do
    # Uma ocorrência aqui é regressão arquitetural: o consumidor deve permanecer imutável.
    if [[ -e "$PROJECT_ROOT/$forbidden" ]]; then
        printf '[ERRO] Boilerplate criado no consumidor: %s\n' "$forbidden" >&2
        exit 1
    fi
done

printf '[OK] Profile GLPI Plugin 11 usa workspace externo sem boilerplate.\n'
