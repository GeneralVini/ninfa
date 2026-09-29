# Análise semântica Yii2

Este documento é o contrato técnico do profile `yii2` do Ninfa para análise específica de framework. Ele complementa `README.md`, `INTERNAL-ARCHITECTURE.md` e a documentação de segurança sem transformar preferências arquiteturais em vulnerabilidades.

## Objetivo

O Ninfa combina três fontes de conhecimento:

1. análise genérica já fornecida por PHPStan, Psalm, Rector, ECS e SAST;
2. semântica própria do Yii2 necessária para interpretar convenções que ferramentas genéricas não conseguem provar sozinhas;
3. boas práticas observadas nos projetos `mspirkov/yii2-phpstan-rules` e `mspirkov/yii2-rector`, reimplementadas sob os contratos de findings, workspace e remediação do Ninfa.

A incorporação é conceitual. O Ninfa não deve copiar classes upstream sem preservar licença/proveniência e não deve acoplar seu contrato público aos nomes internos das regras de terceiros.

## Princípios

- o consumidor não é modificado durante `check`, `security` ou `assist`;
- fatos semânticos e política de findings são responsabilidades separadas;
- referências dinâmicas não comprováveis são `unknown`, não erro presumido;
- correctness, architecture, performance, deprecation e security são categorias diferentes;
- uma preferência arquitetural não é promovida a vulnerabilidade apenas por existir em uma ferramenta upstream;
- Redis e MongoDB são capabilities do profile Yii2, não novos profiles;
- documentação e PHPDoc fazem parte da mesma entrega do código.

## Modelo semântico

`src/Yii2SemanticModel.php` é a camada compartilhada do profile. Ele não gera `Finding` e não decide severidade. O modelo coleta fatos estáticos reutilizáveis por regras posteriores.

Snapshot atual:

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

A resolução inicial de views cobre a convenção estática `controllers/` -> `views/<controller-id>/` para chamadas literais a `render()`, `renderPartial()` e `renderAjax()`. Alias, `renderFile()`, nomes calculados, paths absolutos e traversal permanecem desconhecidos nesta camada até existir resolução específica suficientemente confiável.

O modo normal de `scripts/ninfa-configure.php` persiste esse snapshot em `semantic-index.json`, sob `framework_semantics`, sem misturá-lo aos hints documentais de `SemanticHints`. Em `ninfa assist`, o snapshot também é preservado em `assist/yii2-semantic.json`.

## Referências de actions em behaviors

`src/Yii2BehaviorActionAnalyzer.php` complementa o modelo semântico para uma classe de evidência que depende do source original do controller: referências de action dentro de `behaviors()`.

O analisador não executa PHP do consumidor. Ele tokeniza somente arrays literais retornados por `behaviors()` e cobre:

```text
ActionFilter.only
ActionFilter.except
AuthMethod.optional
AccessControl.rules[].actions
VerbFilter.actions (chaves)
```

A classificação é conservadora. Configurações com classe dinâmica, arrays não literais ou famílias de behavior que não possam ser reconhecidas com segurança são ignoradas. Wildcards de `only`/`except`/`optional` e a chave `*` de `VerbFilter` não são tratados como IDs concretos.

A existência de action é tri-state:

```text
true   action conhecida no controller ou em parent controller local
false  inventário conclusivo e action ausente
null   herança/composição dinâmica impede provar ausência
```

Herança local entre controllers é percorrida. Pais padrão `yii\\base\\Controller`, `yii\\web\\Controller` e `yii\\console\\Controller` encerram a cadeia como conhecidos. Pai customizado externo/desconhecido, `parent::actions()`, `array_merge()`, spread ou retorno indireto de `actions()` degradam o inventário para `null`, evitando falso positivo.

## Engine de regras nativas

`src/Yii2RuleEngine.php` transforma somente fatos semânticos conclusivos em `Finding`. A separação é intencional:

```text
Yii2SemanticModel ----------┐
                            ├-> Yii2RuleEngine -> Finding
Yii2BehaviorActionAnalyzer -┘
```

As regras implementadas nesta tranche são:

```text
NINFA-YII2-COR-001  view literal inexistente
NINFA-YII2-COR-002  action inexistente referenciada em behavior/filter
```

