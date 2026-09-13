# Profile GLPI Plugin 11

O Ninfa reconhece plugins GLPI como um perfil próprio de projeto. Este profile é deliberadamente voltado apenas ao GLPI 11.

## Detecção

A detecção usa sinais técnicos do projeto, sem depender do repositório estar fisicamente dentro de uma instalação GLPI. Entre os sinais considerados estão:

- `setup.php`;
- `hook.php`;
- função `plugin_init_<nome>()` em `setup.php`;
- função `plugin_<nome>_install()` em `hook.php`;
- classes em `src/` usando namespace `GlpiPlugin\\...`;
- presença de `src/` ou `inc/`;
- `composer.json`.

Quando a pontuação mínima é atingida, o contexto é registrado como:

```json
{
  "framework": "GLPI Plugin 11",
  "profile": "glpi-plugin"
}
```

## Caminhos analisados

Para plugins GLPI, o Ninfa procura os diretórios:

```text
src
inc
front
ajax
tests
```

Esses caminhos são os alvos da análise. O core GLPI não é colocado em `paths`.

`src/` é o layout preferencial. `inc/` continua sendo reconhecido durante a descoberta para não deixar código existente fora da análise.

## Descoberta do host GLPI

A análise estática de um plugin precisa conhecer o contrato do core GLPI. O Ninfa procura o host nesta ordem:

1. variável de ambiente `NINFA_GLPI_ROOT`;
2. instalação pai quando o projeto está em `<glpi>/plugins/<plugin>`.

Exemplo fora da árvore do GLPI:

```bash
NINFA_GLPI_ROOT=/opt/glpi php scripts/ninfa-configure.php . --force
```

O diretório informado deve conter pelo menos:

```text
src/
src/autoload/constants.php
```

A versão é lida de `src/autoload/constants.php`. Se um host for encontrado e sua major não for 11, a configuração é interrompida.

O profile não exige `vendor/bin/phpstan`, `composer.lock` ou dependências `require-dev` no host GLPI. O PHPStan continua sendo o instalado no próprio plugin consumidor pelo Ninfa.

## PHPStan

O desenho separa claramente alvo de análise e contexto externo:

```text
plugin -> paths -> código analisado
GLPI   -> symbol discovery/autoload -> API externa conhecida
```

Com um host GLPI 11 válido, o `phpstan.neon.dist` gerado:

- mantém apenas os diretórios do plugin em `paths`;
- usa `<GLPI_ROOT>/src` em `scanDirectories` apenas para descoberta de símbolos;
- usa arquivos reais do autoload GLPI 11 em `scanFiles`, incluindo `constants.php`, `dbutils-aliases.php`, `i18n.php` e `misc-functions.php` quando presentes;
- gera `.ninfa/phpstan-glpi-bootstrap.php`, que registra explicitamente o mapeamento de classes do GLPI 11 (`Glpi\\` -> `src/Glpi/` e classes globais -> `src/`), sem inicializar a aplicação GLPI;
- aproveita stubs oficiais presentes no host quando disponíveis;
- procura `glpi-project/phpstan-glpi` primeiro no `vendor` do próprio plugin e só depois no host.

### Extensão oficial `phpstan-glpi`

Para plugins GLPI, recomenda-se instalar a extensão oficial no próprio plugin:

```bash
composer require --dev glpi-project/phpstan-glpi:^1.3
```

Essa extensão é especialmente importante para tipos dinâmicos/globais do GLPI. Por exemplo, ela informa ao PHPStan que `global $DB` é um `DBmysql`, evitando a cascata de falsos `method.nonObject` causada por `$DB` inferido como `mixed`.

O profile não depende de a instalação GLPI possuir dependências de desenvolvimento. Se a extensão estiver instalada no plugin, o Ninfa inclui:

```text
vendor/glpi-project/phpstan-glpi/extension.neon
```

Se não estiver instalada, o Ninfa mantém a descoberta básica de símbolos do host e emite um aviso com o comando de instalação.

O profile não reduz automaticamente o nível do PHPStan e não cria `ignoreErrors` amplos. Depois que classes, funções e globais do GLPI estiverem resolvidos, erros de tipos do plugin continuam sendo reportados normalmente.

## Psalm

O GLPI expõe classes globais, callbacks descobertos em runtime e uma camada de banco dinâmica. Sem contexto específico, o Psalm interpreta esses contratos como `mixed` e produz uma cascata de falsos positivos.

Quando o host GLPI 11 é localizado, o profile gerado:

- reutiliza `.ninfa/phpstan-glpi-bootstrap.php` como autoloader leve;
- declara `global $DB` como `DBmysql`;
- usa a versão mínima de PHP encontrada em `composer.json`;
- não exige `#[Override]` em projetos que ainda suportam PHP 8.2;
- desativa detecção de código não utilizado, pois hooks e callbacks públicos são chamados dinamicamente pelo GLPI;
- não reporta `InvalidGlobal` originado no `inc/includes.php` externo ao plugin;
- inicia no nível 8 como análise complementar ao PHPStan.

O nível 8 é deliberado enquanto o Psalm não possui uma extensão equivalente a `phpstan-glpi` para modelar os iteradores dinâmicos de `DBmysql`. A análise de taint continua ativa pelo comando `composer psalm:taint`.

## Contexto gerado

`.ninfa/context.json` registra informações específicas do profile:

```json
{
  "framework": "GLPI Plugin 11",
  "profile": "glpi-plugin",
  "glpi": {
    "supported_major": 11,
    "detection_score": 18,
    "host_detected": true,
    "root": "/opt/glpi",
    "version": "11.0.x",
    "phpstan_extension_declared": true
  }
}
```

A pontuação exata depende dos sinais encontrados no projeto.

## CI

Quando o checkout do plugin não estiver dentro de `<glpi>/plugins`, prepare uma instalação GLPI 11 compatível no runner e informe sua raiz:

```bash
export NINFA_GLPI_ROOT=/opt/glpi
composer check
```

O workflow fornecido prepara por padrão um host GLPI 11.0.8 com `scripts/setup-glpi-host.sh`, define `NINFA_GLPI_ROOT` e regenera as configurações no runner efêmero. A versão pode ser alterada com `NINFA_GLPI_VERSION`.

O host GLPI pode ser uma instalação de runtime sem dependências de desenvolvimento. PHPStan, Psalm e `glpi-project/phpstan-glpi` pertencem ao ambiente de desenvolvimento do plugin.

As dependências Composer do plugin são instaladas antes da configuração final. Isso permite que o configurador encontre `vendor/glpi-project/phpstan-glpi/extension.neon` já na primeira execução.

## Escopo atual

Esta primeira versão do profile trata:

- detecção de plugin GLPI;
- paths específicos;
- descoberta do host;
- validação da major 11;
- autoload independente do tooling dev do host;
- integração opcional com a extensão oficial `glpi-project/phpstan-glpi` instalada no plugin;
- descoberta dos símbolos reais do core sem colocar o GLPI em `paths`.

Regras Semgrep específicas de segurança GLPI e validações especializadas de hooks/permissões ficam fora deste primeiro incremento.
