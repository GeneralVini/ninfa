# Arquitetura interna e documentação de código

Este documento descreve o fluxo interno implementado no Ninfa. Ele não substitui PHPDoc, comentários de shell ou documentação junto ao código: funciona como mapa de responsabilidades e invariantes.

## Premissa obrigatória de documentação

Documentação interna é parte do contrato de implementação, não acabamento posterior. Código de produção novo ou alterado deve nascer documentado no mesmo conjunto de mudanças.

Para PHP:

1. toda classe de produção possui PHPDoc com responsabilidade e limites;
2. todo método/função nomeada possui PHPDoc descritivo, inclusive private;
3. `@param`, `@return`, `@var` e shapes preservam informação que o type hint nativo não expressa;
4. `array` não fica semanticamente sem tipo quando o código conhece seu shape;
5. acumuladores não triviais recebem `@var` quando necessário;
6. fluxos relevantes registram decisão/invariante, não narram sintaxe;
7. I/O, rede, arquivos gerados e mutações são documentados junto do método responsável.

`tests/internal-docs.php` aplica esse contrato automaticamente a `src/*.php`; não existe allowlist permanente para dívida documental. Shell possui guard correspondente em `tests/shell-docs.php`.

## Fluxo executável

```text
bin/ninfa
  ↓
ProjectContext::fromRoot()
  ├─ ProfileDetector
  ├─ Workspace
  └─ contexto GLPI quando aplicável
  ↓
RecheckingPipelineRunner
  ↓
PipelineRunner
  ├─ ExternalConfigGenerator
  ├─ PipelinePlan
  ├─ ToolResolver
  ├─ ProcessRunner
  └─ scanners/parsers
       ↓
    Finding[]
       ↓
    ToolResult[]
       ↓
    RunResult
```

`fix` possui duas particularidades:

- no profile Yii2, `bin/ninfa` executa `Yii2SafeRemediator` antes dos fixers externos;
- depois de um fix bem-sucedido, `RecheckingPipelineRunner` executa exatamente um `check` completo.

`assist` não passa pelo pipeline de mutação; é delegado ao configurador em modo auditável.

## Entrada e contexto

### `bin/ninfa`

Entrypoint público para `check`, `fix`, `security` e `assist`. Resolve root, cria `ProjectContext`, apresenta projeto/profile/workspace e delega a execução.

No Yii2, `fix` aplica primeiro remediações nativas marcadas SAFE. Essa etapa não é modelada como pseudo-binário em `PipelinePlan`.

### `src/ProjectContext.php`

Materializa fatos básicos e estáveis:

- root real;
- `composer.json` decodificado;
- profile;
- runtime/constraint PHP;
- paths analisáveis;
- workspace externo;
- host/versão GLPI quando aplicável.

Não carrega inventário semântico Yii2 para evitar virar god object.

### `src/ProfileDetector.php`

Classifica profiles na política atual:

```text
glpi-plugin
yii2
yii3
php-generic
```

Yii 22 permanece dentro da família `yii2` até existir diferença concreta que justifique profile distinto.

### `src/Workspace.php`

Cria workspace externo, por padrão `<tmp>/ninfa/<hash>`. `NINFA_WORKSPACE_ROOT` pode alterar a base, mas o workspace não pode residir dentro do consumidor.

## Planejamento e execução

### `src/PipelinePlan.php`

Descreve somente ferramentas/etapas externas do pipeline:

```text
check
  ECS
  Rector --dry-run
  PHPStan
  Psalm
  ESLint/Prettier quando aplicável
  testes

fix
  somente etapas externas fixable

security
  Composer Audit
  OSV
  Psalm Taint
  Semgrep
```

Remediação nativa Yii2 não aparece nesse plano porque não é ferramenta externa.

### `src/PipelineRunner.php`

Orquestra `check`, `fix` e `security`: gera configs externas, resolve ferramentas, executa sem fail-fast, materializa `ToolResult`, consolida `RunResult` e renderiza via `CliStyle`.

No modo security também produz inventário/relatório, executa OSV e normaliza Psalm Taint/Semgrep.

### `src/RecheckingPipelineRunner.php`

Altera somente o contrato de `fix`: após fix bem-sucedido, executa um e somente um `check`. Falha no fix impede o recheck.

### `src/ToolResolver.php`

Resolve binários nesta ordem:

1. `node_modules/.bin` do consumidor;
2. `vendor/bin` do consumidor;
3. `.tools/<tool>/bin/<tool>` do Ninfa;
4. `node_modules/.bin` do Ninfa;
5. `vendor/bin` do Ninfa;
6. `PATH`.

