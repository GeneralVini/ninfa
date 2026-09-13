#!/usr/bin/env bash
set -euo pipefail

NINFA_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FIXTURE_ROOT="$(mktemp -d /tmp/ninfa-glpi-profile.XXXXXX)"
PROJECT_ROOT="$FIXTURE_ROOT/glpi/plugins/example"
GLPI_ROOT="$FIXTURE_ROOT/glpi"

cleanup() {
    rm -rf "$FIXTURE_ROOT"
}
trap cleanup EXIT

mkdir -p "$PROJECT_ROOT/src" "$PROJECT_ROOT/front" "$GLPI_ROOT/src/autoload" "$GLPI_ROOT/src"

printf "%s\n" "<?php define('GLPI_VERSION', '11.0.8');" > "$GLPI_ROOT/src/autoload/constants.php"
printf "%s\n" '<?php class DBmysql {}' > "$GLPI_ROOT/src/DBmysql.php"
printf "%s\n" '<?php namespace GlpiPlugin\Example; final class Example {}' > "$PROJECT_ROOT/src/Example.php"
printf "%s\n" '<?php function plugin_init_example(): void {}' > "$PROJECT_ROOT/setup.php"
printf "%s\n" '<?php function plugin_example_install(): bool { return true; }' > "$PROJECT_ROOT/hook.php"
printf '%s\n' '{"name":"example/plugin","type":"glpi-plugin","require":{"php":">=8.2"},"require-dev":{"glpi-project/phpstan-glpi":"^1.3"}}' > "$PROJECT_ROOT/composer.json"

NINFA_GLPI_ROOT="$GLPI_ROOT" php "$NINFA_ROOT/scripts/ninfa-configure.php" "$PROJECT_ROOT" --force >/dev/null

grep -q '"profile": "glpi-plugin"' "$PROJECT_ROOT/.ninfa/context.json"
grep -q '"php_version": "8.2"' "$PROJECT_ROOT/.ninfa/context.json"
grep -q 'errorLevel="8"' "$PROJECT_ROOT/psalm.xml"
grep -q 'phpVersion="8.2"' "$PROJECT_ROOT/psalm.xml"
grep -q 'autoloader=".ninfa/phpstan-glpi-bootstrap.php"' "$PROJECT_ROOT/psalm.xml"
grep -q '<var name="DB" type="DBmysql" />' "$PROJECT_ROOT/psalm.xml"
grep -q "$GLPI_ROOT/inc/includes.php" "$PROJECT_ROOT/psalm.xml"
grep -q "$GLPI_ROOT/src" "$PROJECT_ROOT/phpstan.neon.dist"

printf '[OK] Profile GLPI Plugin 11 configurado corretamente.\n'
