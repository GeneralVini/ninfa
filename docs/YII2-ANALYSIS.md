# Análise semântica Yii2

Este documento é o contrato técnico do profile `yii2` do Ninfa para análise específica de framework. Ele complementa `README.md`, `INTERNAL-ARCHITECTURE.md` e a documentação de segurança sem transformar preferência arquitetural, performance ou modernização em vulnerabilidade.

## Objetivo

O Ninfa combina três fontes de conhecimento:

1. análise genérica fornecida por PHPStan, Psalm, Rector, ECS e SAST;
2. semântica própria do Yii2 necessária para interpretar convenções que ferramentas genéricas não conseguem provar sozinhas;
3. boas práticas observadas em `mspirkov/yii2-phpstan-rules` e `mspirkov/yii2-rector`, reimplementadas sob os contratos de findings, workspace, documentação e remediação do Ninfa.

A incorporação é conceitual. O contrato público pertence ao Ninfa. Código upstream só pode ser adaptado literalmente com preservação de copyright/licença conforme `THIRD_PARTY_NOTICES.md`.

## Princípios

- `check`, `security` e `assist` não modificam o consumidor;
- `fix` só modifica o consumidor por regras explicitamente classificadas como SAFE ou por fixers já homologados;
- fatos semânticos e política de findings permanecem separados;
- referência dinâmica ou tipo não comprovável é `unknown`, não erro presumido;
- correctness, security, architecture, performance, modernization, deprecation e static-analysis são categorias diferentes;
- opinião arquitetural não é promovida a vulnerabilidade;
- Redis e MongoDB são capabilities do profile Yii2, não novos profiles;
- documentação, PHPDoc, fixture e regra fazem parte da mesma mudança;
- ausência só é afirmada quando existe evidência estática suficiente;
- toda remediação SAFE precisa de teste de idempotência.

## Modelo semântico compartilhado

`src/Yii2SemanticModel.php` coleta fatos e não decide severidade. O snapshot inclui:

```text
Yii2SemanticModel
├── capabilities
│   ├── redis
│   └── mongodb
├── controllers
│   ├── class
│   ├── id
│   ├── actions
│   └── literal view references
└── relations
    ├── model
    ├── getter/relation name
    ├── hasOne|hasMany
    └── literal target class
```

O modo normal de `scripts/ninfa-configure.php` persiste esse snapshot em `semantic-index.json`, sob `framework_semantics`. Em `ninfa assist`, o snapshot também é preservado em `assist/yii2-semantic.json`.

`SemanticHints` continua separado: documentação do consumidor pode enriquecer contexto, mas não é usada como prova de AST, reachability, schema ou relação.

## Regras de correctness

### COR-001 — view literal inexistente

`NINFA-YII2-COR-001` cobre referências literais resolvíveis pela convenção estática `controllers/` → `views/<controller-id>/` em `render()`, `renderPartial()` e `renderAjax()`.

O finding só existe quando o path foi resolvido e o arquivo foi comprovadamente ausente. Alias, `renderFile()`, nomes calculados, paths absolutos e resolução dependente de runtime não são negados.

### COR-002 — action inexistente em behaviors/filters

`src/Yii2BehaviorActionAnalyzer.php` tokeniza configurações estáticas de `behaviors()` sem executar PHP do consumidor. A cobertura atual inclui:

```text
ActionFilter.only
ActionFilter.except
AuthMethod.optional
AccessControl.rules[].actions
VerbFilter.actions (chaves)
```

A existência é tri-state:

```text
true   action conhecida no controller ou parent local
false  inventário conclusivo e action ausente
null   herança/composição dinâmica impede provar ausência
```

Wildcards, classe de behavior dinâmica, `parent::actions()`, spreads, `array_merge()` e retornos indiretos degradam para `unknown`.

### COR-003 — relation path inexistente em ActiveQuery

`src/Yii2RelationReferenceAnalyzer.php` valida relation paths literais em:

```text
Model::find()->with('relation')
Model::find()->joinWith('relation')
Model::find()->innerJoinWith('relation')
```

Paths pontuados são percorridos quando os targets intermediários também são ActiveRecord locais conclusivos. Alias literal de `joinWith`, como `items item`, é normalizado somente para lookup.