### `src/ExternalConfigGenerator.php`

Gera PHPStan, Psalm, ECS e Rector exclusivamente no workspace externo. O consumidor não recebe configuração ou dependência persistente por esse fluxo.

### `src/LefthookConfigGenerator.php`

Gera `lefthook.yml` externo. `pre-commit` chama `ninfa fix`; `pre-push` chama `ninfa check`.

## Modelo de resultados

### `src/Finding.php`

Contrato normalizado de achado: localização, regra, problema, correção, severidade, confiança, tipo de evidência, proveniência e metadata.

Findings Yii2 usam exatamente esse contrato; não existe schema paralelo por framework.

### `src/ToolResult.php`

Representa uma etapa e distingue `ok`, `failed`, `error`, `skipped`, `not_applicable`, `unavailable` e `partial`.

### `src/RunResult.php`

Consolida a operação e os resultados das ferramentas, preservando a política de exit code.

## Fundação semântica Yii2

```text
ProjectContext(profile=yii2)
  ↓
Yii2SemanticModel
  ├─ Redis/MongoDB capabilities
  ├─ controllers/actions/views
  └─ relations hasOne/hasMany
       │
       ├─ Yii2BehaviorActionAnalyzer
       ├─ Yii2RelationReferenceAnalyzer
       ├─ Yii2RelationLinkAnalyzer
       ├─ Yii2QueryConditionAnalyzer
       ├─ Yii2QueryExistenceAnalyzer
       ├─ Yii2FindShortcutAnalyzer
       ├─ Yii2DeprecationAnalyzer
       ├─ Yii2MagicPropertyAnalyzer
       └─ Yii2ControllerAccessAnalyzer
                │
                ↓
          Yii2RuleEngine
                │
                ↓
            Finding[]
```

Essa camada não substitui PHPStan/Psalm/SAST e não deve ser confundida com `SemanticHints`: o modelo Yii2 deriva fatos do código/Composer; `SemanticHints` continua documental.

### `src/Yii2SemanticModel.php`

Constrói snapshot conservador somente para `profile=yii2`. Detecta capabilities Redis/MongoDB, controllers/actions, referências literais a views e getters `hasOne()`/`hasMany()` com target literal.

Referências calculadas, aliases sem resolução segura e dependência de runtime permanecem unknown.

### `src/Yii2BehaviorActionAnalyzer.php`

Valida referências literais em `behaviors()` para `only`, `except`, `optional`, `AccessControl.rules[].actions` e `VerbFilter.actions`. Herança local é percorrida; parent customizado externo/composição dinâmica degrada para unknown.

### `src/Yii2RelationReferenceAnalyzer.php`

Valida relation paths literais em `with()`, `joinWith()` e `innerJoinWith()`, inclusive paths pontuados quando cada target intermediário é conclusivo.

### `src/Yii2RelationLinkAnalyzer.php`

Valida related/current de links literais `hasOne()/hasMany()` somente quando `attributes()` fornece inventário estático completo. Schema runtime, PHPDoc isolado e `via()/viaTable()` não são usados como prova negativa.

### `src/Yii2QueryConditionAnalyzer.php`

Valida aridade de operators em condition arrays literais de `where()/andWhere()/orWhere()` iniciados por ActiveRecord local comprovado. Conjunctions literais podem ser percorridas recursivamente. Hash conditions, spread, variáveis e receivers incertos ficam fora.

### `src/Yii2QueryExistenceAnalyzer.php`

Detecta `one()`/`count()` usados apenas para testar existência e produz remediation `exists()`/`!exists()`. A regra é performance/REVIEW e não participa do autofix.

### `src/Yii2FindShortcutAnalyzer.php`

Detecta somente a equivalência segura:

```text
ActiveRecord::find()->where(hash literal string-keyed)->one()
  -> ActiveRecord::findOne(hash)

ActiveRecord::find()->where(hash literal string-keyed)->all()
  -> ActiveRecord::findAll(hash)
```

O analyzer fornece offset/length/replacement exatos para remediação SAFE. List/operator format/empty array/spread/dynamic condition são preservados.

### `src/Yii2DeprecationAnalyzer.php`

Detecta depreciações mecânicas:

```text
Yii::trace() -> Yii::debug()
Controller::EXIT_CODE_* -> ExitCode::*
return 0/1 em console action -> ExitCode::*
```

As regras que dependem de tipo só são emitidas quando a herança/classe pode ser resolvida estaticamente.

### `src/Yii2MagicPropertyAnalyzer.php`

