# Arquitetura interna e documentação de código

Este documento descreve o fluxo interno implementado no Ninfa a partir do código atual. Ele não substitui PHPDoc, comentários de shell ou documentação junto ao código: serve como mapa para localizar responsabilidades e evitar que comentários futuros contradigam a implementação.

## Premissa obrigatória de documentação

A documentação interna é parte do contrato de implementação do Ninfa, não um acabamento posterior. Código de produção novo ou alterado deve nascer documentado no mesmo commit.

A unidade mínima de documentação não é o arquivo. Um cabeçalho de classe, módulo ou script não substitui a documentação dos símbolos e blocos internos relevantes.

Para PHP:

1. toda classe de produção deve possuir PHPDoc com responsabilidade e limites;
2. todo método ou função nomeada de produção deve possuir PHPDoc descritivo, inclusive métodos privados;
3. `@param`, `@return`, `@var` e shapes devem preservar informação que o type hint nativo não consegue expressar;
4. `array` não deve ficar semanticamente sem tipo quando o código conhece o shape, a lista ou os tipos de chave/valor;
5. acumuladores e estruturas locais não triviais devem receber `@var` quando o tipo não é evidente;
6. blocos de controle relevantes devem explicar decisão, precedência ou invariável;
7. exceções relevantes, I/O, arquivos gerados, rede e mutações devem ser documentados junto do método que os executa.

Para shell vale o mesmo princípio em outra sintaxe: cabeçalho, funções e blocos semânticos devem registrar finalidade, efeitos externos, restrições e motivo das validações. JavaScript próprio do Ninfa, quando existir, deve usar JSDoc para módulos/funções e estruturas complexas.

A suíte não mantém allowlist documental para `src/`: arquivos novos entram automaticamente nas verificações.

## Fluxo executável atual

```text
bin/ninfa
  ↓
ProjectContext::fromRoot()
  ├─ ProfileDetector
  ├─ Workspace
  └─ resolução de host GLPI quando profile = glpi-plugin
  ↓
RecheckingPipelineRunner
  ↓
PipelineRunner
  ├─ ExternalConfigGenerator
  ├─ PipelinePlan
  ├─ ToolResolver
  ├─ ProcessRunner
  └─ scanners/parsers específicos
       ↓
    Finding[]
       ↓
    ToolResult[]
       ↓
    RunResult
```

`fix` possui comportamento adicional: `RecheckingPipelineRunner` executa `fix` e, somente se ele terminar com código 0, executa um `check` completo. `assist` não passa por esse runner; o CLI delega ao configurador em modo `--assist`.

## Entrada e contexto do projeto

### `bin/ninfa`

É o entrypoint do CLI. Aceita `check`, `fix`, `security` e `assist`, resolve a raiz informada ou o diretório atual, cria `ProjectContext` e imprime projeto, profile e workspace.

### `src/ProjectContext.php`

Constrói o contexto imutável. A implementação:

- resolve a raiz com `realpath()`;
- carrega `composer.json` quando existe;
- delega a classificação a `ProfileDetector`;
- mantém separadas constraint PHP declarada e versão real do runtime;
- escolhe paths analisáveis conforme o profile e somente inclui paths existentes;
- cria `Workspace` externo;
- para `glpi-plugin`, exige host GLPI 11 identificável.

`phpVersion()` representa `major.minor` do runtime; `runtimePhpVersion()` preserva `PHP_VERSION`; `phpConstraint()` representa `require.php` quando presente.

### `src/ProfileDetector.php`

Classifica nesta ordem: GLPI Plugin, Yii2, Yii3 e PHP genérico. GLPI usa sinais estruturais/semânticos. Yii2 depende de `yiisoft/yii2`. Yii3 exige marcador de aplicação/runner + marcador de infraestrutura. O fallback genérico exige código PHP observável.

Os quatro identificadores oficiais atuais são:

```text
glpi-plugin
yii2
yii3
php-generic
```

Yii 22 continua dentro de `yii2`; não existe detector separado para ele. Laravel/Python não participam do detector atual.

O detector responde somente qual profile se aplica; semântica de segurança pertence a `SecurityContract`.

### `src/Workspace.php`

Cria workspace por projeto, por padrão em `<tmp>/ninfa/<hash>`. `NINFA_WORKSPACE_ROOT` pode mudar a base, mas a implementação rejeita base igual ou interna ao consumidor.

