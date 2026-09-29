# Matriz de incorporação Yii2 upstream

Este documento registra como o Ninfa absorve ideias de `mspirkov/yii2-phpstan-rules` e `mspirkov/yii2-rector` sem transformar os projetos upstream em contrato público do produto. Ele complementa `YII2-ANALYSIS.md` e `YII2-SAFE-REMEDIATION.md`.

A classificação é deliberadamente mais estrita que a mera disponibilidade de uma regra upstream. O Ninfa separa **detecção**, **finding**, **risco de remediação** e **superfície de comando**. Uma transformação só entra em `fix` quando há equivalência mecânica comprovada e fixture de idempotência.

## Estados

| Estado | Significado |
| --- | --- |
| `IMPLEMENTED` | conceito já possui regra/transformação nativa equivalente no Ninfa |
| `PARTIAL` | parte do conceito foi incorporada, mas com cobertura deliberadamente menor |
| `SAFE-CANDIDATE` | pode chegar a autofix, mas ainda exige prova/fixtures adicionais |
| `REVIEW` | útil como finding ou patch sugerido; não deve ser autofixado por padrão |
| `SEMANTIC` | depende de contrato de tipo/PHPDoc/arquitetura e permanece fora de autofix |
| `DEFERRED` | valor reconhecido, mas não prioritário ou sem base forte para implementação nativa |

## `yii2-rector`

| Regra upstream | Decisão Ninfa | Mapping atual | Observação |
| --- | --- | --- | --- |
| `ReplaceTraceWithDebugRector` | `IMPLEMENTED` | `NINFA-YII2-DEP-001` | replacement mecânico `Yii::trace()` → `Yii::debug()` |
| `ReplaceExitCodeConstantRector` | `IMPLEMENTED` | `NINFA-YII2-DEP-002` | exige resolução estática para `yii\\console\\Controller` |
| `ReplaceActionReturnLiteralWithExitCodeRector` | `IMPLEMENTED` | `NINFA-YII2-DEP-003` | limitado a actions de console controller comprovado |
| `ReplaceCacheMultiMethodAliasesRector` | `IMPLEMENTED` | `NINFA-YII2-DEP-004` | receiver precisa ser `yii\\caching\\Cache` ou subclasse local comprovada |
| `ReplaceGetHasChangedWithIsChangedRector` | `IMPLEMENTED` | `NINFA-YII2-DEP-005` | receiver precisa ser `yii\\caching\\Dependency` ou subclasse local comprovada |
| `ReplaceClassnameWithClassRector` | `IMPLEMENTED` | `NINFA-YII2-DEP-006` | preserva `self::className()`/`parent::className()` e hierarquia inconclusiva |
| `ReplaceExistenceCheckWithExistsRector` | `IMPLEMENTED` | `NINFA-YII2-PERF-001` | `assist + fix`; precedência explícita sobre `MOD-001` quando os patches se sobrepõem |
| `ReplaceFindWhereOneWithFindOneRector` | `IMPLEMENTED` | `NINFA-YII2-MOD-001` | somente hash literal associativo suportado |
| `ReplaceFindWhereAllWithFindAllRector` | `IMPLEMENTED` | `NINFA-YII2-MOD-001` | mesma prova do caso `findOne()` |
| `ReplaceAppRequestResponseWithThisRector` | `PARTIAL` | `NINFA-YII2-ARCH-001` | finding REVIEW; o Ninfa não trata preferência arquitetural como autofix |
| `AddPropertyTagsRector` | `PARTIAL` | `NINFA-YII2-TYPE-001` | hoje detecta relação mágica sem tag quando a classe já mantém property tags; não reescreve PHPDoc |
| `RemoveRedundantPropertyTagsRector` | `SEMANTIC` | — | remover tag pode alterar inferência de IDE/PHPStan/Psalm; requer contrato de tipo forte |
| `ReplaceGetterWithPropertyRector` | `SEMANTIC` | — | depende de BaseObject, property tag compatível, assinatura do getter e ausência de propriedade pública conflitante |
| `ReplaceSetterWithPropertyRector` | `SEMANTIC` | — | mesma sensibilidade do getter, com risco adicional de contrato de escrita |
| `MergeModelRulesRector` | `REVIEW` | — | ordem e agrupamento de validators podem carregar intenção de domínio; não é transformação mecânica universal |
| `RemoveRedundantHtmlEncodeRector` | `SAFE-CANDIDATE` | — | só deve remover `Html::encode()` quando um engine de tipos provar `numeric-string`; heurística lexical não é suficiente |
| `ReplaceWhereEqualityConditionWithArrayRector` | `SAFE-CANDIDATE` | — | bom candidato para `where(['col' => $value])`, mas exige AST/query type proof e política explícita para valores `null`, Expression e coerções |

### Critérios para as duas candidatas SAFE restantes

`RemoveRedundantHtmlEncodeRector` não será reimplementada usando nome de variável, regex ou PHPDoc isolado. O upstream remove encode apenas quando o tipo é comprovadamente `numeric-string`; o Ninfa deve exigir evidência de força equivalente antes de autorizar mutação.

`ReplaceWhereEqualityConditionWithArrayRector` também não será promovida diretamente a vulnerabilidade. String/interpolação dinâmica em `where()` pode ser um **security smell**, mas classificação de vulnerabilidade exige evidência adicional de fluxo/taint. A transformação pode ser segura no subconjunto exato de igualdade simples, desde que o Ninfa prove receiver Yii2 Query, shape do AST e equivalência do valor.

## `yii2-phpstan-rules`: validation/correctness

