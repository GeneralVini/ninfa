# Adoção das regras `yii2-rector`

Este documento registra como o Ninfa incorpora conceitos de `mspirkov/yii2-rector` sem transformar o projeto upstream em contrato público. A classificação é feita por risco de remediação, força da evidência disponível no Ninfa e impacto sobre semântica PHP/Yii2.

A matriz complementa `YII2-ANALYSIS.md` e `YII2-SAFE-REMEDIATION.md`. Uma regra upstream não entra automaticamente em `ninfa fix`: primeiro precisa existir detector próprio, `Finding` normalizado, fixtures positivas/negativas e uma decisão explícita entre `SAFE`, `REVIEW` e `SEMANTIC`.

## Critérios

`SAFE` significa replacement mecânico, local, idempotente e demonstravelmente equivalente no subconjunto aceito pelo detector. `REVIEW` significa que existe uma sugestão objetiva, mas contexto de domínio, quoting, fluxo ou arquitetura ainda pode alterar a decisão. `SEMANTIC` cobre mudanças em contratos PHPDoc, propriedades mágicas, estrutura de rules e outras transformações cuja correção depende de inferência mais forte que a disponível no analyzer nativo.

A classificação é por **subconjunto implementado**, não pelo nome da regra upstream. O Ninfa pode aceitar menos casos que o Rector original para preservar baixa taxa de falso positivo.

## Matriz atual

| Regra upstream | Política Ninfa | Regra/estado no Ninfa | Observação |
| --- | --- | --- | --- |
| `ReplaceTraceWithDebugRector` | SAFE | `NINFA-YII2-DEP-001` | replacement mecânico `Yii::trace()` → `Yii::debug()` |
| `ReplaceExitCodeConstantRector` | SAFE | `NINFA-YII2-DEP-002` | exige resolução estática para console controller Yii2 |
| `ReplaceActionReturnLiteralWithExitCodeRector` | SAFE | `NINFA-YII2-DEP-003` | somente action de console controller comprovado |
| `ReplaceCacheMultiMethodAliasesRector` | SAFE | `NINFA-YII2-DEP-004` | receiver precisa ser `yii\caching\Cache`/subclasse comprovada |
| `ReplaceGetHasChangedWithIsChangedRector` | SAFE | `NINFA-YII2-DEP-005` | receiver precisa ser `yii\caching\Dependency`/subclasse comprovada |
| `ReplaceClassnameWithClassRector` | SAFE | `NINFA-YII2-DEP-006` | preserva `self`/`parent` e hierarquia inconclusiva |
| `ReplaceExistenceCheckWithExistsRector` | SAFE | `NINFA-YII2-PERF-001` | apenas comparações equivalentes contra `null`, `0` ou `1` |
| `ReplaceFindWhereOneWithFindOneRector` | SAFE | `NINFA-YII2-MOD-001` | somente hash condition literal suportada |
| `ReplaceFindWhereAllWithFindAllRector` | SAFE | `NINFA-YII2-MOD-001` | mesmo contrato do caso `findOne()` |
| `ReplaceAppRequestResponseWithThisRector` | REVIEW | `NINFA-YII2-ARCH-001` | orientação arquitetural; não é correctness/security |
| `ReplaceWhereEqualityConditionWithArrayRector` | REVIEW | `NINFA-YII2-SEC-001` | security smell; sem taint proof e sem autofix nesta tranche |
| `RemoveRedundantHtmlEncodeRector` | REVIEW | planejado | upstream depende de prova `numeric-string`; não será aproximada por nome/regex |
| `ReplaceGetterWithPropertyRector` | SEMANTIC | planejado | depende de BaseObject, PHPDoc legível e equivalência de tipos |
| `ReplaceSetterWithPropertyRector` | SEMANTIC | planejado | além de tipos, chained calls podem alterar semântica de retorno |
| `AddPropertyTagsRector` | SEMANTIC | parcialmente coberto por `NINFA-YII2-TYPE-001` | Ninfa sugere relation tags, mas não reescreve PHPDoc |
| `RemoveRedundantPropertyTagsRector` | SEMANTIC | planejado | remoção muda contrato percebido por IDE/PHPStan/Psalm |
| `MergeModelRulesRector` | SEMANTIC | planejado | alteração estrutural de `rules()` depende de intenção e precedência |

