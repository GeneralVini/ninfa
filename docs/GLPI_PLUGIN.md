# Profile GLPI Plugin 11

O profile `glpi-plugin` é dedicado a plugins GLPI 11 e faz parte do MVP inicial do Ninfa.

## Detecção

A detecção usa sinais técnicos combinados, incluindo `setup.php`, `hook.php`, hooks de instalação/inicialização e namespaces `GlpiPlugin\\...`. O projeto também precisa possuir `composer.json` e paths analisáveis.

## Host GLPI

O host é resolvido nesta ordem:

1. `NINFA_GLPI_ROOT`;
2. instalação pai quando o plugin está em `<glpi>/plugins/<plugin>`.

O host precisa fornecer `src/autoload/constants.php`. Apenas versões `11.x` são aceitas.

Exemplo:

```bash
NINFA_GLPI_ROOT=/opt/glpi bin/ninfa check /caminho/do/plugin
```

## Paths do plugin

O Ninfa considera, quando existentes:

```text
src
inc
front
ajax
tests
```

O core GLPI não entra como alvo de análise do plugin; ele fornece contexto externo de símbolos.

## Workspace externo

As configurações GLPI são geradas fora do consumidor, por padrão em `/tmp/ninfa/<hash>`:

```text
phpstan.neon
psalm.xml
glpi-bootstrap.php
```

O bootstrap carrega o autoload do host quando disponível e resolve classes `Glpi\\...` e classes globais diretamente a partir de `src/`.

## PHPStan

O profile GLPI usa **nível 8**.

Quando disponíveis, o gerador inclui arquivos reais do GLPI para descoberta de símbolos, como:

- `src/autoload/constants.php`;
- `src/autoload/dbutils-aliases.php`;
- `src/autoload/i18n.php`;
- `src/autoload/misc-functions.php`;
- stubs oficiais do host.

A extensão `glpi-project/phpstan-glpi` é procurada primeiro no `vendor` do plugin e depois no host. Recomenda-se disponibilizá-la no ambiente de desenvolvimento do plugin.

## Psalm

Psalm também usa **nível 8** no profile GLPI. O config externo:

- usa o bootstrap GLPI como autoloader;
- declara `$DB` como `DBmysql`;
- desativa `findUnusedCode` para evitar falsos positivos de hooks/callbacks dinâmicos;
- ajusta `ensureOverrideAttribute` conforme a versão mínima de PHP;
- suprime `InvalidGlobal` somente para o `inc/includes.php` externo do host.

Psalm Taint é executado apenas no comando `security`.

## Semântica documental

README, AGENTS e documentação do plugin podem enriquecer o índice semântico de símbolos, mas não substituem os contratos reais fornecidos pelo host GLPI e pelo `phpstan-glpi`.

## Frontend

Se o plugin contiver JavaScript/TypeScript real, `check` e `fix` também incluem ESLint e Prettier. Diretórios genéricos sem arquivos JS/TS não ativam esse pipeline.

## Segurança

```bash
bin/ninfa security /caminho/do/plugin
```

Executa Composer Audit, Psalm Taint e Semgrep. DAST é opcional e somente local por padrão:

```bash
NINFA_DAST=1 \
NINFA_ZAP_TARGET=http://127.0.0.1:8080 \
bin/ninfa security /caminho/do/plugin
```

## Escopo do MVP

O profile cobre detecção, validação GLPI 11, descoberta de símbolos, PHPStan/Psalm contextuais, workspace externo e pipelines de qualidade/segurança. Regras Semgrep específicas do domínio GLPI e validações especializadas de permissões/hooks podem evoluir após os testes em plugins reais.
