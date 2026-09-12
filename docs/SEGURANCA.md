# Segurança

O Ninfa separa segurança estática, dependências e testes dinâmicos.

## Dependências

`composer security:dependencies` executa `composer audit --locked --no-interaction` e verifica vulnerabilidades conhecidas nas dependências registradas no lock file.

## Taint analysis

`composer psalm:taint` executa Psalm Taint Analysis para rastrear dados não confiáveis até operações sensíveis.

## Semgrep CE

`composer security:semgrep` aplica as regras locais de `security/semgrep.yml`.

O conjunto padrão cobre apenas alguns padrões genéricos de risco. Projetos consumidores podem acrescentar regras próprias, desde que revisadas e documentadas.

## OWASP ZAP

`composer security:dast` executa o wrapper local do OWASP ZAP. O wrapper exige `NINFA_ZAP_TARGET` e restringe o destino padrão a `localhost` ou `127.0.0.1`.

O relatório HTML padrão é salvo em:

```text
runtime/security/zap-report.html
```

## Ferramentas locais

Semgrep CE e OWASP ZAP são instalados em `.tools/` por `scripts/install-security-tools.sh`. O diretório não deve ser versionado.

O instalador valida o pacote do ZAP por SHA-256 antes da extração.

## Pipeline

A pipeline comum é:

```text
composer check
├── composer qa
│   ├── ECS
│   ├── Rector
│   ├── PHPStan
│   ├── Psalm
│   └── PHPUnit
└── composer security
    ├── Composer Audit
    ├── Psalm Taint Analysis
    └── Semgrep CE
```

O OWASP ZAP permanece fora de `composer check` porque depende de uma aplicação em execução.

## Tratamento de achados

Achados devem ser corrigidos, justificados ou tratados por regra específica e revisável. Não crie exclusões globais apenas para obter uma execução verde.
