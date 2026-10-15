# Profile GLPI Plugin 11

O profile `glpi-plugin` é dedicado a plugins GLPI 11 e faz parte do MVP inicial do Ninfa.

Os exemplos abaixo assumem `/opt/ninfa/bin` no `PATH`:

```bash
export PATH="/opt/ninfa/bin:$PATH"
```

## Detecção

A detecção usa sinais técnicos combinados, incluindo `setup.php`, `hook.php`, hooks de instalação/inicialização e namespaces `GlpiPlugin\\...`. `composer.json` é opcional no detector; o contexto exige paths analisáveis e um host GLPI 11 reconhecível.

`setup.php` e `hook.php` tambem integram explicitamente os paths de PHPStan,
Psalm, ECS e Semgrep. Eles sao entrypoints do ciclo de vida do plugin e nao
podem ficar cobertos apenas por deteccao de profile. O Rector nao transforma
esses arquivos: mudancas automaticas em guards de versao ou coercao dos hooks
podem alterar a compatibilidade do plugin.

## Host GLPI

O host é resolvido nesta ordem:

1. `NINFA_GLPI_ROOT`;
2. instalação pai quando o plugin está em `<glpi>/plugins/<plugin>`.

O host precisa fornecer `src/autoload/constants.php`. Apenas versões `11.x` são aceitas.

Exemplo:

```bash
NINFA_GLPI_ROOT=/opt/glpi ninfa check /caminho/do/plugin
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

## Semântica nativa de lifecycle — baseline inicial

`GlpiPluginSemanticModel` lê `setup.php` e `hook.php` usando tokens PHP, sem incluir/executar o consumidor, e registra quatro callbacks convencionais associados ao nome técnico do diretório:

```text
setup.php  plugin_init_<plugin>()
setup.php  plugin_version_<plugin>()
hook.php   plugin_<plugin>_install()
hook.php   plugin_<plugin>_uninstall()
```

Os IDs `NINFA-GLPI-COR-001` (entrypoint obrigatório ausente) e `NINFA-GLPI-COR-002` (callback obrigatório não declarado) são correctness com `autofix=false`, disponíveis no `ninfa assist`. Só são produzidos quando a ausência pode ser afirmada. Includes, namespaces e declarações condicionais deixam a evidência em `unknown`, sem finding especulativo. A regra não tenta determinar se um hook dinâmico será executado, se a função retorna sucesso ou se o plugin pode ser instalado no host.

O configurador escreve `framework_semantics` no `semantic-index.json` externo. Em `assist`, os fatos e resultados nativos ficam também em `assist/glpi-semantic.json`, `assist/glpi-findings.json` e `assist/findings.json`, sob o workspace NINFA. O analisador não escreve no plugin.

Os demais contratos (registro de hooks, Session/rights/CSRF, banco/migrations e deprecations GLPI 11) continuam pendentes do gate em [GLPI-PLUGIN-CLOSURE.md](GLPI-PLUGIN-CLOSURE.md).

## Semântica documental

README, AGENTS e documentação do plugin podem enriquecer o índice semântico de símbolos, mas não substituem os contratos reais fornecidos pelo host GLPI e pelo `phpstan-glpi`.

## Frontend

Se o plugin contiver JavaScript/TypeScript real, `check` e `fix` também incluem ESLint e Prettier. Diretórios genéricos sem arquivos JS/TS não ativam esse pipeline.

## Segurança

```bash
ninfa security /caminho/do/plugin
```

Executa Composer Audit, OSV, Psalm Taint e Semgrep. DAST não integra o comando público `ninfa security` nesta baseline; a configuração `NINFA_DAST=1` não executa OWASP ZAP automaticamente.

## Field test seguro

A homologação em SIGAPS deve usar `scripts/glpi-field-test.sh` sobre **cópia isolada**; jamais direcione `ninfa fix` ao path original `/var/www/psglpi/plugins/sigaps`. O harness rejeita symlinks e compara o estado do original no início e no fim, mantendo seus artefatos em diretório temporário. Consulte o [gate de fechamento](GLPI-PLUGIN-CLOSURE.md) para limitações do isolamento.

## Escopo do MVP

O profile cobre detecção, validação GLPI 11, descoberta de símbolos, PHPStan/Psalm contextuais, workspace externo e pipelines de qualidade/segurança. O overlay Semgrep GLPI já existe, mas sua calibração e a validação semântica de permissões/hooks ainda dependem de fixtures e field tests em cópias de plugins reais. Não se deve declarar o profile fechado antes do [gate de fechamento](GLPI-PLUGIN-CLOSURE.md).
