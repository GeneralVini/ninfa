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

`src/Yii2SemanticModel.php` é a primeira camada compartilhada do profile. Ele não gera `Finding` e não decide severidade. O modelo coleta fatos estáticos reutilizáveis por regras posteriores.

Snapshot inicial:

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

## Taxonomia prevista

As regras específicas Yii2 devem receber ID estável do Ninfa, independente do nome upstream.

| Família | Exemplo de ID | Tratamento padrão |
| --- | --- | --- |
| correctness | `NINFA-YII2-COR-001` | bloqueante quando a evidência é conclusiva |
| security | `NINFA-YII2-SEC-001` | segue política SAST e evidência do fluxo |
| architecture | `NINFA-YII2-ARCH-001` | advisory salvo política explícita |
| performance | `NINFA-YII2-PERF-001` | advisory/remediation |
| deprecation | `NINFA-YII2-DEP-001` | candidato a autofix seguro |
| static-analysis | `NINFA-YII2-TYPE-001` | check/assist |

O primeiro catálogo planejado é:

| Conceito | Categoria | Estado |
| --- | --- | --- |
| view literal inexistente | correctness | modelo semântico implementado; regra pendente |
| action inexistente em filtros/behaviors | correctness | planejado |
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

O objetivo é preservar informação semântica real. Docblocks vazios ou que apenas repetem a assinatura não atendem ao contrato.

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

`tests/yii2-semantic-model.php` protege a fundação atual: capabilities Redis/MongoDB, actions inline/externas, resolução de views literais e relações `hasOne`/`hasMany`.

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

Recebe correctness, static-analysis, deprecation e qualidade Yii2 conforme as regras forem promovidas ao pipeline. Não modifica o consumidor.

### `ninfa fix`

Só deve aplicar remediações classificadas como seguras. Alterações de comportamento, arquitetura ou PHPDoc sensível não entram automaticamente.

### `ninfa assist`

É o destino preferencial para findings Yii2 que exigem interpretação humana, incluindo PHPDoc sem correção mecânica comprovadamente segura.

### `ninfa security`

Continua reservado à segurança. View inexistente, query ineficiente ou preferência arquitetural não são chamadas de vulnerabilidade. Regras Yii2 só entram em `security` quando há contrato de segurança correspondente e evidência adequada.

## Proveniência

As ideias de regras são comparadas com:

- `mspirkov/yii2-phpstan-rules`;
- `mspirkov/yii2-rector`;
- capacidades já existentes do próprio Ninfa.

Ambos os projetos externos usam licença MIT. Consulte `THIRD_PARTY_NOTICES.md`. Se código ou porção substancial vier a ser adaptado literalmente, o copyright e o texto de licença correspondentes devem acompanhar a distribuição.

## Sequência de implementação

1. catálogo e contrato documental;
2. modelo semântico compartilhado;
3. correctness de view/action/relation;
4. integração com findings normalizados;
5. deprecations/remediações SAFE;
6. query/security com correlação ao SAST;
7. PHPDoc/magic properties via check/assist;
8. hardening Redis/MongoDB e field tests.

Cada etapa deve atualizar código, PHPDoc, testes e documentação no mesmo conjunto de mudanças.