## `SEC-001` — igualdade dinâmica em `where()`

`src/Yii2WhereEqualityAnalyzer.php` implementa a primeira tranche inspirada em `ReplaceWhereEqualityConditionWithArrayRector`. O detector só considera chain iniciada por `ActiveRecord::find()` de classe local cuja herança Yii2 seja demonstrável e reconhece duas formas simples:

```php
Order::find()->where('status = ' . $status);
Order::find()->andWhere("tenant_id = $tenantId");
```

A sugestão é usar hash condition:

```php
Order::find()->where(['status' => $status]);
Order::find()->andWhere(['tenant_id' => $tenantId]);
```

O finding é classificado como `security-smell`, não como vulnerabilidade confirmada. A presença da concatenação/interpolação demonstra que o Query Builder não está recebendo uma hash condition naquele ponto, mas **não demonstra origem não confiável do valor**. Por isso o contrato atual é:

```text
category          security
finding_kind      security-smell
severity          warning
confidence        high
taint_proven      false
remediation_risk  review
autofix           false
```

`ninfa security` continua reservado ao fluxo SCA/SAST. `SEC-001` aparece no `assist` até existir correlação de taint/cobertura suficiente para decidir se deve ser promovido ao contrato de segurança.

## Casos deliberadamente não reconhecidos por `SEC-001`

A primeira tranche não tenta reescrever ou acusar:

```php
$query->where('status = ' . $status);              // tipo do receiver não provado
Order::find()->where('status = ' . trim($status)); // expressão composta
Order::find()->where('status = ' . $status . ' AND active = 1');
Order::find()->where(['status' => $status]);        // já usa hash condition
Order::find()->where('status = active');            // string literal sem valor dinâmico
```

Também são ignoradas classes não Yii2, parents externos inconclusivos, exemplos dentro de strings/comentários e outras shapes que exigiriam parser/type inference mais forte. O objetivo é preferir cobertura menor a um security finding produzido por heurística textual frágil.

## Regras PHPDoc e magic properties

`AddPropertyTagsRector`, `RemoveRedundantPropertyTagsRector`, `ReplaceGetterWithPropertyRector` e `ReplaceSetterWithPropertyRector` permanecem `SEMANTIC`. No Yii2, tags `@property*` participam do contrato de propriedades mágicas e influenciam IDE, PHPStan e Psalm. O Ninfa não deve criar, remover ou usar essas tags para autofix sem uma prova coerente entre getter/setter, tipo, visibilidade e comportamento de `BaseObject`.

`NINFA-YII2-TYPE-001` continua sendo a superfície segura de curto prazo: quando uma classe **já mantém** property tags, uma relation conhecida sem tag correspondente é reportada no `assist`, mas o consumidor não é modificado.

## `RemoveRedundantHtmlEncodeRector`

O upstream remove `Html::encode()` somente quando PHPStan prova que o argumento é `numeric-string`. Essa condição é importante: remover encode por nome de variável, cast presumido ou regex de conteúdo seria uma redução de garantia.

Portanto a regra fica em `REVIEW` até o Ninfa possuir uma fonte forte para `numeric-string` — por exemplo integração explícita com o tipo inferido pelo PHPStan — e fixtures que provem que a remoção não afeta conteúdo não numérico.

## Política de evolução

Uma regra `REVIEW` só pode migrar para `SAFE` quando o analyzer passar a produzir replacement exato e a suíte cobrir equivalência, falsos positivos, sobreposição com outras regras e idempotência. Uma regra `SEMANTIC` só pode migrar para autofix depois de existir um contrato de tipos/framework forte o suficiente para demonstrar que a transformação preserva o comportamento observável.

Qualquer promoção deve atualizar, no mesmo conjunto de mudanças, este arquivo, `YII2-ANALYSIS.md` quando o catálogo público mudar, `YII2-SAFE-REMEDIATION.md` se entrar em `fix`, PHPDoc da implementação e a fixture dedicada.
