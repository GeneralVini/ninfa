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

`src/` é o layout preferencial. `inc/` continua sendo reconhecido durante a descoberta para não deixar código existente fora da análise.

## Descoberta do host GLPI

A análise estática de um plugin precisa conhecer o core GLPI. O Ninfa procura o host nesta ordem:

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

Quando nenhum host é encontrado, o profile ainda é detectado e os paths do plugin são configurados, mas o Ninfa emite aviso de que a análise PHPStan integrada ao core GLPI não está disponível.

## PHPStan

O GLPI 11 já possui integração própria com PHPStan. Por isso o Ninfa prioriza os recursos oficiais do host em vez de manter uma cópia paralela da API GLPI.

Com um host GLPI 11 válido, o `phpstan.neon.dist` gerado pode acrescentar:

- `vendor/glpi-project/phpstan-glpi/extension.neon` em `includes`, quando instalado no host;
- `<GLPI_ROOT>/src` em `scanDirectories`;
- aliases globais de `src/autoload/`, como `dbutils-aliases.php`, em `scanFiles` quando presentes;
- `<GLPI_ROOT>/vendor/autoload.php` em `bootstrapFiles` quando presente;
- stubs oficiais do próprio GLPI, como `stubs/db_config_classes.php`, `stubs/glpi_constants.php` e `stubs/plugins_migrations_classes.php`, quando presentes.

O objetivo é permitir que o PHPStan reconheça o runtime real do GLPI, incluindo classes, aliases, funções e tipos dinâmicos usados pelos plugins, sem criar uma lista ampla de `ignoreErrors` no Ninfa.

O profile não reduz automaticamente o nível do PHPStan e não ignora erros de `mixed`, tipos de retorno ou contratos do plugin. Depois que o core GLPI é conhecido, esses problemas devem continuar aparecendo quando forem reais.

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
    "version": "11.0.x"
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

O Ninfa não baixa nem instala GLPI automaticamente. A versão do host usado em CI deve ser gerenciada explicitamente pela equipe responsável pela pipeline.

## Escopo atual

Esta primeira versão do profile trata:

- detecção de plugin GLPI;
- paths específicos;
- descoberta do host;
- validação da major 11;
- integração do PHPStan com a extensão, stubs e símbolos oficiais disponíveis no core GLPI.

Regras Semgrep específicas de segurança GLPI e validações especializadas de hooks/permissões ficam fora deste primeiro incremento para evitar misturar descoberta de runtime com novas políticas de segurança.