| Conceito upstream | Decisão Ninfa | Mapping atual / direção |
| --- | --- | --- |
| `controllerViewExistenceValidation` | `IMPLEMENTED` | `NINFA-YII2-COR-001` |
| `viewRenderExistenceValidation` | `PARTIAL` | ampliar `COR-001` quando houver resolução estática segura fora de controller |
| `nestedViewExistenceValidation` | `PARTIAL` | mesma família de resolução de views; referências dinâmicas continuam `unknown` |
| `controllerBehaviorActionsValidation` | `IMPLEMENTED` | `NINFA-YII2-COR-002` |
| `controllerActionsValidation` | `PARTIAL` | inventário de actions já existe; ampliar referências objetivas sem inventar reachability |
| `activeRecordRelationValidation` | `IMPLEMENTED` | `NINFA-YII2-COR-003` |
| `activeRecordConditionValidation` | `PARTIAL` | `NINFA-YII2-COR-005` cobre aridade de operators literais |
| `queryConditionValidation` | `PARTIAL` | mesma base de `COR-005`; ampliar tipos/conditions somente com prova segura |
| `activeQueryWithValidation` | `PARTIAL` | relation paths já cobertos; outras validações dependem de typing adicional |
| `activeRecordUpdateValuesValidation` | `DEFERRED` | requer inventário confiável de atributos/schema antes de negar chaves |
| `baseObjectInstantiationValidation` | `DEFERRED` | útil para config arrays; requer modelagem de setters/properties/configuração Yii2 |
| `behaviorAttributesValidation` | `DEFERRED` | depende de inventário de atributos e semântica de behavior |
| `componentBehaviorsValidation` | `PARTIAL` | parser de behaviors já existe para actions; config geral ainda não |
| `htmlActiveAttributeValidation` | `DEFERRED` | requer tipo de model + inventário de atributos confiável |
| `modelAttributeHintsValidation` | `DEFERRED` | depende de contrato completo de atributos |
| `modelAttributeLabelsValidation` | `DEFERRED` | depende de contrato completo de atributos |
| `modelRulesValidation` | `DEFERRED` | alta utilidade, mas validators/rules precisam parser semântico próprio |
| `modelScenariosValidation` | `DEFERRED` | depende do mesmo inventário de atributos/rules |
| `uploadedFileInstanceValidation` | `DEFERRED` | exige type/data-flow suficiente para evitar falso positivo |
| `widgetPropertiesValidation` | `DEFERRED` | exige resolução confiável de classe/config properties |
| `yiiCreateObjectValidation` | `DEFERRED` | candidato futuro do modelo de config arrays/DI |
| `activeFormFieldValidation` | `DEFERRED` | depende de model + attribute typing no ponto de uso |

## `yii2-phpstan-rules`: code quality / arquitetura

| Conceito upstream | Decisão Ninfa | Mapping atual / política |
| --- | --- | --- |
| `noRedundantExistenceCheck` | `IMPLEMENTED` | `NINFA-YII2-PERF-001` |
| `noDynamicQueryWhere` | `SAFE-CANDIDATE` | futuro finding de security-smell/modernization; não chamar vulnerabilidade sem taint |
| `noControllerActionCallsViaThis` | `REVIEW` | opinião arquitetural útil; não correctness por padrão |
| `noDbQueriesInActions` | `REVIEW` | advisory arquitetural, não vulnerabilidade automática |
| `noDbQueriesInControllers` | `REVIEW` | advisory arquitetural, respeitando aplicações Yii2 legadas |
| `noDbQueriesInViews` | `REVIEW` | pode indicar arquitetura/performance; severidade configurável |
| `noDirectSuperglobals` | `REVIEW` | pode ganhar categoria security-smell quando houver contexto; não é vulnerabilidade isolada |
| `noForbiddenYiiAppProperties` | `REVIEW` | policy específica do consumidor, não default universal |
| `noYiiAppPropertyMutation` | `REVIEW` | útil para acoplamento/estado global; não autofix |
| `noComplexActionClasses` | `REVIEW` | threshold/opinião arquitetural; deve ser configurável |
| `noComplexControllerActions` | `REVIEW` | threshold/opinião arquitetural; deve ser configurável |
| `noRedundantHtmlEncode` | `SAFE-CANDIDATE` | somente com prova `numeric-string` equivalente à do upstream |

## Política de PHPDoc

PHPDoc do consumidor é considerado parte do contrato estático. O Ninfa pode produzir finding ou patch sugerido, mas mudanças automáticas em `@property`, `@property-read`, `@property-write`, tipos de retorno inferidos e tags redundantes permanecem `SEMANTIC` até que exista prova suficiente e fixture que demonstre ausência de mudança observável para PHPStan/Psalm/IDE.

PHPDoc do próprio Ninfa continua obrigatório e narrativo. Qualquer analyzer novo precisa documentar: fonte de evidência, condições de `unknown`, shape retornado, política de falso positivo e motivo para classificação SAFE/REVIEW/SEMANTIC.

## Ordem de implementação restante

A sequência recomendada depois das regras já fechadas é:

1. `noDynamicQueryWhere` / `ReplaceWhereEqualityConditionWithArrayRector`: primeiro como finding conservador, depois avaliar autofix somente no subset comprovado;
2. ampliar model/config validation (`rules`, `scenarios`, config arrays) usando um inventário de atributos/config properties compartilhado;
3. melhorar typing com evidência de analyzer externo antes de `RemoveRedundantHtmlEncodeRector`;
4. aprofundar PHPDoc/magic properties sem autofix;
5. regras arquiteturais configuráveis, sempre separadas de correctness/security;
6. field tests em aplicações Yii2 reais antes de promover novos findings para `ninfa check`.

A matriz deve ser atualizada no mesmo commit sempre que uma ideia upstream mudar de estado ou ganhar um ID NINFA próprio.