Parent externo desconhecido, trait capaz de introduzir getter, getter existente mas não classificável como `hasOne()/hasMany()`, target intermediário incerto, arrays, closures e relation paths dinâmicas permanecem `unknown`.

### COR-004 — atributos de links `hasOne()`/`hasMany()`

`src/Yii2RelationLinkAnalyzer.php` valida os dois lados de um link literal:

```php
$this->hasOne(Customer::class, [
    'id' => 'customer_id',
]);
```

A chave pertence ao ActiveRecord relacionado e o valor ao ActiveRecord atual. Cada lado é avaliado separadamente.

Um inventário de atributos só é conclusivo quando `attributes()` retorna lista literal completa ou herda esse contrato de parent local igualmente conclusivo. Não são usados como prova negativa: propriedade PHP ausente, PHPDoc isolado, migration sem correlação inequívoca, schema runtime, `parent::attributes()`, `array_merge()`, variáveis/condicionais, parent externo, trait incerta, link dinâmico ou relação via `via()/viaTable()`.

### COR-005 — aridade de operadores em conditions

`src/Yii2QueryConditionAnalyzer.php` valida conditions array literais em chains comprovadas de ActiveRecord:

```php
Order::find()->where(['=', 'status', 1]);      // válido
Order::find()->where(['=', 'status']);         // inválido
Order::find()->where(['between', 'age', 18]);  // inválido
```

A tranche atual cobre `where()`, `andWhere()` e `orWhere()` iniciados por `ActiveRecord::find()` local comprovável. Conditions `AND`, `OR` e `NOT` podem ser validadas recursivamente quando seus operands são arrays literais.

Requisitos atuais:

| Operador | Aridade |
| --- | --- |
| `AND`, `OR` | ao menos 1 |
| `NOT` | exatamente 1 |
| `BETWEEN`, `NOT BETWEEN` | ao menos 3 |
| `IN`, `NOT IN` | ao menos 2 |
| `LIKE`, `NOT LIKE`, `OR LIKE`, `OR NOT LIKE` | ao menos 2 |
| `EXISTS`, `NOT EXISTS` | ao menos 1 |
| `=`, `!=`, `<>`, `>`, `>=`, `<`, `<=` | exatamente 2 |

Hash conditions, spread, variáveis e receivers sem tipo demonstrável ficam fora da negação.

## Performance

### PERF-001 — existência via `one()`/`count()`

`src/Yii2QueryExistenceAnalyzer.php` identifica query mais cara usada apenas para obter booleano:

```php
Order::find()->where(['status' => 1])->one() !== null;
Order::find()->where(['status' => 1])->count() > 0;
```

A orientação é `exists()`/`!exists()`. São reconhecidas as equivalências estritas/relacionais contra `null`, `0` e `1`, inclusive com operands invertidos.

A regra não reporta quando o valor de `one()` ou `count()` é realmente consumido, quando o threshold não representa existência ou quando o tipo da query não pode ser provado.

Contrato:

```text
category          performance
severity          warning
confidence        high
remediation_risk  review
autofix           false
```

A classificação REVIEW é deliberada: o upstream possui uma transformação Rector, mas o Ninfa ainda mantém essa mudança fora do SAFE enquanto amplia fixtures e field tests.

## Modernization SAFE

### MOD-001 — `find()->where(hash)->one/all` para `findOne/findAll`

`src/Yii2FindShortcutAnalyzer.php` incorpora uma transformação do `yii2-rector` apenas no subconjunto cuja equivalência é demonstrável:

```php
Order::find()->where(['status' => 1])->one();
// -> Order::findOne(['status' => 1]);

Order::find()->where(['status' => 1])->all();
// -> Order::findAll(['status' => 1]);
```

A condition precisa ser array associativo literal não vazio e todas as chaves top-level precisam ser strings literais. São intencionalmente preservados:

```text
listas
operator format
arrays vazios
spread
variáveis
Expression
classe não comprovada como ActiveRecord
```

`NINFA-YII2-MOD-001` é warning de modernization com `remediation_risk=safe` e `autofix=true`.

## Deprecations SAFE

