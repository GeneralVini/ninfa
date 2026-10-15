#!/usr/bin/env bash
# Exercita a cópia do harness GLPI sem executar scanners nem acessar SIGAPS real.
#
# O teste cria um plugin sintético chamado sigaps dentro de /tmp, injeta um
# executável php fictício no PATH e observa o destino entregue ao NINFA.
# Ele verifica o nome técnico preservado e a integridade do consumidor fake.
set -euo pipefail

# @var NINFA_ROOT absolute-path — raiz versionada do NINFA.
NINFA_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# @var FIXTURE_ROOT absolute-path — árvore integralmente sintética de teste.
FIXTURE_ROOT="$(mktemp -d /tmp/ninfa-glpi-field-fixture.XXXXXXXX)"
# @var SOURCE absolute-path — plugin sintético cujo nome deve sobreviver à cópia.
SOURCE="$FIXTURE_ROOT/glpi/plugins/sigaps"
# @var CAPTURE absolute-path — captura externa do caminho passado ao CLI simulado.
CAPTURE="$FIXTURE_ROOT/target.txt"

# @function cleanup — limpa apenas a árvore temporária criada por esta fixture.
cleanup() {
    # Só há cópia externa para remover se o harness finalizou sua preparação.
    if [[ -f "$FIXTURE_ROOT/harness-root.txt" ]]; then
        # O harness armazena a cópia fora da árvore da fixture: limpar só seu diretório conhecido.
        local harness_root
        harness_root="$(cat "$FIXTURE_ROOT/harness-root.txt")"
        # Não apagar nenhum path que não tenha o prefixo exclusivo do harness.
        if [[ -n "$harness_root" && "$harness_root" == /tmp/ninfa-glpi-field-test.* && -d "$harness_root" ]]; then
            rm -rf -- "$harness_root"
        fi
    fi
    rm -rf -- "$FIXTURE_ROOT"
}
trap cleanup EXIT

mkdir -p "$SOURCE/src" "$FIXTURE_ROOT/bin"
printf '%s\n' '<?php function plugin_init_sigaps() {}' > "$SOURCE/setup.php"
printf '%s\n' '<?php function plugin_sigaps_install() {}' > "$SOURCE/hook.php"
printf '%s\n' '{"name":"synthetic/sigaps","type":"glpi-plugin"}' > "$SOURCE/composer.json"

# O comando php fictício não processa PHP: só captura e valida o root da cópia.
cat > "$FIXTURE_ROOT/bin/php" <<'FAKE_PHP'
#!/usr/bin/env bash
set -euo pipefail
[[ "$1" == */bin/ninfa ]]
[[ "$2" == assist ]]
[[ "$(basename "$3")" == sigaps ]]
[[ -f "$3/setup.php" ]]
[[ "$3" != "$NINFA_GLPI_TEST_SOURCE" ]]
printf '%s\n' "$3" > "$NINFA_GLPI_TEST_CAPTURE"
FAKE_PHP
chmod +x "$FIXTURE_ROOT/bin/php"

# @var OUTPUT text — relatório do harness para conferir o resultado da inspeção.
OUTPUT="$(PATH="$FIXTURE_ROOT/bin:$PATH" \
    NINFA_GLPI_TEST_CAPTURE="$CAPTURE" NINFA_GLPI_TEST_SOURCE="$SOURCE" \
    bash "$NINFA_ROOT/scripts/glpi-field-test.sh" "$SOURCE" assist)"
# @var CLONE absolute-path — diretório repassado ao NINFA fictício.
CLONE="$(cat "$CAPTURE")"
# @var SANDBOX absolute-path — diretório temporário retornado pelo harness.
SANDBOX="$(printf '%s\n' "$OUTPUT" | sed -n 's/^\[NINFA\] Evidências e cópia: //p')"
printf '%s\n' "$SANDBOX" > "$FIXTURE_ROOT/harness-root.txt"

[[ "$CLONE" == "$SANDBOX/sigaps" ]]
[[ -f "$CLONE/hook.php" ]]
[[ -f "$SOURCE/setup.php" ]]
[[ -f "$SANDBOX/original-before.sha256" ]]
[[ -f "$SANDBOX/original-after.sha256" ]]
cmp -s "$SANDBOX/original-before.sha256" "$SANDBOX/original-after.sha256"
grep -qF 'Integridade do original preservada' <<< "$OUTPUT"

printf '[OK] Field-test GLPI preserva nome técnico e integridade com somente cópia sintética.\n'
