# Análise semântica Yii2

Este documento é o contrato técnico do profile `yii2` do Ninfa para análise específica de framework. Ele complementa `README.md`, `INTERNAL-ARCHITECTURE.md` e a documentação de segurança sem transformar preferência arquitetural, performance ou modernização em vulnerabilidade.

## Objetivo

O Ninfa combina três fontes de conhecimento:

1. análise genérica fornecida por PHPStan, Psalm, Rector, ECS e SAST;
2. semântica própria do Yii2 necessária para interpretar convenções que ferramentas genéricas não conseguem provar sozinhas;
3. boas práticas observadas em `mspirkov/yii2-phpstan-rules` e `mspirkov/yii2-rector`, reimplementadas sob os contratos de findings, workspace, documentação e remediação do Ninfa.

A incorporação é conceitual. O contrato público pertence ao Ninfa. Código upstream só pode ser adaptado literalmente com preservação de copyright/licença conforme `THIRD_PARTY_NOTICES.md`.

A decisão de incorporar, adiar ou restringir cada conceito upstream é registrada em `YII2-UPSTREAM-RULE-MATRIX.md`.

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

`Yii2ModelRulesAnalyzer` adiciona um inventário reutilizável de atributos de Model para rules/scenarios/labels/forms sem transformar schema runtime em fato. Nesta tranche ele é calculado sob demanda pelos analyzers e ainda não amplia o schema persistido de `Yii2SemanticModel`.

`SemanticHints` continua separado: documentação do consumidor pode enriquecer contexto, mas não é usada como prova de AST, reachability, schema ou relação.

## Regras de correctness

### COR-001 — view literal inexistente

`NINFA-YII2-COR-001` usa `src/Yii2ViewReferenceAnalyzer.php` para resolver somente referências literais cuja origem e path podem ser demonstrados sem executar o consumidor.

A cobertura inclui:

```text
Controller::$this->render()/renderPartial()/renderAjax()
$this->render() em arquivos sob views/Views
Yii::$app->view->render()
Yii::$app->getView()->render()
```

Para controllers, a convenção física `controllers|Controllers` → `views|Views` preserva subdiretórios e o controller ID. Em nested views, nomes relativos são resolvidos a partir do diretório da view atual e nomes iniciados por `/` usam a raiz de views daquele contexto.

Aliases e view paths customizados exigem configuração externa explícita, evitando inferir configuração runtime:

```bash
export NINFA_YII2_VIEW_ALIASES_JSON='{"@app":"/srv/app/frontend","@shared":"/srv/app/shared/views"}'
export NINFA_YII2_VIEW_PATHS_JSON='{"App\\Frontend\\Controllers":"/srv/app/resources/frontend/views"}'
export NINFA_YII2_VIEW_EXTENSIONS='php,twig'
```

Paths relativos nessas variáveis são ancorados na raiz do consumidor. `//...` só é resolvido quando `@app` foi explicitamente configurado. Alias desconhecido, receiver arbitrário, string calculada, traversal e contexto relativo substituído por terceiro argumento permanecem `unknown`.

`renderFile()` permanece fora de `COR-001`: seu contrato é path de arquivo, não nome de view, e será tratado separadamente se houver ganho real.

Contrato:

```text
category          correctness
severity          error
confidence        high
autofix           false
```

### COR-002 — action inexistente em behaviors/filters

`src/Yii2BehaviorActionAnalyzer.php` tokeniza configurações estáticas de `behaviors()` sem executar PHP do consumidor. A cobertura atual inclui:

```text
ActionFilter.only
ActionFilter.except
AuthMethod.optional
AccessControl.rules[].actions
VerbFilter.actions (chaves)
```

Além das classes oficiais, o analyzer percorre herança **local e demonstrável** para reconhecer subclasses customizadas de `ActionFilter`, `AuthMethod`, `AccessControl`, `VerbFilter` e `AccessRule`. A cadeia é resolvida apenas a partir de classes presentes nos paths analisáveis; parent externo desconhecido não é presumido como filter Yii2.

A existência é tri-state:

```text
true   action conhecida no controller ou parent local
false  inventário conclusivo e action ausente
null   herança/composição dinâmica impede provar ausência
```

Wildcards de `only/except/optional`, classe de behavior dinâmica, `parent::actions()`, spreads, `array_merge()`, retorno indireto e herança externa não demonstrável degradam para `unknown`.