`COR-001` reporta uma referência literal a view quando o modelo conseguiu resolver o path convencional e confirmou que o arquivo não existe. `exists=null`, nomes calculados, aliases não resolvidos e demais casos desconhecidos não produzem finding.

`COR-002` reporta somente referências literais de behavior cujo inventário de actions esteja completo e tenha resultado `exists=false`. O finding preserva a origem da referência (`only`, `except`, `optional`, `rules[].actions` ou `VerbFilter.actions`) e a classe do behavior. Referências com `exists=null` não produzem finding.

Os findings usam:

```text
tool          ninfa-yii2
category      correctness
severity      error
confidence    high
autofix       false
evidence_type framework-correctness
```

No estágio atual as regras são incorporadas ao fluxo de `assist`, que grava `assist/yii2-findings.json` e também inclui os findings no `assist/findings.json`. A promoção para o gate de `check` deve ocorrer apenas depois de field tests em projetos Yii2 reais.

## Capabilities de storage

O profile permanece `yii2` quando o Composer declara:

```text
yiisoft/yii2-redis
yiisoft/yii2-mongodb
```

O modelo expõe:

```json
{
  "redis": true,
  "mongodb": false
}
```

Regras ActiveRecord/Query podem posteriormente usar essas flags para habilitar semântica específica sem criar combinações de profile como `yii2-redis-mongodb`.

## Taxonomia

As regras específicas Yii2 recebem ID estável do Ninfa, independente do nome upstream.

| Família | Exemplo de ID | Tratamento padrão |
| --- | --- | --- |
| correctness | `NINFA-YII2-COR-001` | bloqueante quando a evidência é conclusiva e a regra foi promovida ao gate |
| security | `NINFA-YII2-SEC-001` | segue política SAST e evidência do fluxo |
| architecture | `NINFA-YII2-ARCH-001` | advisory salvo política explícita |
| performance | `NINFA-YII2-PERF-001` | advisory/remediation |
| deprecation | `NINFA-YII2-DEP-001` | candidato a autofix seguro |
| static-analysis | `NINFA-YII2-TYPE-001` | check/assist |

Catálogo atual:

| Conceito | Categoria | Estado |
| --- | --- | --- |
| view literal inexistente | correctness | `NINFA-YII2-COR-001` implementada; em `assist` |
| action inexistente em filtros/behaviors | correctness | `NINFA-YII2-COR-002` implementada; em `assist` |
| relação ActiveRecord inexistente | correctness | inventário de relações implementado; regra pendente |
| condição dinâmica insegura em Query | security/code-quality | planejado |
| existência via `one()`/`count()` | performance | planejado |
| APIs Yii2 deprecated | deprecation | planejado |
| properties mágicas/PHPDoc | static-analysis | planejado |

## Remediação

Remediação deve ser classificada por risco, não apenas pelo conjunto de origem.

### SAFE

Transformações mecânicas de equivalência bem definida, por exemplo:

```text
Yii::trace() -> Yii::debug()
Cache::mget() -> Cache::multiGet()
Cache::mset() -> Cache::multiSet()
Cache::madd() -> Cache::multiAdd()
Dependency::getHasChanged() -> Dependency::isChanged()
constants antigos de exit code -> ExitCode::*
```

Essas transformações são candidatas futuras ao `ninfa fix`, sempre seguidas pelo recheck único já definido pelo pipeline.

### REVIEW

Mudanças semanticamente mais contextuais, por exemplo simplificações de query e acesso via controller. Devem aparecer primeiro em `check`/`assist` com orientação ou patch previsto.

### SEMANTIC

Alterações de PHPDoc, propriedades mágicas e mudanças estruturais devem permanecer inicialmente em `assist`. PHPDoc Yii2 afeta IDEs e análise estática e não é tratado como comentário cosmético.

## PHPDoc do próprio Ninfa

Código novo do profile segue integralmente `docs/INTERNAL-ARCHITECTURE.md` e `tests/internal-docs.php`:

- classes com PHPDoc narrativo de responsabilidade e limites;
- métodos públicos e privados documentados;
- `array` preservando generics/shapes quando conhecidos;
- acumuladores não triviais com `@var`;
- comentários locais para decisões, precedências e invariantes relevantes;
- I/O e mutações documentados junto do método responsável.

