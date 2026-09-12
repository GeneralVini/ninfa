# Comandos do Ninfa

## Instalação contextual

```bash
make install
```

Detecta framework e caminhos do projeto, consulta `README.md` e `docs/*.md`, gera apenas configurações ausentes e executa `composer install`.

## Reconfiguração contextual

```bash
make configure
```

Refaz a descoberta do projeto e atualiza `.ninfa/context.json` e `.ninfa/paths.txt`. Também gera `ecs.php`, `rector.php`, `phpstan.neon.dist`, `psalm.xml` e `phpunit.xml.dist` apenas quando esses arquivos ainda não existirem.

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

Executa Composer Audit, Psalm Taint Analysis e Semgrep CE. O Semgrep usa por padrão os caminhos detectados em `.ninfa/paths.txt`.

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