A validação estrutural de `Controller::actions()` (classe Action, propriedades/options e tipos) não pertence a `COR-002`; ela permanece no pacote de config arrays/BaseObject para não misturar **referência a action** com **validade da configuração da action**.

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

### COR-010 — atributo inexistente em conditions/updates estáticos de ActiveRecord

\`src/Yii2ActiveRecordAttributeAnalyzer.php\` reutiliza o inventário de \`Yii2ModelRulesAnalyzer\`, mas somente para classes marcadas como ActiveRecord **e** com inventário completo.

A tranche cobre keys string literais em:

\`\`\`text
findOne(condition)
findAll(condition)
deleteAll(condition)
updateAll(attributes, condition)
updateAllCounters(counters, condition)
\`\`\`

Exemplo conclusivo:

\`\`\`php
final class Order extends \yii\db\ActiveRecord
{
    public function attributes(): array
    {
        return ['id', 'status'];
    }
}

Order::updateAll(
    ['statuz' => 'closed'],
    ['id' => 1],
);
\`\`\`

\`statuz\` produz \`NINFA-YII2-COR-010\`. Já um ActiveRecord que depende apenas do schema runtime não possui inventário suficiente para negar a key e permanece \`unknown\`.

Operator-format arrays, keys dinâmicas, spreads e validação do **tipo do valor** também ficam fora desta tranche. O upstream consegue parte desses type checks via PHPStan Reflection; o Ninfa não fabrica equivalência sem evidência de mesma força.

Contrato:

\`\`\`text
category          correctness
severity          error
confidence        high
autofix           false
\`\`\`

### COR-006 — atributo inexistente em `Model::rules()`

`src/Yii2ModelRulesAnalyzer.php` estabelece um inventário de atributos reutilizável e usa essa prova para validar atributos literais no índice 0 das rules.

Inventários são conclusivos apenas em duas situações:

```text
attributes() retorna lista literal completa
ou
cadeia local termina em yii\base\Model e os atributos vêm de propriedades públicas não estáticas
```

Herança local agrega propriedades públicas do parent. Em contrapartida, `ActiveRecord` sem override literal de `attributes()` continua inconclusivo, pois colunas podem vir do schema em runtime. Trait usada pela classe, parent externo e `attributes()` dinâmico também tornam o inventário incompleto.

Exemplo:

```php
class SignupForm extends \yii\base\Model
{
    public string $email = '';

