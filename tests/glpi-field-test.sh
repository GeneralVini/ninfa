#!/usr/bin/env bash
# Exercita o harness GLPI com dois plugins sintéticos, sem acessar projetos reais.
#
# Reproduz os nomes técnicos SIGAPS e EGIDE somente na árvore temporária.
# O executável php simulado captura a cópia passada ao NINFA sem executar PHP.
set -euo pipefail

# @var NINFA_ROOT absolute-path — raiz do projeto NINFA versionado.
NINFA_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# @var FIXTURE_ROOT absolute-path — árvore sintética isolada dos consumidores reais.
FIXTURE_ROOT="$(mktemp -d /tmp/ninfa-glpi-field-fixture.XXXXXXXX)"
# @var HARNESS_ROOTS absolute-path — lista das cópias descartáveis geradas no teste.
HARNESS_ROOTS="$FIXTURE_ROOT/harness-roots.txt"

# @function cleanup — remove exclusivamente fixture e sandboxes desta execução.
cleanup() {
    # A lista só contém paths emitidos pelo próprio harness nesta fixture.
    if [[ -f "$HARNESS_ROOTS" ]]; then
        while IFS= read -r harness_root; do
            # O prefixo é obrigatório para nunca remover uma árvore arbitrária.
            if [[ -n "$harness_root" && "$harness_root" == /tmp/ninfa-glpi-field-test.* && -d "$harness_root" ]]; then
                rm -rf -- "$harness_root"
            fi
        done < "$HARNESS_ROOTS"
    fi
    rm -rf -- "$FIXTURE_ROOT"
}
trap cleanup EXIT

mkdir -p "$FIXTURE_ROOT/bin"

# O comando php fictício valida os argumentos, captura a cópia e não executa scanners.
cat > "$FIXTURE_ROOT/bin/php" <<'FAKE_PHP'
#!/usr/bin/env bash
set -euo pipefail
[[ "$1" == */bin/ninfa ]]
[[ "$2" == assist ]]
[[ "$(basename "$3")" == "$NINFA_GLPI_TEST_NAME" ]]
[[ -f "$3/setup.php" ]]
[[ "$3" != "$NINFA_GLPI_TEST_SOURCE" ]]
printf '%s\n' "$3" > "$NINFA_GLPI_TEST_CAPTURE"
FAKE_PHP
chmod +x "$FIXTURE_ROOT/bin/php"

# Dois nomes técnicos distintos precisam sobreviver à cópia de campo.
for NAME in sigaps egide; do
    # @var SOURCE absolute-path — plugin sintético usado apenas nesta iteração.
    SOURCE="$FIXTURE_ROOT/glpi/plugins/$NAME"
    # @var CAPTURE absolute-path — captura do destino do CLI fictício.
    CAPTURE="$FIXTURE_ROOT/target-$NAME.txt"
    mkdir -p "$SOURCE/src"
    printf '<?php function plugin_init_%s() {}\n' "$NAME" > "$SOURCE/setup.php"
    printf '<?php function plugin_%s_install() {}\n' "$NAME" > "$SOURCE/hook.php"
    printf '{"name":"synthetic/%s","type":"glpi-plugin"}\n' "$NAME" > "$SOURCE/composer.json"

    # @var NINFA_GLPI_TEST_CAPTURE absolute-path — destino passado ao mock.
    # @var NINFA_GLPI_TEST_SOURCE absolute-path — original sintético protegido.
    # @var NINFA_GLPI_TEST_NAME string — nome esperado na cópia isolada.
    # @var OUTPUT text — relatório do harness, sem comandos sobre plugins reais.
    OUTPUT="$(PATH="$FIXTURE_ROOT/bin:$PATH" \
        NINFA_GLPI_TEST_CAPTURE="$CAPTURE" NINFA_GLPI_TEST_SOURCE="$SOURCE" \
        NINFA_GLPI_TEST_NAME="$NAME" \
        bash "$NINFA_ROOT/scripts/glpi-field-test.sh" "$SOURCE" assist)"

    # @var CLONE absolute-path — caminho entregue ao NINFA fictício.
    CLONE="$(cat "$CAPTURE")"
    # @var SANDBOX absolute-path — workspace descartável dessa execução.
    SANDBOX="$(printf '%s\n' "$OUTPUT" | sed -n 's/^\[NINFA\] Evidências e cópia: //p')"
    printf '%s\n' "$SANDBOX" >> "$HARNESS_ROOTS"

    [[ "$CLONE" == "$SANDBOX/$NAME" ]]
    [[ -f "$CLONE/hook.php" ]]
    [[ -f "$SOURCE/setup.php" ]]
    [[ -f "$SANDBOX/original-before.sha256" ]]
    [[ -f "$SANDBOX/original-after.sha256" ]]
    cmp -s "$SANDBOX/original-before.sha256" "$SANDBOX/original-after.sha256"
    grep -qF 'Integridade do original preservada' <<< "$OUTPUT"
done

printf '[OK] Field-test GLPI sintético SIGAPS/EGIDE preserva nomes e integridade.\n'