Verifica property tags de relations apenas quando a classe já mantém contrato `@property*`. Não autoedita PHPDoc e ignora classes sem essa convenção, magic accessors customizados e property pública nativa de mesmo nome.

### `src/Yii2ControllerAccessAnalyzer.php`

Detecta `Yii::$app->request`/`response` dentro de controller comprovado e sugere `$this->request`/`response`. É architecture advisory/REVIEW, não correctness/security e não possui autofix.

### `src/Yii2RuleEngine.php`

Converte evidências em `Finding` sob IDs próprios:

```text
COR-001  view ausente
COR-002  action de behavior/filter ausente
COR-003  relation path ausente
COR-004  atributo inválido em link de relation
COR-005  aridade inválida em query condition
PERF-001 redundant existence check
MOD-001  findOne/findAll shortcut seguro
DEP-001  Yii::trace deprecated
DEP-002  exit constant deprecated
DEP-003  magic exit literal em console action
TYPE-001 magic relation property ausente no PHPDoc já adotado
ARCH-001 Yii::$app request/response dentro de controller
```

A engine preserva categoria e risco em metadata. Architecture/performance não são vulnerabilidades por associação.

## Remediação nativa Yii2

### `src/Yii2SafeRemediator.php`

Agrega apenas analyzers SAFE. O contrato é:

1. analyzer prova equivalência e fornece `file`, `offset`, `length`, `replacement`;
2. remediator agrupa patches por arquivo;
3. patches sobrepostos são rejeitados;
4. aplicação ocorre por offset decrescente;
5. arquivos sem mudança não são regravados;
6. segunda execução precisa ser idempotente.

SAFE atual:

```text
Yii2DeprecationAnalyzer
Yii2FindShortcutAnalyzer
```

REVIEW/SEMANTIC não entram nessa classe.

## Assist Yii2

`scripts/ninfa-configure.php --assist` executa PHPStan/Psalm estruturados, constrói `Yii2SemanticModel`, executa `Yii2RuleEngine` e grava:

```text
assist/yii2-semantic.json
assist/yii2-findings.json
assist/findings.json
```

As regras nativas permanecem em field test no `assist` antes de eventual promoção seletiva para `check`.

## Segurança

### `src/SecurityContract.php`

Resolve os doze contratos canônicos do profile. Baseline/overlay:

```text
php-generic  common
yii2        common + yii2
yii3        common + yii3
glpi-plugin common + glpi-plugin-11
```

Correctness, performance, modernization, deprecation, PHPDoc e architecture advisory do rule engine Yii2 não migram automaticamente para SAST.

### `src/SemgrepParser.php`

Normaliza Semgrep e diferencia scanned/skipped, exclusão deliberada, skip inesperado e erro do mecanismo. `ERROR` é bloqueante; `WARNING` é hotspot.

### `src/PsalmTaintParser.php`

Normaliza findings de dataflow do Psalm preservando trace quando disponível.

## SCA

### `src/SecurityInventory.php`

Usa `composer.lock` como fonte primária de versões e `vendor/composer/installed.json` como fallback quando apropriado. Preserva direct/transitive, runtime/dev, PHP runtime/constraint e contexto do profile.

### `src/ComposerAuditParser.php`

Normaliza advisories do Composer; pacotes abandonados são política de dependência, não vulnerabilidade.

### `src/OsvClient.php`

Consulta OSV em batch e produz findings correlacionados ao inventário.

### `src/ScaFindingDeduplicator.php`

Agrupa aliases CVE/GHSA/PKSA/OSV mantendo proveniência.

### `src/SecurityReport.php`

Persiste inventário, fontes, findings, vulnerabilidades deduplicadas e exit final.

## Testes internos

`make profile-test` inclui contratos gerais e a suíte Yii2:

```text
tests/yii2-semantic-model.php
tests/yii2-query-existence.php
tests/yii2-query-condition.php
tests/yii2-deprecation-remediation.php
tests/yii2-find-shortcut.php
tests/yii2-magic-property.php
tests/yii2-controller-access.php
```

Remediações SAFE possuem teste de idempotência. Casos dinâmicos/incertos devem aparecer como negative/unknown, nunca ser removidos das fixtures para “fazer o teste passar”.

## Estado atual

O Ninfa possui quatro profiles PHP oficiais, SCA/SAST estruturado e uma camada Yii2 nativa em expansão. A estratégia continua sendo absorver conhecimento útil do ecossistema sem transformar o produto em wrapper de regras externas.

A promoção de regras nativas para `check` e a ampliação de autofix dependem de evidência de baixo falso positivo em projetos Yii2 reais. A documentação canônica da especialização está em `docs/YII2-ANALYSIS.md`.
