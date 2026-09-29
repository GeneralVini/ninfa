# Análise semântica Yii2

Este documento é o contrato técnico do profile `yii2` do Ninfa para análise específica de framework. Ele complementa `README.md`, `INTERNAL-ARCHITECTURE.md` e a documentação de segurança sem transformar preferências arquiteturais em vulnerabilidades.

## Objetivo

O Ninfa combina três fontes de conhecimento:

1. análise genérica já fornecida por PHPStan, Psalm, Rector, ECS e SAST;
2. semântica própria do Yii2 necessária para interpretar convenções que ferramentas genéricas não conseguem provar sozinhas;
3. boas práticas observadas nos projetos `mspirkov/yii2-phpstan-rules` e `mspirkov/yii2-rector`, reimplementadas sob os contratos de findings, workspace e remediação do Ninfa.

A incorporação é conceitual. O contrato público pertence ao Ninfa. Código upstream só pode ser adaptado literalmente com preservação de copyright/licença conforme `THIRD_PARTY_NOTICES.md`.

## Princípios

- o consumidor não é modificado durante `check`, `security` ou `assist`;
- fatos semânticos e política de findings são responsabilidades separadas;
- referências dinâmicas não comprováveis são `unknown`, não erro presumido;
- correctness, architecture, performance, deprecation e security são categorias diferentes;
- preferência arquitetural não é promovida a vulnerabilidade;
- Redis e MongoDB são capabilities do profile Yii2, não novos profiles;
- documentação, PHPDoc, fixture e regra fazem parte da mesma entrega;
- ausência só é afirmada quando existe evidência estática suficiente.

## Modelo semântico compartilhado

`src/Yii2SemanticModel.php` coleta fatos e não decide severidade. O snapshot atual inclui:

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

## COR-001 — view literal inexistente

`NINFA-YII2-COR-001` cobre referências literais resolvíveis pela convenção estática `controllers/` → `views/<controller-id>/` em chamadas como `render()`, `renderPartial()` e `renderAjax()`.

O Ninfa só produz finding quando o path pôde ser resolvido e o arquivo foi comprovadamente ausente. Alias, `renderFile()`, nomes calculados, paths absolutos e resoluções dependentes de runtime permanecem fora da negação.

## COR-002 — action inexistente em behaviors/filters

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

`NINFA-YII2-COR-002` só é emitida para `exists=false`.

## COR-003 — relation path inexistente em ActiveQuery

`src/Yii2RelationReferenceAnalyzer.php` valida relation paths literais usadas em:

```text
Model::find()->with('relation')
Model::find()->joinWith('relation')
Model::find()->innerJoinWith('relation')
```

Paths pontuados são percorridos segmento a segmento quando os targets intermediários também são ActiveRecord locais conclusivos. Alias literal de `joinWith`, como `items item`, é normalizado somente para lookup.

Parent externo desconhecido, trait que possa introduzir getter, getter existente mas não classificável como `hasOne()/hasMany()`, target intermediário incerto, arrays, closures e relation paths dinâmicas permanecem `unknown`.

`NINFA-YII2-COR-003` só é emitida quando o segmento ausente pode ser provado.

## COR-004 — atributos de links `hasOne()`/`hasMany()`

`src/Yii2RelationLinkAnalyzer.php` valida os dois lados de um link literal:

```php
$this->hasOne(Customer::class, [
    'id' => 'customer_id',
]);
```

A chave pertence ao ActiveRecord relacionado e o valor ao ActiveRecord atual. Cada lado é avaliado separadamente.

Um inventário de atributos só é considerado conclusivo quando a classe declara:

```php
public function attributes(): array
{
    return ['id', 'customer_id'];
}
```

ou herda esse contrato literal de parent local igualmente conclusivo.

Não são usados como prova negativa:

- ausência de propriedade PHP;
- PHPDoc `@property` isolado;
- migrations sem correlação inequívoca com o model;
- schema disponível apenas em runtime;
- `parent::attributes()`, `array_merge()`, variáveis ou condicionais;
- parent externo/desconhecido;
- trait sem override literal conclusivo;
- links dinâmicos;
- relações seguidas por `via()` ou `viaTable()`.

`NINFA-YII2-COR-004` produz um finding por lado comprovadamente inválido e nunca tenta escolher automaticamente a coluna correta.

