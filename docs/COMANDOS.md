# Comandos do Ninfa

## Setup

```bash
make setup
```

Instala dependências Composer, valida metadados, prepara Semgrep CE e OWASP ZAP em `.tools/`, instala hooks quando Lefthook estiver disponível e executa `composer check`.

## Qualidade

```bash
composer qa
```

Executa ECS, Rector em dry-run, PHPStan, Psalm e PHPUnit.

## Segurança

```bash
composer security
```

Executa Composer Audit, Psalm Taint Analysis e Semgrep CE.

## Validação completa

```bash
composer check
```

Executa `composer qa` seguido de `composer security`.

## Correções automáticas

```bash
composer fix
```

Executa Rector e ECS em modo de correção.

## DAST

```bash
NINFA_ZAP_TARGET=http://127.0.0.1:8080 composer security:dast
```

O alvo padrão deve ser local e explicitamente autorizado.

## Comandos individuais

```bash
composer lint
composer rector
composer stan
composer psalm
composer psalm:taint
composer test
composer security:dependencies
composer security:semgrep
```
