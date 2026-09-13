# Segurança

O Ninfa mantém segurança separada do pipeline comum de qualidade.

## Comando

```bash
ninfa security /caminho/do/projeto
```

O baseline considera:

```text
Composer Audit        # quando houver composer.lock
Psalm Taint Analysis
Semgrep
OWASP ZAP             # somente opt-in
```

## Composer Audit

Executa `composer audit --locked --no-interaction` somente quando o projeto possui `composer.lock`. Projetos PHP genéricos sem Composer não falham apenas pela ausência desse recurso.

## Psalm Taint

Reutiliza a configuração Psalm gerada no workspace externo e executa análise de taint sobre os paths detectados.

## Semgrep

Usa as regras do próprio Ninfa em `/opt/ninfa/security/semgrep.yml` e analisa somente os paths detectados do projeto alvo. Diretórios como `vendor` e `runtime` são excluídos.

## DAST / OWASP ZAP

DAST é opcional. Para habilitar em alvo local autorizado:

```bash
NINFA_DAST=1 \
NINFA_ZAP_TARGET=http://127.0.0.1:8080 \
ninfa security /caminho/do/projeto
```

O wrapper padrão recusa destinos que não sejam `localhost` ou `127.0.0.1`. O relatório gerado pelo pipeline é direcionado ao workspace externo do projeto, não ao consumidor.

## Princípio

Achados devem ser corrigidos ou tratados por regra específica e revisável. O MVP não deve criar exclusões globais apenas para silenciar a pipeline.