    public function rules(): array
    {
        return [
            ['emial', 'string'], // COR-006: inventário completo prova ausência
        ];
    }
}
```

A primeira tranche aceita string literal ou array literal de strings como lista de atributos. `array_merge()`, spread, variável, concatenação, retorno indireto e outras shapes dinâmicas permanecem `unknown`.

Contrato:

```text
category          correctness
severity          error
confidence        high
autofix           false
```

O Ninfa não tenta decidir automaticamente se a correção é renomear a rule ou adicionar um atributo ao Model, porque isso depende de intenção de domínio.


### COR-007 — cenários e atributos inválidos em `Model::scenarios()`

`src/Yii2ModelMetadataAnalyzer.php` reutiliza o mesmo inventário conclusivo de atributos usado por `COR-006`. A tranche valida somente `return [...]` literal com nomes de cenário literais e listas literais de atributos.

O prefixo `!` do Yii2 é preservado como sintaxe de cenário, mas removido apenas para o lookup do atributo:

```php
public function scenarios(): array
{
    return [
        'create' => ['name', '!email', 'misspelled'],
    ];
}
```

`misspelled` só produz finding quando o inventário do Model é completo. Nome de cenário vazio e atributo literal vazio também são inválidos. Valor de cenário dinâmico, item dinâmico ou retorno indireto permanece `unknown`.

Contrato:

```text
category          correctness
severity          error
confidence        high
autofix           false
```

A cobertura permanece `PARTIAL` em relação ao upstream porque o Ninfa ainda não tenta provar, por type inference, que expressões não literais são definitivamente não-array ou não-string.

### COR-008 — atributo inválido em `Model::attributeLabels()`

`NINFA-YII2-COR-008` valida chaves string literais de `attributeLabels()` quando o inventário do Model é conclusivo. Chave vazia ou atributo comprovadamente ausente gera finding; chave dinâmica e retorno não literal permanecem `unknown`.

### COR-009 — atributo inválido em `Model::attributeHints()`

`NINFA-YII2-COR-009` aplica o mesmo contrato de `COR-008` a `attributeHints()`: somente chave literal e inventário conclusivo permitem afirmar ausência. Labels e hints não possuem autofix porque decidir entre corrigir a chave ou alterar o contrato do Model exige intenção de domínio.

## Security smell

### SEC-001 — igualdade SQL dinâmica em `where()`

`src/Yii2WhereEqualityAnalyzer.php` incorpora de forma conservadora os conceitos de `noDynamicQueryWhere` e `ReplaceWhereEqualityConditionWithArrayRector`. A primeira tranche reconhece apenas chains iniciadas por `ActiveRecord::find()` de classe local com herança Yii2 comprovada e duas formas simples:

```php
Order::find()->where('status = ' . $status);
Order::find()->andWhere("tenant_id = $tenantId");
```

A orientação é migrar para hash condition para delegar binding/quoting ao Query Builder:

```php
Order::find()->where(['status' => $status]);
Order::find()->andWhere(['tenant_id' => $tenantId]);
```

O finding não afirma SQL injection. A análise comprova a construção dinâmica da igualdade, mas não comprova que `$status` ou `$tenantId` sejam dados não confiáveis. Por isso o contrato é explicitamente de security smell:

```text
category          security
finding_kind      security-smell
severity          warning
confidence        high
taint_proven      false
remediation_risk  review
autofix           false
```

Ficam fora desta tranche receivers armazenados em variável, expressions compostas, múltiplos predicados, hash condition já segura, strings literais sem valor dinâmico, classes não Yii2 e herança externa inconclusiva. Exemplos em strings/comentários não são promovidos a finding.

`SEC-001` aparece em `ninfa assist`. Ele não entra automaticamente em `ninfa security` nem em `ninfa fix`; qualquer promoção exige correlação de taint/field test e atualização explícita do contrato de segurança.

## Performance SAFE

### PERF-001 — existência via `one()`/`count()`

`src/Yii2QueryExistenceAnalyzer.php` identifica query mais cara usada apenas para obter booleano:

```php
Order::find()->where(['status' => 1])->one() !== null;
Order::find()->where(['status' => 1])->count() > 0;
```

A transformação é `exists()`/`!exists()`. São reconhecidas somente equivalências estritas/relacionais contra `null`, `0` e `1`, inclusive com operands invertidos. O analyzer exige `ActiveRecord::find()` de classe local cuja herança até Yii2 DB/Redis/MongoDB seja comprovada e produz `offset`, `length` e replacement do comparativo completo.

A regra não reporta nem reescreve quando o valor de `one()` ou `count()` é realmente consumido, quando o threshold não representa existência ou quando o tipo da query não pode ser provado.

Contrato:

```text
category          performance
severity          warning
confidence        high
remediation_risk  safe
autofix           true
```

Quando `PERF-001` engloba uma chain que também seria candidata a `MOD-001`, o `Yii2SafeRemediator` dá precedência explícita a `PERF-001` e descarta o shortcut aninhado. Outras sobreposições entre patches SAFE continuam sendo erro.

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

`src/Yii2DeprecationAnalyzer.php` produz patches exatos para depreciações mecânicas gerais:

```text
NINFA-YII2-DEP-001  Yii::trace() -> Yii::debug()
NINFA-YII2-DEP-002  Controller::EXIT_CODE_* -> yii\console\ExitCode::*
NINFA-YII2-DEP-003  return 0/1 em console action -> ExitCode::*
```

`src/Yii2CachingDeprecationAnalyzer.php` cobre APIs de caching apenas com receiver tipado comprovado:

```text
NINFA-YII2-DEP-004  Cache::mget/mset/madd() -> multiGet/multiSet/multiAdd()
NINFA-YII2-DEP-005  Dependency::getHasChanged() -> isChanged()
```

`src/Yii2ClassNameDeprecationAnalyzer.php` cobre a API antiga de `BaseObject`:

```text
NINFA-YII2-DEP-006  SomeObject::className()/static::className() -> ::class
```

`DEP-002` só atua quando a classe usada na constante resolve estaticamente para `yii\console\Controller`. `DEP-003` só atua em action de classe cuja herança local termina em controller console Yii2 conhecido. `DEP-004/005` exigem tipo nominal comprovado por parâmetro/propriedade/`new` local ou subclasse local. `DEP-006` exige `yii\base\BaseObject` ou subclasse local comprovada; `self::className()` e `parent::className()` permanecem intactos por late static binding, assim como hierarquia externa inconclusiva.

Para `DEP-006`, comentários, PHPDoc, strings, inline HTML e heredoc são mascarados preservando offsets antes da detecção. Isso impede que exemplos textuais sejam tratados como código autofixável.

Esses findings usam:

```text
category          deprecation
severity          warning
confidence        high
remediation_risk  safe
autofix           true
```

A mesma evidência dos analyzers alimenta `assist` e `fix`; a engine não mantém uma segunda lógica de prova de tipo.

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
Yii2SemanticModel ---------------------┐
Yii2BehaviorActionAnalyzer ------------┤
Yii2RelationReferenceAnalyzer ---------┤
Yii2RelationLinkAnalyzer --------------┤
Yii2QueryConditionAnalyzer ------------┤
Yii2ModelRulesAnalyzer ----------------┤
Yii2WhereEqualityAnalyzer -------------┤
Yii2QueryExistenceAnalyzer ------------┤
Yii2FindShortcutAnalyzer --------------┤
Yii2DeprecationAnalyzer ---------------┤
Yii2CachingDeprecationAnalyzer --------┼-> Yii2RuleEngine -> Finding[]
Yii2ClassNameDeprecationAnalyzer ------┤
Yii2MagicPropertyAnalyzer -------------┤
Yii2ControllerAccessAnalyzer ----------┘
```

