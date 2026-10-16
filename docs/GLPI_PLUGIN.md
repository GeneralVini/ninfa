# Profile GLPI Plugin 11

> **EAP mestre:** [GLPI-PLUGIN-EAP.md](GLPI-PLUGIN-EAP.md) — coordenação das cinco ondas, IDs, prioridades, evidências e gates. Este arquivo mantém seu escopo especializado e não deve ser usado como segunda fonte concorrente de status.

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

### Inventário do registry de hooks (GLPI-2, parcial)

O `GlpiHookRegistryAnalyzer` indexa atribuições a `$PLUGIN_HOOKS` em `setup.php` somente quando o primeiro índice é uma string literal **ou uma constante oficial do host GLPI 11 resolvida**, e o binding global está comprovado: no escopo do arquivo ou em `plugin_init_<plugin>()` após `global $PLUGIN_HOOKS;` em nível direto do corpo. Atribuições em funções auxiliares, variáveis locais homônimas e closures sem binding global são ignoradas. Preserva `hook`, `plugin` literal quando houver, linha, nível do registro e se o valor é um escalar isolado; os fatos aparecem em `framework_semantics.hook_registrations` e em `assist/glpi-semantic.json`.

`NINFA-GLPI-COR-003` sinaliza apenas a atribuição de **escalar ao índice de hook sem índice do plugin**, para hooks comprovadamente por plugin: `csrf_compliant`, `config_page`, `change_profile`, `change_entity`, `init_session` e `use_massive_action`.

```php
$PLUGIN_HOOKS['csrf_compliant'] = true;             // COR-003
$PLUGIN_HOOKS['csrf_compliant']['example'] = true;  // correto
$PLUGIN_HOOKS['csrf_compliant'] = ['example' => true]; // mapa completo, não acusado
```

`GlpiHookConstantResolver` consulta exclusivamente `<host>/src/Glpi/Plugin/Hooks.php` e resolve valores públicos literais para `Hooks::NOME` com `use Glpi\Plugin\Hooks` (com ou sem alias), `\Glpi\Plugin\Hooks::NOME` e referências qualificadas inequívocas. Na ausência do arquivo oficial no host, em imports de outro vendor, constantes calculadas, índices calculados, concatenação/ternário no valor, arrays completos ou registros de três ou mais dimensões, a regra não emite diagnósticos sem prova. O finding é **correctness/warning/high**, `remediation_risk=review`, `autofix=false`. Não prova bypass de CSRF nem outros problemas de segurança.

`GlpiHookCallbackIndex` registra, adicionalmente, referências literais simples de função nos hooks `change_profile`, `change_entity`, `init_session` e `post_init`. Quando a função global é declarada nos entrypoints, o campo `declared=true` comprova a declaração textual; fora deles, `declared=null` significa **unknown**, nunca ausência definitiva. Os fatos são exportados em `hook_callbacks` no JSON sem criar uma nova regra ou autofix.

### Classes locais e `Plugin::registerClass()` (índice inicial)

`GlpiClassDeclarationIndex` percorre **somente** `setup.php`, `hook.php` e arquivos PHP regulares em `src/`, via `token_get_all`, sem executar código. Reconhece classes nomeadas no escopo global ou em namespaces convencionais `namespace X;` e métodos declarados diretamente. Classes anônimas/condicionais, traits, interfaces e namespaces em bloco permanecem fora.

`GlpiClassRegistrationIndex` captura `Plugin::registerClass('PluginExampleProfile', ...)` ou `\\Plugin::registerClass(...)` quando o símbolo global é inequívoco e o primeiro argumento é uma string literal simples.

```php
Plugin::registerClass('PluginSigapsProfile', ['addtabon' => ['Profile']]);
```

O snapshot expõe `class_declarations` e `class_registrations`: `declared=true` confirma declaração local; `declared=null` significa **unknown**, não classe inexistente. A presença de métodos comprova somente declarações, **não** invocabilidade, herança nem autoload completo. `GlpiClassReferenceResolver` agora resolve `Plugin::registerClass(Alias::class)`, strings simples e nomes de classe totalmente qualificados, incluindo aliases `use` não ambíguos. Imports agrupados, aliases conflitantes, expressões compostas e autoload/herança externos permanecem inconclusivos. **Esta tranche não introduz Finding nem autofix.**

Callables em arrays/métodos, referências externas e tipos de valor por hook permanecem em aberto até prova estática suficiente.