`src/Yii2DeprecationAnalyzer.php` produz patches exatos para a primeira família de depreciações mecânicas:

```text
NINFA-YII2-DEP-001  Yii::trace() -> Yii::debug()
NINFA-YII2-DEP-002  Controller::EXIT_CODE_* -> yii\console\ExitCode::*
NINFA-YII2-DEP-003  return 0/1 em console action -> ExitCode::*
```

`DEP-002` só atua quando a classe usada na constante resolve estaticamente para `yii\console\Controller`. `DEP-003` só atua em action de classe cuja herança local termina em controller console Yii2 conhecido.

Esses findings usam:

```text
category          deprecation
severity          warning
confidence        high
remediation_risk  safe
autofix           true
```

## PHPDoc e static analysis

### TYPE-001 — propriedade mágica de relation não documentada

`src/Yii2MagicPropertyAnalyzer.php` trata PHPDoc como contrato semântico, não cosmético. A regra só entra em classes que **já mantêm** alguma tag `@property`, `@property-read` ou `@property-write`.

Se uma relation literal conhecida não possui tag correspondente, a regra sugere, por exemplo:

```text
@property-read \app\models\Customer|null $customer
@property-read \app\models\Item[] $items
```

A primeira tranche verifica presença, não tenta reescrever tipos existentes. Classes sem convenção de property tags, com `__get/__set` customizado ou com propriedade pública nativa de mesmo nome são ignoradas.

Contrato:

```text
category          static-analysis
severity          warning
confidence        high
remediation_risk  semantic
autofix           false
```

O `fix` não altera PHPDoc semanticamente sensível.

## Architecture advisory

### ARCH-001 — request/response global dentro de controller

`src/Yii2ControllerAccessAnalyzer.php` detecta, somente em controllers Yii2 comprovados:

```php
Yii::$app->request
Yii::$app->response
```

O controller já expõe os mesmos objetos por:

```php
$this->request
$this->response
```

A regra é inspirada em `ReplaceAppRequestResponseWithThisRector`, mas o Ninfa a mantém como advisory:

```text
category          architecture
severity          warning
confidence        high
remediation_risk  review
autofix           false
```

Ela não é security nem correctness. Helper, service ou classe cuja herança de controller não possa ser provada não recebe finding.

## Engine de regras nativas

A separação de responsabilidades é:

```text
Yii2SemanticModel ----------------┐
Yii2BehaviorActionAnalyzer -------┤
Yii2RelationReferenceAnalyzer ----┤
Yii2RelationLinkAnalyzer ---------┤
Yii2QueryConditionAnalyzer -------┤
Yii2QueryExistenceAnalyzer -------┤
Yii2FindShortcutAnalyzer ---------┤
Yii2DeprecationAnalyzer ----------┼-> Yii2RuleEngine -> Finding[]
Yii2MagicPropertyAnalyzer --------┤
Yii2ControllerAccessAnalyzer -----┘
```

Catálogo atual:

| ID | Categoria | Estado / política |
| --- | --- | --- |
| `NINFA-YII2-COR-001` | correctness | `assist`, error |
| `NINFA-YII2-COR-002` | correctness | `assist`, error |
| `NINFA-YII2-COR-003` | correctness | `assist`, error |
| `NINFA-YII2-COR-004` | correctness | `assist`, error |
| `NINFA-YII2-COR-005` | correctness | `assist`, error |
| `NINFA-YII2-PERF-001` | performance | `assist`, warning, REVIEW |
| `NINFA-YII2-MOD-001` | modernization | `assist` + `fix`, warning, SAFE |
| `NINFA-YII2-DEP-001` | deprecation | `assist` + `fix`, warning, SAFE |
| `NINFA-YII2-DEP-002` | deprecation | `assist` + `fix`, warning, SAFE |
| `NINFA-YII2-DEP-003` | deprecation | `assist` + `fix`, warning, SAFE |
| `NINFA-YII2-TYPE-001` | static-analysis | `assist`, warning, SEMANTIC |
| `NINFA-YII2-ARCH-001` | architecture | `assist`, warning, REVIEW |

Todos usam `Finding`; não existe schema paralelo para Yii2.