## Planejamento e execução

### `src/PipelinePlan.php`

Define etapas de cada operação:

```text
check
  ECS
  Rector --dry-run
  PHPStan
  Psalm
  ESLint      quando aplicável
  Prettier    quando aplicável
  testes

fix
  somente etapas marcadas como fixable

security
  Composer Audit
  OSV
  Psalm Taint
  Semgrep
```

O plano descreve; `PipelineRunner` executa.

### `src/PipelineRunner.php`

Orquestra `check`, `fix` e `security`. Ele:

- gera configurações externas;
- resolve ferramentas/comandos;
- executa etapas sem fail-fast;
- preserva o primeiro exit code não zero;
- materializa `ToolResult`;
- renderiza resumo usando `CliStyle`.

No modo `security` também cria inventário/relatório, executa OSV e normaliza Psalm Taint/Semgrep.

A política Semgrep é aplicada pelo Ninfa, não por `semgrep --error`:

```text
severity ERROR    bloqueia
severity WARNING  hotspot; não bloqueia sozinho
```

O comando Semgrep usa `--no-git-ignore` para incluir arquivos novos ainda não rastreados dentro dos paths permitidos. `vendor`, `runtime`, `public/assets` e `web/assets` são excluídos explicitamente.

`NINFA_DAST` apenas produz aviso; o runner não chama ZAP.

### `src/RecheckingPipelineRunner.php`

Altera somente `fix`: depois de um fix bem-sucedido, executa exatamente um `check`. Se ainda houver falha, orienta o uso de `ninfa assist`.

### `src/ToolResolver.php`

Resolve binários nesta ordem:

1. `node_modules/.bin` do consumidor;
2. `vendor/bin` do consumidor;
3. `.tools/<tool>/bin/<tool>` do Ninfa;
4. `node_modules/.bin` do Ninfa;
5. `vendor/bin` do Ninfa;
6. `PATH`.

Se Semgrep não for encontrado, orienta `make security-tools`.

### `src/ProcessRunner.php`

Atualmente reúne `ProcessResult`, `FindingRenderer` e `ProcessRunner`. Esse agrupamento é fato da implementação e não deve ser documentado como arquivos separados até eventual refatoração.

## Configuração e detecção auxiliar

### `src/ExternalConfigGenerator.php`

Gera no workspace configurações para PHPStan, Psalm, ECS e Rector. Para GLPI Plugin 11 também pode localizar `phpstan-glpi`, gerar bootstrap, adicionar source/stubs do host e configurar `$DB`/exceção Psalm específica.

Nada disso é escrito no consumidor.

### `src/FrontendDetector.php`

Detecta sinais JS/TS e disponibilidade de ESLint/Prettier no consumidor.

### `src/LefthookConfigGenerator.php`

Gera `lefthook.yml` no workspace. `pre-commit` chama `ninfa fix`; `pre-push` chama `ninfa check`.

### `src/SemanticHints.php`

Lê documentação do consumidor e extrai sinais documentais. Não percorre AST/call graph e não deve ser usado como prova de reachability.

## Modelo de resultado

### `src/Finding.php`

Representa achado normalizado com localização, regra, problema, correção, severidade, confiança, tipo de evidência, proveniência e metadata. Não decide sozinho se um finding bloqueia.

### `src/ToolResult.php`

Representa uma etapa e distingue `ok`, `failed`, `error`, `skipped`, `not_applicable`, `unavailable` e `partial`. Preserva findings, duração e coverage.

### `src/RunResult.php`

Consolida a operação e os resultados das ferramentas. O exit final preserva a primeira falha em ordem de execução.

## Contratos SAST

### `src/SecurityContract.php`

Resolve os doze contratos canônicos para o profile atual. O baseline é PHP comum; overlays entram somente quando o framework possui semântica própria.

```text
php-generic  common
yii2        common + yii2
yii3        common + yii3
glpi-plugin common + glpi-plugin-11
```

O overlay Yii2 cobre Request/Response, DB/Command, HTML, redirect, headers, path/filesystem e HTTP-client em nível semântico. Yii 22 permanece nessa família sem branch de profile separada.

O objeto também resolve as configurações Semgrep que `PipelineRunner` deve carregar. Scanners não devem espalhar condicionais de framework quando a informação pertence ao contrato.

### `src/SemgrepParser.php`

Converte JSON nativo do Semgrep em `Finding` e normaliza cobertura.