Referências: [GLPI developer docs — Hooks](https://github.com/glpi-project/docdev/blob/master/source/plugins/hooks.rst) e [plugin Tag compatível com GLPI 11](https://github.com/pluginsGLPI/tag/blob/main/setup.php).

### Gate semântico no `ninfa check`

Além de `assist`, `check` executa a etapa nativa `glpi-semantic` **somente** para `glpi-plugin`, de modo independente dos binários externos. Os findings `COR-001..003` entram em `RunResult`/`ToolResult` e são exibidos no resumo. Apenas `severity=error` bloqueia o `check`; `COR-003` é `warning` observável, sem exit de erro.

A etapa grava `check/glpi-semantic.json` e `check/glpi-findings.json` exclusivamente sob o workspace externo. Qualquer falha de serialização/escrita torna a etapa `error`, nunca um falso scan limpo. O `fix` não executa o analyzer nem aplica correções GLPI; `security` mantém seu pipeline SCA/SAST independente. `assist` continua exportando os artefatos próprios em `assist/`.

Os demais contratos (validação de callbacks de hooks, Session/rights/CSRF, banco/migrations e deprecations GLPI 11) continuam pendentes do gate em [GLPI-PLUGIN-CLOSURE.md](GLPI-PLUGIN-CLOSURE.md).

## Semântica documental

README, AGENTS e documentação do plugin podem enriquecer o índice semântico de símbolos, mas não substituem os contratos reais fornecidos pelo host GLPI e pelo `phpstan-glpi`.

## Frontend

Se o plugin contiver JavaScript/TypeScript real, `check` e `fix` também incluem ESLint e Prettier. Diretórios genéricos sem arquivos JS/TS não ativam esse pipeline.

## Segurança

```bash
ninfa security /caminho/do/plugin
```

Executa Composer Audit, OSV, Psalm Taint e Semgrep. DAST não integra o comando público `ninfa security` nesta baseline; a configuração `NINFA_DAST=1` não executa OWASP ZAP automaticamente.

### Configurações próprias do consumidor — advertência de cobertura

A configuração externa protege o plugin contra sobrescrita, inclusive no modo legado `--force`. **Entretanto, preservação não equivale a aplicação**: as etapas PHPStan, Psalm, ECS e Rector recebem configurações geradas pelo NINFA, sem mesclagem automática dos arquivos `phpstan.neon[.dist]`, `psalm.xml[.dist]`, `ecs.php` ou `rector.php` já existentes no consumidor.

Quando detectados, o NINFA emite um aviso não bloqueante e cria o manifesto externo `consumer-config-advisory.json`, com nomes relativos e `review_required=true`. O manifesto não lê, executa ou modifica configurações do plugin. Scripts de teste e PHPUnit têm contratos próprios e não são classificados automaticamente como ignorados por esta advertência.

Essa distinção preserva o apontamento histórico P1 [PR #1 comentário 3998260123](https://github.com/GeneralVini/ninfa/pull/1#discussion_r3998260123) como **parcialmente aberto**: o bloqueio contra overwrite tem regressão, mas aplicar corretamente regras personalizadas ainda exige política de composição explícita.

## Field test seguro

A homologação em **SIGAPS e ÉGIDE** deve usar `scripts/glpi-field-test.sh` sobre **cópias isoladas**; jamais direcione `ninfa fix` a qualquer original. SIGAPS tem caminho conhecido `/var/www/psglpi/plugins/sigaps`; o caminho real do ÉGIDE ainda precisa ser informado e confirmado. O harness preserva o nome técnico do plugin na cópia (essencial para resolver callbacks), rejeita symlinks e compara o estado do original no início e no fim, mantendo seus artefatos em diretório temporário. Consulte o [gate de fechamento](GLPI-PLUGIN-CLOSURE.md) e a [matriz comparativa SIGAPS/ÉGIDE](GLPI-PLUGIN-FIELD-TEST-CORPUS.md) para critérios, evidências e limitações do isolamento.

## Escopo do MVP

O profile cobre detecção, validação GLPI 11, descoberta de símbolos, PHPStan/Psalm contextuais, workspace externo e pipelines de qualidade/segurança. O [corpus de homologação](GLPI-PLUGIN-FIELD-TEST-CORPUS.md) já identifica os projetos SIGAPS e [ÉGIDE](https://github.com/GeneralVini/egide) (repositório privado) para revisão comparativa; o ÉGIDE foi inspecionado estaticamente, mas ainda não foi analisado por execução local do NINFA. O overlay Semgrep GLPI já existe, mas sua calibração e a validação semântica de permissões/hooks ainda dependem de fixtures e field tests em cópias de plugins reais. Não se deve declarar o profile fechado antes do [gate de fechamento](GLPI-PLUGIN-CLOSURE.md).