## Remediação SAFE

`src/Yii2SafeRemediator.php` é a única camada nativa autorizada atualmente a alterar código Yii2 diretamente. Ela recebe patches exatos dos analyzers SAFE, agrupa por arquivo, rejeita intervalos sobrepostos e aplica do maior offset para o menor.

Atualmente consome:

```text
Yii2DeprecationAnalyzer
Yii2FindShortcutAnalyzer
```

A operação é idempotente: uma segunda execução sobre o resultado já corrigido precisa produzir zero alterações.

No CLI, `ninfa fix` executa a remediação SAFE Yii2 antes dos fixers externos. Depois o fluxo normal de `RecheckingPipelineRunner` preserva o contrato já existente de **um único `check` final**. A remediação nativa não é representada como pseudo-binário em `PipelinePlan`.

## Capabilities Redis e MongoDB

A presença de `yiisoft/yii2-redis` e `yiisoft/yii2-mongodb` é registrada como capability, mantendo `profile=yii2`. Analyzers que comprovam herança reconhecem bases ActiveRecord de DB, Redis e MongoDB quando aplicável.

Regras de schema continuam conservadoras quando os atributos só podem ser determinados em runtime.

## PHPDoc do próprio Ninfa

Código novo continua sujeito a `tests/internal-docs.php`:

- classe com PHPDoc narrativo;
- todo método/função nomeada documentado, inclusive private;
- arrays com generics/shapes quando conhecidos;
- acumuladores não triviais com `@var`;
- comentários locais de decisão/invariante em fluxo relevante;
- I/O e mutações explicados junto do código responsável.

Docblock que apenas repete assinatura não satisfaz o objetivo. O guard documental não deve ser relaxado para acomodar nova regra.

## Testes

Toda regra precisa de true positive, true negative, caso limite e caso não resolvível. Remediação SAFE também exige idempotência.

A suíte dedicada inclui:

```text
tests/yii2-semantic-model.php
tests/yii2-query-existence.php
tests/yii2-query-condition.php
tests/yii2-deprecation-remediation.php
tests/yii2-find-shortcut.php
tests/yii2-magic-property.php
tests/yii2-controller-access.php
```

Todos integram `make profile-test`.

## Relação com os comandos

### `ninfa check`

As regras nativas Yii2 continuam fora do gate principal enquanto correctness/advisories são calibrados em projetos reais. O check final após `fix` continua sendo a validação global do resultado.

### `ninfa assist`

É a superfície principal dos findings nativos e preserva:

```text
assist/yii2-semantic.json
assist/yii2-findings.json
assist/findings.json
```

### `ninfa fix`

Executa somente remediações nativas marcadas SAFE e os fixers externos existentes. Atualmente são SAFE nativas: `DEP-001..003` e `MOD-001`.

`COR-*`, `PERF-001`, `TYPE-001` e `ARCH-001` não são reescritos automaticamente.

### `ninfa security`

Continua reservado a SCA/SAST. Performance, modernization, deprecation, PHPDoc e architecture advisory não são tratados como vulnerabilidade.

## Proveniência

As regras e transformações são inspiradas em ideias dos projetos:

- `mspirkov/yii2-phpstan-rules`;
- `mspirkov/yii2-rector`.

O Ninfa não reproduz PHPStan Reflection nem acopla IDs públicos ao upstream. Quando uma regra upstream depende de type inference mais forte, a implementação nativa reduz cobertura em vez de inferir tipo incorreto.

As regras arquiteturais do upstream são tratadas como opiniões úteis/advisories por padrão. Só passam a correctness/security quando houver contrato objetivo e evidência apropriada.

## Continuidade

A evolução seguinte prioriza:

1. ampliar field tests das regras já implementadas;
2. modernizações/deprecations adicionais somente quando a equivalência puder ser provada e testada;
3. ampliar query analysis com tipo demonstrável, sem substituir PHPStan Reflection por heurística frágil;
4. aprofundar PHPDoc/magic properties mantendo `SEMANTIC` sem autofix;
5. hardening Redis/MongoDB e inventário de schema apenas com fonte forte e auditável;
6. promover correctness ao `check` somente após calibração de falso positivo.