Além de `scanned`/`skipped`, separa:

```text
excluded_by_policy
unexpected_skips
errors
```

Cobertura é `partial` apenas quando há skip inesperado ou erro do mecanismo. Exclusões deliberadas continuam auditáveis sem produzir falso estado de cobertura incompleta.

### `src/PsalmTaintParser.php`

Normaliza findings de dataflow do Psalm preservando trace quando disponível. Psalm Taint continua motor principal para propagação PHP interprocedural.

## Regras e fixtures Semgrep

Regras:

```text
security/semgrep/common.yml
security/semgrep/profiles/yii2.yml
security/semgrep/profiles/yii3.yml
security/semgrep/profiles/glpi-plugin-11.yml
```

Fixtures paralelas:

```text
security/semgrep-tests/common.php
security/semgrep-tests/profiles/yii2.php
security/semgrep-tests/profiles/yii3.php
security/semgrep-tests/profiles/glpi-plugin-11.php
```

`make semgrep-rules` executa `--validate` antes de `--test`. `make setup` inclui esse target depois de preparar o Semgrep homologado.

## SCA implementado

### `src/SecurityInventory.php`

A precedência de versão é:

```text
composer.lock
  ↓ fallback quando lock não existe
vendor/composer/installed.json
```

Preserva dependências direct/transitive, runtime/dev, runtime PHP, constraint, extensões, profile, paths e contexto GLPI.

### `src/ComposerAuditParser.php`

Converte JSON de `composer audit` em `Finding`. Advisories são `sca-advisory`; pacotes abandonados são `dependency-policy`.

### `src/OsvClient.php`

Consulta OSV em batch para packages Composer resolvidos, trata paginação, busca registros por ID, ignora `withdrawn` e produz findings correlacionados ao inventário.

### `src/ScaFindingDeduplicator.php`

Agrupa findings SCA por IDs/aliases conectados e preserva proveniência/componentes.

### `src/SecurityReport.php`

Aceita `RunResult` de `security` e grava o schema estruturado com inventário, fontes, findings, vulnerabilidades deduplicadas e exit final.

## Scripts executáveis

### `scripts/ninfa-configure.php`

No modo normal gera configs, `lefthook.yml` e `semantic-index.json`. Com `--assist`, executa PHPStan/Psalm estruturados, grava evidência bruta e findings no workspace externo.

### `scripts/install-security-tools.sh`

Prepara Semgrep em `.tools/semgrep` com Python >=3.10 e versão fixada por `NINFA_SEMGREP_VERSION` (default atual 1.177.0). Não instala ZAP.

### `scripts/setup-glpi-host.sh`

Prepara host GLPI 11 para uso/teste do profile.

### `scripts/zap-scan.sh`

Wrapper legado/congelado; não integra `ninfa security`.

## Makefile interno

Targets relevantes:

```text
environment-check
security-tools
semgrep-rules
syntax
profile-test
setup
```

`semgrep-rules` depende de `security-tools` e valida/testa a suíte Semgrep. `setup` executa toda a cadeia. Esses targets pertencem ao desenvolvimento do Ninfa, não ao consumidor.

## Política visual

`CliStyle` permanece a única abstração de cores/símbolos. `NINFA_COLOR=auto` é o padrão; `always`, `never` e `NO_COLOR` completam o contrato. Não existem configurações específicas por scanner.

## Padrão para PHPDoc e comentários

A documentação deve ficar junto do símbolo/bloco que explica e conter somente fatos sustentados pelo código ou decisão explícita. Métodos/funções nomeadas precisam registrar responsabilidade; collections/shapes devem preservar tipos úteis; comentários de controle devem explicar intenção/invariante, não narrar sintaxe.

`tests/internal-docs.php` aplica o padrão a `src/*.php`. `tests/shell-docs.php` protege scripts shell. Não existe allowlist permanente de dívida documental.

## Estado atual

A fundação, SCA estruturado, SAST estruturado e contratos de profile estão implementados para os quatro profiles PHP oficiais. O próximo trabalho deve ser calibração em projetos reais, redução de falsos positivos e evolução de exposure/gates baseada em evidência.

Roadmap de frameworks/ecossistemas:

```text
acompanhar: Yii 22 dentro de yii2
futuro PHP: Laravel
futuro: Python → python-generic → Django/Flask
```

Não antecipar abstrações multilíngues apenas para materializar roadmap.