## PERF-001 — existência via `one()`/`count()`

`src/Yii2QueryExistenceAnalyzer.php` incorpora o conceito de "no redundant existence check" de forma nativa e conservadora.

O problema é usar uma query mais cara apenas para obter um booleano:

```php
Order::find()->where(['status' => 1])->one() !== null;
Order::find()->where(['status' => 1])->count() > 0;
```

Quando a intenção é apenas existência, o contrato preferido é:

```php
Order::find()->where(['status' => 1])->exists();
```

Para ausência:

```php
!Order::find()->where(['status' => 1])->exists();
```

A primeira tranche reconhece somente chains `ActiveRecord::find()` cuja classe local pode ser comprovada como descendente de:

```text
yii\db\ActiveRecord
yii\db\BaseActiveRecord
yii\redis\ActiveRecord
yii\mongodb\ActiveRecord
```

As comparações equivalentes atualmente reconhecidas são:

```text
one() !== null   -> exists()
one() === null   -> !exists()
count() !== 0    -> exists()
count() === 0    -> !exists()
count() > 0      -> exists()
count() <= 0     -> !exists()
count() >= 1     -> exists()
count() < 1      -> !exists()
```

Formas com operandos invertidos, como `0 < Query::count()` ou `null === Query::one()`, são normalizadas para a mesma semântica.

A regra **não** reporta:

```php
$total = Order::find()->count();
$row = Order::find()->one();
Order::find()->count() > 1;
```

Também ficam fora da tranche variáveis de query cujo tipo não pode ser provado sem reflection, factories customizadas e expressões dinâmicas. Essa limitação é intencional: o upstream usa PHPStan Reflection para reconhecer qualquer `QueryInterface`; o Ninfa nativo prefere cobertura menor a inferir um tipo incorreto.

`NINFA-YII2-PERF-001` usa:

```text
category      performance
severity      warning
confidence    high
autofix       false
remediation   exists()/!exists()
risk          review
```

Ela permanece em `assist` durante field test. Não é vulnerabilidade, não entra automaticamente em `security` e não é reescrita por `fix` nesta fase.

## Engine de regras nativas

A separação de responsabilidades é:

```text
Yii2SemanticModel ---------------┐
Yii2BehaviorActionAnalyzer ------┤
Yii2RelationReferenceAnalyzer ---┤
Yii2RelationLinkAnalyzer --------┼-> Yii2RuleEngine -> Finding[]
Yii2QueryExistenceAnalyzer ------┘
```

Catálogo atual:

| ID | Categoria | Evidência | Estado |
| --- | --- | --- | --- |
| `NINFA-YII2-COR-001` | correctness | view literal resolvida ausente | `assist` |
| `NINFA-YII2-COR-002` | correctness | action estática ausente com inventário completo | `assist` |
| `NINFA-YII2-COR-003` | correctness | relation path literal ausente | `assist` |
| `NINFA-YII2-COR-004` | correctness | atributo de link ausente com `attributes()` conclusivo | `assist` |
| `NINFA-YII2-PERF-001` | performance | `one/count` usado apenas como booleano | `assist`, warning |

Todos usam o contrato comum `Finding`; não existe schema paralelo para Yii2.

## Taxonomia e severidade

| Família | Exemplo | Política |
| --- | --- | --- |
| correctness | `NINFA-YII2-COR-*` | error quando a ausência é conclusiva |
| security | `NINFA-YII2-SEC-*` | somente com contrato/evidência SAST |
| architecture | `NINFA-YII2-ARCH-*` | advisory salvo política explícita |
| performance | `NINFA-YII2-PERF-*` | warning/advisory |
| deprecation | `NINFA-YII2-DEP-*` | candidato a remediation |
| static-analysis | `NINFA-YII2-TYPE-*` | check/assist |

Performance, arquitetura, deprecation e PHPDoc não são chamadas de vulnerabilidade apenas por terem sido encontradas pelo profile Yii2.

## Capabilities Redis e MongoDB

A presença de:

```text
yiisoft/yii2-redis
yiisoft/yii2-mongodb
```

é registrada como capability, mantendo `profile=yii2`. Isso evita combinações artificiais de profile.

