# Comandos do Ninfa

## Instalação contextual

```bash
make install
```

Detecta framework e caminhos do projeto, consulta `README.md` e `docs/*.md`, gera apenas configurações ausentes e executa `composer install`.

Para sobrescrever as configurações gerenciadas pelo Ninfa:

```bash
make install-force
```

Esse comando refaz a descoberta contextual, regenera `ecs.php`, `rector.php`, `phpstan.neon.dist`, `psalm.xml` e `phpunit.xml.dist` e depois executa `composer install`.

## Reconfiguração contextual

```bash
make configure
```

Refaz a descoberta do projeto e atualiza `.ninfa/context.json` e `.ninfa/paths.txt`. Também gera `ecs.php`, `rector.php`, `phpstan.neon.dist`, `psalm.xml` e `phpunit.xml.dist` apenas quando esses arquivos ainda não existirem.

Para regenerar e sobrescrever esses arquivos:

```bash
make configure-force
```

Equivale a:

```bash
php scripts/ninfa-configure.php . --force
```

## Teste do profile GLPI

```bash
make profile-test
```

Cria um plugin e um host GLPI mínimos em diretório temporário e valida contexto, PHPStan e Psalm gerados.

## Instalador inicial com force

Durante a primeira implantação, o instalador também aceita `--force`:

```bash
php /tmp/ninfa/bin/ninfa-install.php . --force
```

Nesse modo, os arquivos de infraestrutura gerenciados pelo Ninfa são substituídos e o configurador contextual também é executado com `--force`.

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