Catálogo atual:

| ID | Categoria | Estado / política |
| --- | --- | --- |
| `NINFA-YII2-COR-001` | correctness | `assist`, error |
| `NINFA-YII2-COR-002` | correctness | `assist`, error |
| `NINFA-YII2-COR-003` | correctness | `assist`, error |
| `NINFA-YII2-COR-004` | correctness | `assist`, error |
| `NINFA-YII2-COR-005` | correctness | `assist`, error |
| `NINFA-YII2-COR-010` | correctness | `assist`, error |
| `NINFA-YII2-COR-006` | correctness | `assist`, error |
| `NINFA-YII2-COR-007` | correctness | `assist`, error |
| `NINFA-YII2-COR-008` | correctness | `assist`, error |
| `NINFA-YII2-COR-009` | correctness | `assist`, error |
| `NINFA-YII2-SEC-001` | security-smell | `assist`, warning, REVIEW |
| `NINFA-YII2-PERF-001` | performance | `assist` + `fix`, warning, SAFE |
| `NINFA-YII2-MOD-001` | modernization | `assist` + `fix`, warning, SAFE |
| `NINFA-YII2-DEP-001` | deprecation | `assist` + `fix`, warning, SAFE |
| `NINFA-YII2-DEP-002` | deprecation | `assist` + `fix`, warning, SAFE |
| `NINFA-YII2-DEP-003` | deprecation | `assist` + `fix`, warning, SAFE |
| `NINFA-YII2-DEP-004` | deprecation | `assist` + `fix`, warning, SAFE |
| `NINFA-YII2-DEP-005` | deprecation | `assist` + `fix`, warning, SAFE |
| `NINFA-YII2-DEP-006` | deprecation | `assist` + `fix`, warning, SAFE |
| `NINFA-YII2-TYPE-001` | static-analysis | `assist`, warning, SEMANTIC |
| `NINFA-YII2-ARCH-001` | architecture | `assist`, warning, REVIEW |

Todos usam `Finding`; não existe schema paralelo para Yii2.

## Remediação SAFE

`src/Yii2SafeRemediator.php` é a única camada nativa autorizada atualmente a alterar código Yii2 diretamente. Ela recebe patches exatos dos analyzers SAFE, agrupa por arquivo, resolve apenas a precedência declarada `PERF-001 > MOD-001`, rejeita demais intervalos sobrepostos e aplica do maior offset para o menor.

Atualmente consome:

```text
Yii2DeprecationAnalyzer
Yii2CachingDeprecationAnalyzer
Yii2ClassNameDeprecationAnalyzer
Yii2QueryExistenceAnalyzer
Yii2FindShortcutAnalyzer
```

A operação é idempotente: uma segunda execução sobre o resultado já corrigido precisa produzir zero alterações.