`PERF-001` reconhece herança local que termina em `yii\redis\ActiveRecord` ou `yii\mongodb\ActiveRecord`. Regras de schema continuam conservadoras quando os atributos só podem ser determinados em runtime.

## Remediação

Remediações são classificadas por risco.

### SAFE

Candidatas futuras ao `ninfa fix` quando equivalência for validada e houver teste de idempotência:

```text
Yii::trace() -> Yii::debug()
Cache::mget() -> Cache::multiGet()
Cache::mset() -> Cache::multiSet()
Cache::madd() -> Cache::multiAdd()
Dependency::getHasChanged() -> Dependency::isChanged()
constants antigos de exit code -> ExitCode::*
```

### REVIEW

Mudanças que parecem mecânicas mas ainda merecem confirmação contextual. `PERF-001` começa aqui: o finding informa `exists()`/`!exists()`, mas não altera o consumidor nesta fase.

### SEMANTIC

PHPDoc mágico, propriedades inferidas, alterações estruturais e transformações cujo significado depende do domínio permanecem inicialmente em `assist`.

## PHPDoc do próprio Ninfa

Código novo continua sujeito a `tests/internal-docs.php`:

- classe com PHPDoc narrativo;
- todo método/função nomeada documentado, inclusive private;
- arrays com generics/shapes quando conhecidos;
- acumuladores não triviais com `@var`;
- comentários locais de decisão/invariante em fluxo relevante;
- I/O e mutações explicados junto do código responsável.

`Yii2QueryExistenceAnalyzer` segue o mesmo contrato: o PHPDoc declara o subconjunto suportado, as bases ActiveRecord reconhecidas, o shape retornado e por que tipos incertos são ignorados.

## PHPDoc do consumidor

`@property`, `@property-read` e `@property-write` podem representar APIs reais do Yii2 e influenciam PHPStan/Psalm/IDE. Por isso:

```text
check  -> pode detectar inconsistência
assist -> pode explicar/propor patch
fix    -> não altera PHPDoc semanticamente sensível nesta fase
```

PHPDoc isolado não substitui schema de banco para `COR-004`.

## Testes

Toda nova regra deve conter no mínimo true positive, true negative, caso limite e caso não resolvível.

A suíte atual possui:

```text
tests/yii2-semantic-model.php
  COR-001..COR-004
  Redis/MongoDB capabilities
  views/actions/relations/links
  casos dynamic/unknown

tests/yii2-query-existence.php
  PERF-001
  one() vs null
  count() vs 0/1
  operandos invertidos
  herança ActiveRecord local
  count/one usados como valor não reportados
  classe não-ActiveRecord ignorada
```

Ambos integram `make profile-test`.

## Relação com os comandos

### `ninfa check`

As regras nativas Yii2 ainda não foram promovidas ao gate principal. Promoção depende de field tests em projetos reais e calibração de falso positivo.

### `ninfa assist`

É a primeira superfície das regras nativas. Preserva:

```text
assist/yii2-semantic.json
assist/yii2-findings.json
assist/findings.json
```

### `ninfa fix`

Nenhuma das regras `COR-*` ou `PERF-001` possui autofix nesta fase.

### `ninfa security`

Continua reservado a SCA/SAST. `PERF-001` permanece performance, não segurança.

## Proveniência

A validação de views/actions/relations/queries é inspirada em ideias dos projetos:

- `mspirkov/yii2-phpstan-rules`;
- `mspirkov/yii2-rector`.

A implementação do Ninfa não reproduz PHPStan Reflection nem copia o contrato interno do upstream. Em particular, a regra upstream de existência consegue reconhecer expressões tipadas como `yii\db\QueryInterface`/`ActiveQueryInterface`; `PERF-001` nativa limita-se inicialmente a `ActiveRecord::find()` local comprovável.

## Próximas tranches

1. field test de `COR-001` a `COR-004` e `PERF-001` em projetos Yii2 reais;
2. decidir quais correctness podem ser promovidas a `check`;
3. ampliar query analysis apenas quando o tipo puder ser provado sem elevar falso positivo;
4. implementar remediações SAFE inspiradas em `yii2-rector`;
5. adicionar análise de PHPDoc/magic properties em `check/assist` antes de qualquer autofix;
6. hardening de Redis/MongoDB e inventário de schema somente com fonte forte/auditável.
