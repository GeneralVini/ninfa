# Segurança

O Ninfa mantém segurança separada do pipeline comum de qualidade.

## Comando

```bash
bin/ninfa security /caminho/do/projeto
```

O baseline executa:

```text
Composer Audit
Psalm Taint Analysis
Semgrep
```

## Composer Audit

Executa `composer audit --locked --no-interaction` no projeto alvo.

## Psalm Taint

Reutiliza a configuração Psalm gerada no workspace externo e executa análise de taint.

## Semgrep

Usa as regras do próprio Ninfa em `security/semgrep.yml` e analisa somente os paths detectados do projeto alvo. Diretórios como `vendor` e `runtime` são excluídos.

## DAST / OWASP ZAP

DAST é opcional e não roda apenas por executar `security`. Para habilitar:

```bash
NINFA_DAST=1 \
NINFA_ZAP_TARGET=http://127.0.0.1:8080 \
bin/ninfa security /caminho/do/projeto
```

O wrapper padrão recusa destinos que não sejam `localhost` ou `127.0.0.1`.

## Princípio

Achados devem ser corrigidos ou tratados por regra específica e revisável. O MVP não deve criar exclusões globais apenas para silenciar a pipeline.
