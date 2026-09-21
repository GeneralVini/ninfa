#!/usr/bin/env bash
# Wrapper legado para execução manual de OWASP ZAP fora do pipeline público.
#
# Este script NÃO é chamado por `ninfa security` e permanece congelado como
# referência/uso manual controlado.
#
# Entradas:
# - NINFA_ZAP_TARGET: URL alvo; aceita somente localhost ou 127.0.0.1.
# - NINFA_ZAP_BIN: binário/script ZAP; default <repo>/.tools/zap/zap.sh.
# - NINFA_ZAP_REPORT ou primeiro argumento: caminho do relatório de saída.
#
# Efeitos externos:
# - cria o diretório pai do relatório;
# - executa ZAP em modo `-cmd -quickurl ... -quickout ... -quickprogress`.
#
# Falha quando alvo/relatório não são informados, quando o alvo não é local ou
# quando nenhum executável ZAP pode ser resolvido.
set -euo pipefail

# O script opera a partir da raiz do Ninfa para manter caminhos relativos de
# ferramenta estáveis, independentemente do diretório de onde foi chamado.
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

TARGET="${NINFA_ZAP_TARGET:-}"
ZAP_BIN="${NINFA_ZAP_BIN:-$ROOT/.tools/zap/zap.sh}"
REPORT="${1:-${NINFA_ZAP_REPORT:-}}"

# Alvo explícito é obrigatório; ausência não deve cair em nenhum default de rede.
if [[ -z "$TARGET" ]]; then
    printf '[ERRO] Defina NINFA_ZAP_TARGET para uma URL local de desenvolvimento.\n' >&2
    exit 1
fi

# O relatório precisa ficar em caminho escolhido pelo chamador para não escrever
# artefato dinâmico silenciosamente no repositório ou no consumidor.
if [[ -z "$REPORT" ]]; then
    printf '[ERRO] Informe um relatório ZAP no workspace externo.\n' >&2
    exit 1
fi

# Limite de segurança deliberado: o wrapper legado não pode apontar active scan
# para hosts remotos. Apenas loopback explícito é aceito.
if [[ ! "$TARGET" =~ ^https?://(127\.0\.0\.1|localhost)(:[0-9]+)?(/|$) ]]; then
    printf '[ERRO] O DAST padrão do Ninfa aceita somente localhost/127.0.0.1.\n' >&2
    exit 1
fi

# A resolução prefere o caminho configurado/legado; `zaproxy` no PATH é apenas
# fallback manual. Falha explícita evita fingir que um scan inexistente ocorreu.
if [[ ! -x "$ZAP_BIN" ]]; then
    # O fallback de PATH só é usado quando o caminho configurado não é executável;
    # isso preserva a precedência explícita de NINFA_ZAP_BIN.
    if command -v zaproxy >/dev/null 2>&1; then
        ZAP_BIN="$(command -v zaproxy)"
    else
        printf '[ERRO] OWASP ZAP não encontrado. Execute NINFA_INSTALL_ZAP=1 make security-tools no repositório do Ninfa.\n' >&2
        exit 1
    fi
fi

# A partir daqui todas as pré-condições locais foram validadas; `exec` preserva
# o exit code do ZAP como exit code final do wrapper.
mkdir -p "$(dirname "$REPORT")"
printf '[NINFA] OWASP ZAP active scan local: %s\n' "$TARGET"
printf '[NINFA] Relatório: %s\n' "$REPORT"
exec "$ZAP_BIN" -cmd -quickurl "$TARGET" -quickout "$REPORT" -quickprogress
