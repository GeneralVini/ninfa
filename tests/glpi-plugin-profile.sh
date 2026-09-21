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

NINFA_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FIXTURE_ROOT="$(mktemp -d /tmp/ninfa-glpi-profile.XXXXXX)"
PROJECT_ROOT="$FIXTURE_ROOT/glpi/plugins/example"
GLPI_ROOT="$FIXTURE_ROOT/glpi"
WORKSPACE_ROOT="$FIXTURE_ROOT/ninfa-workspace"

cleanup() {
    rm -rf "$FIXTURE_ROOT"
}
trap cleanup EXIT

mkdir -p "$PROJECT_ROOT/src" "$PROJECT_ROOT/front" "$GLPI_ROOT/src/autoload" "$GLPI_ROOT/src" "$GLPI_ROOT/inc" "$GLPI_ROOT/stubs"

printf "%s\n" "<?php define('GLPI_VERSION', '11.0.8');" > "$GLPI_ROOT/src/autoload/constants.php"
printf "%s\n" '<?php class DBmysql {}' > "$GLPI_ROOT/src/DBmysql.php"
printf "%s\n" '<?php' > "$GLPI_ROOT/inc/includes.php"
printf "%s\n" '<?php namespace GlpiPlugin\Example; final class Example {}' > "$PROJECT_ROOT/src/Example.php"
printf "%s\n" '<?php function plugin_init_example(): void {}' > "$PROJECT_ROOT/setup.php"
printf "%s\n" '<?php function plugin_example_install(): bool { return true; }' > "$PROJECT_ROOT/hook.php"
printf '%s\n' '{"name":"example/plugin","type":"glpi-plugin","require":{"php":">=8.2"},"require-dev":{"glpi-project/phpstan-glpi":"^1.3"}}' > "$PROJECT_ROOT/composer.json"

OUTPUT="$(NINFA_GLPI_ROOT="$GLPI_ROOT" NINFA_WORKSPACE_ROOT="$WORKSPACE_ROOT" php "$NINFA_ROOT/scripts/ninfa-configure.php" "$PROJECT_ROOT" --force)"
WORKSPACE="$(printf '%s\n' "$OUTPUT" | sed -n 's/^\[NINFA\] Workspace: //p')"

[[ -n "$WORKSPACE" ]]
[[ "$WORKSPACE" == "$WORKSPACE_ROOT"/* ]]

grep -q '^\[NINFA\] Profile: glpi-plugin$' <<< "$OUTPUT"
grep -q '^\[NINFA\] PHPStan level: 8$' <<< "$OUTPUT"
grep -q '^\[NINFA\] Psalm level: 8$' <<< "$OUTPUT"
grep -q 'level: 8' "$WORKSPACE/phpstan.neon"
grep -q "$GLPI_ROOT/src" "$WORKSPACE/phpstan.neon"
grep -q '<var name="DB" type="DBmysql" />' "$WORKSPACE/psalm.xml"
grep -q "$GLPI_ROOT/inc/includes.php" "$WORKSPACE/psalm.xml"

for forbidden in .ninfa phpstan.neon.dist psalm.xml ecs.php rector.php phpunit.xml.dist; do
    if [[ -e "$PROJECT_ROOT/$forbidden" ]]; then
        printf '[ERRO] Boilerplate criado no consumidor: %s\n' "$forbidden" >&2
        exit 1
    fi
done

printf '[OK] Profile GLPI Plugin 11 usa workspace externo sem boilerplate.\n'