No CLI, `ninfa fix` executa a remediação SAFE Yii2 antes dos fixers externos. Depois o fluxo normal de `RecheckingPipelineRunner` preserva o contrato já existente de **um único `check` final**. A remediação nativa não é representada como pseudo-binário em `PipelinePlan`.

`SEC-001` não é consumida por `Yii2SafeRemediator`: a sugestão de hash condition é REVIEW até existir evidência suficiente para uma promoção explícita a SAFE. `COR-006` também não possui autofix: escolher entre corrigir a rule e alterar o contrato do Model exige intenção de domínio.

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
tests/yii2-view-resolution.php
tests/yii2-behavior-inheritance.php
tests/yii2-query-existence.php
tests/yii2-query-condition.php
tests/yii2-query-relations.php
tests/yii2-active-record-attributes.php
tests/yii2-model-rules.php
tests/yii2-model-metadata.php
tests/yii2-where-equality.php
tests/yii2-deprecation-remediation.php
tests/yii2-typed-deprecation.php
tests/yii2-classname-deprecation.php
tests/yii2-find-shortcut.php
tests/yii2-magic-property.php
tests/yii2-controller-access.php
```

`tests/yii2-model-rules.php` cobre propriedades públicas, herança local, `attributes()` literal de ActiveRecord, atributos inválidos, ActiveRecord runtime, traits, `attributes()` dinâmico e `rules()` dinâmica.

`tests/yii2-model-metadata.php` cobre `scenarios()`, prefixo unsafe `!`, nomes vazios, labels/hints válidos e inválidos, retorno dinâmico e ActiveRecord com schema runtime inconclusivo.

Todos integram `make profile-test`.

## Relação com os comandos

### `ninfa check`

As regras nativas Yii2 continuam fora do gate principal enquanto correctness/advisories são calibrados em projetos reais. O check final após `fix` continua sendo a validação global do resultado.

### `ninfa assist`

É a superfície principal dos findings nativos, incluindo `COR-006..010` e `SEC-001`, e preserva:

```text
assist/yii2-semantic.json
assist/yii2-findings.json
assist/findings.json
```

### `ninfa fix`

Executa somente remediações nativas marcadas SAFE e os fixers externos existentes. Atualmente são SAFE nativas: `PERF-001`, `MOD-001` e `DEP-001..006`.

`COR-*`, `SEC-001`, `TYPE-001` e `ARCH-001` não são reescritos automaticamente.

### `ninfa security`

Continua reservado a SCA/SAST. `SEC-001` permanece um security smell no `assist` enquanto `taint_proven=false`; não é automaticamente tratado como vulnerabilidade. Performance, modernization, deprecation, PHPDoc e architecture advisory também não são tratados como vulnerabilidade.

## Proveniência

As regras e transformações são inspiradas em ideias dos projetos:

- `mspirkov/yii2-phpstan-rules`;
- `mspirkov/yii2-rector`.

O Ninfa não reproduz PHPStan Reflection nem acopla IDs públicos ao upstream. Quando uma regra upstream depende de type inference mais forte, a implementação nativa reduz cobertura em vez de inferir tipo incorreto.

`COR-006` reimplementa apenas a parte de existência de atributos de `modelRulesValidation`; validação de classe do validator, options e tipos permanece fora desta tranche.

As regras arquiteturais do upstream são tratadas como opiniões úteis/advisories por padrão. Só passam a correctness/security quando houver contrato objetivo e evidência apropriada.

A matriz de rastreabilidade das regras upstream fica em `docs/YII2-UPSTREAM-RULE-MATRIX.md` e deve ser atualizada junto com qualquer promoção `DEFERRED/PARTIAL/REVIEW/SEMANTIC` para uma regra NINFA própria.

## Continuidade

A evolução seguinte prioriza:

1. desenhar o pacote 2.5 de config arrays/BaseObject sobre um modelo compartilhado de classe/properties/setters/options;
2. correlacionar `SEC-001` com evidência de taint/field tests antes de qualquer promoção a `security`/autofix;
3. avançar ActiveForm/UploadedFile/config arrays apenas quando o tipo do Model/componente for demonstrável;
4. melhorar typing com evidência forte antes de `RemoveRedundantHtmlEncodeRector`;
5. hardening Redis/MongoDB e inventário de schema apenas com fonte forte e auditável;
6. promover correctness ao `check` somente após calibração de falso positivo.