O objetivo é preservar informação semântica real. Docblocks vazios ou que apenas repetem a assinatura não atendem ao contrato. O próprio CI já bloqueou a primeira versão de `Yii2SemanticModel` por ausência de comentário local de invariável em um arquivo com fluxo relevante; a implementação foi corrigida em vez de relaxar o guard.

## PHPDoc do consumidor Yii2

O Ninfa distingue seu PHPDoc interno do PHPDoc analisado no consumidor.

No consumidor, `@property`, `@property-read` e `@property-write` podem representar APIs reais de `BaseObject`/ActiveRecord e influenciam PHPStan, Psalm e IDEs. Por isso:

```text
check  -> pode detectar inconsistência
assist -> pode explicar e propor patch
fix    -> não altera PHPDoc semanticamente sensível nesta fase
```

Autofix de PHPDoc só deve ser habilitado depois de fixtures e field tests demonstrarem equivalência e baixo índice de falso positivo.

## Testes e fixtures

Toda nova regra Yii2 deve conter, no mínimo:

1. true positive;
2. true negative;
3. caso limite;
4. caso dinâmico/não resolvível que não pode virar falso positivo.

`tests/yii2-semantic-model.php` protege a fundação atual: capabilities Redis/MongoDB, actions inline/externas, resolução de views literais, relações `hasOne`/`hasMany`, `NINFA-YII2-COR-001`, `NINFA-YII2-COR-002` e a persistência de `framework_semantics` no workspace externo.

Para `COR-002`, a fixture cobre referências válidas e inválidas em `only`, `except`, `AccessControl.rules[].actions`, `VerbFilter.actions` e `AuthMethod.optional`. Também verifica que wildcards, valores de action dinâmicos e classe de behavior dinâmica não viram finding.

A evolução prevista é organizar fixtures em:

```text
tests/fixtures/yii2/
├── views/
├── actions/
├── active-record/
├── queries/
├── phpdoc/
├── deprecations/
├── redis/
└── mongodb/
```

## Relação com os comandos públicos

### `ninfa check`

Receberá correctness, static-analysis, deprecation e qualidade Yii2 à medida que cada regra passar pela fase de `assist`/field test e for promovida ao gate. Não modifica o consumidor.

### `ninfa fix`

Só deve aplicar remediações classificadas como seguras. Alterações de comportamento, arquitetura ou PHPDoc sensível não entram automaticamente.

### `ninfa assist`

É a primeira superfície das regras nativas Yii2. Além de PHPStan/Psalm estruturados, preserva `yii2-semantic.json`, `yii2-findings.json` e inclui os findings Yii2 no `findings.json` consolidado. Nesta tranche isso inclui `COR-001` e `COR-002`.

### `ninfa security`

Continua reservado à segurança. View inexistente, action de filtro inexistente, query ineficiente ou preferência arquitetural não são chamadas de vulnerabilidade. Regras Yii2 só entram em `security` quando há contrato de segurança correspondente e evidência adequada.

## Proveniência

As ideias de regras são comparadas com:

- `mspirkov/yii2-phpstan-rules`;
- `mspirkov/yii2-rector`;
- capacidades já existentes do próprio Ninfa.

Ambos os projetos externos usam licença MIT. Consulte `THIRD_PARTY_NOTICES.md`. Se código ou porção substancial vier a ser adaptado literalmente, o copyright e o texto de licença correspondentes devem acompanhar a distribuição.

A validação de actions em behaviors foi reimplementada sob o modelo conservador do Ninfa. A implementação upstream usa PHPStan Reflection/AST para provar subclasses de `ActionFilter`; o Ninfa, nesta tranche, prefere reconhecer classes/configurações estáticas e degradar incerteza para `null` em vez de afirmar equivalência onde não possui reflexão completa.

## Sequência de implementação

1. catálogo e contrato documental — iniciado;
2. modelo semântico compartilhado — implementado para capabilities/controllers/actions/views/relations;
3. correctness de view/action/relation — view e action implementadas em `assist`; relation pendente;
4. integração com findings normalizados — `NINFA-YII2-COR-001` e `NINFA-YII2-COR-002` integradas;
5. deprecations/remediações SAFE;
6. query/security com correlação ao SAST;
7. PHPDoc/magic properties via check/assist;
8. hardening Redis/MongoDB e field tests.

Cada etapa deve atualizar código, PHPDoc, testes e documentação no mesmo conjunto de mudanças.
