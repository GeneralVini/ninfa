# Remediação SAFE Yii2

Este documento descreve o subconjunto de transformações Yii2 que o Ninfa pode aplicar automaticamente durante `ninfa fix`. Ele complementa `YII2-ANALYSIS.md` e não altera a política de findings de security.

## Princípio

Uma transformação só entra em `SAFE` quando o Ninfa consegue produzir um replacement mecânico, local e idempotente sem depender de intenção de domínio. Regras de arquitetura, PHPDoc semântico, query rewrites contextuais e evidência de tipo inconclusiva permanecem em `REVIEW` ou `SEMANTIC`.

O aplicador é `src/Yii2SafeRemediator.php`. Ele recebe patches com `file`, `offset`, `length` e `replacement`, agrupa por arquivo, rejeita intervalos sobrepostos e aplica do maior offset para o menor. A segunda execução sobre o código já transformado deve produzir zero alterações.

## Catálogo SAFE atual

| ID | Transformação | Prova exigida |
| --- | --- | --- |
| `NINFA-YII2-MOD-001` | `find()->where(hash)->one()/all()` → `findOne()/findAll()` | ActiveRecord e hash literal suportado |
| `NINFA-YII2-DEP-001` | `Yii::trace()` → `Yii::debug()` | chamada global literal |
| `NINFA-YII2-DEP-002` | `Controller::EXIT_CODE_*` → `yii\console\ExitCode::*` | classe resolve para `yii\console\Controller` |
| `NINFA-YII2-DEP-003` | `return 0/1` em console action → `ExitCode::*` | herança de console controller comprovada |
| `NINFA-YII2-DEP-004` | `Cache::mget/mset/madd()` → `multiGet/multiSet/multiAdd()` | receiver comprovado como `yii\caching\Cache` ou subclasse local |
| `NINFA-YII2-DEP-005` | `Dependency::getHasChanged()` → `isChanged()` | receiver comprovado como `yii\caching\Dependency` ou subclasse local |
| `NINFA-YII2-DEP-006` | `SomeObject::className()` / `static::className()` → `::class` | classe nomeada ou classe léxica comprovada como `yii\base\BaseObject`/subclasse local |

`DEP-004` e `DEP-005` são inspiradas nas regras `ReplaceCacheMultiMethodAliasesRector` e `ReplaceGetHasChangedWithIsChangedRector`; `DEP-006` segue `ReplaceClassnameWithClassRector`, todos de `mspirkov/yii2-rector`. O Ninfa reimplementa os conceitos sob seu próprio contrato de evidência, workspace, testes e idempotência; não depende da API interna do projeto upstream.

## Prova de tipo para caching

`src/Yii2CachingDeprecationAnalyzer.php` não substitui nomes de métodos apenas porque uma variável se chama `$cache` ou `$dependency`. A transformação é permitida somente quando o tipo é demonstrável por uma das fontes locais suportadas:

```php
public function run(\yii\caching\Cache $cache): void
{
    $cache->mget(['a']);
}
```

```php
private \yii\caching\Cache $cache;
```

```php
$cache = new LocalCache(); // LocalCache extends yii\caching\Cache
```

Subclasses locais são percorridas até a base Yii2 conhecida. Tipos externos desconhecidos, parâmetros sem type hint, PHPDoc isolado, service locator, container DI runtime e unions/intersections não são usados como prova nesta tranche.

Portanto estes casos permanecem intactos:

```php
public function run($cache): void
{
    $cache->mget(['a']);
}
```

```php
public function run(RuntimeCache $cache): void
{
    $cache->mget(['a']);
}
```

A política é preferir falso negativo temporário a alterar método de um objeto cujo contrato não foi comprovado.

## `className()` para `::class`

`src/Yii2ClassNameDeprecationAnalyzer.php` só aceita `className()` sem argumentos. Chamadas em classe nomeada são corrigidas quando o FQCN resolve diretamente para `yii\base\BaseObject` ou para subclasse cuja cadeia local termina nessa base. `static::className()` exige a mesma prova para a classe léxica que contém a chamada.

Por compatibilidade com late static binding, estes dois casos permanecem intocados:

```php
self::className();
parent::className();
```

Também ficam fora do SAFE classes cujo parent externo impede concluir a cadeia. Comentários, PHPDoc, strings, inline HTML e conteúdo de heredoc são mascarados preservando offsets antes da detecção, para que exemplos textuais de `className()` nunca sejam tratados como código.

## Relação entre `assist` e `fix`

`DEP-004` e `DEP-005` usam o mesmo `Yii2CachingDeprecationAnalyzer` nos dois fluxos. `DEP-006` usa `Yii2ClassNameDeprecationAnalyzer` da mesma forma. Cada analyzer produz evidência única com `file`, `line`, `rule`, `kind`, `replacement`, `offset` e `length`.

No `assist`, `Yii2RuleEngine` apenas normaliza essa evidência como `Finding` com:

```text
category          deprecation
severity          warning
confidence        high
remediation_risk  safe
autofix           true
```

No `fix`, `Yii2SafeRemediator` reutiliza os offsets/replacements da mesma evidência. Não existe uma segunda implementação da lógica de prova para apresentação de findings.

Para `profile=yii2`, `bin/ninfa` executa `Yii2SafeRemediator` antes dos fixers externos. Depois, `RecheckingPipelineRunner` mantém o contrato global do Ninfa: um único `check` completo ao final de um `fix` bem-sucedido.

O remediator não é pseudo-ferramenta no `PipelinePlan`. Ele é uma etapa nativa do framework e não instala dependências nem grava configuração no consumidor.

## PHPDoc

Nenhuma transformação SAFE atual reescreve `@property`, `@property-read`, `@property-write` ou outros contratos PHPDoc do consumidor. Essas mudanças permanecem `SEMANTIC`, porque podem alterar inferência de IDE/PHPStan/Psalm mesmo quando não alteram execução PHP.

O próprio código do Ninfa continua sujeito ao guard de documentação: classes/métodos narrativos, shapes/generics, `@var` para estruturas não triviais e comentários locais de invariantes.

## Testes

As remediações de deprecation são cobertas por:

```text
tests/yii2-deprecation-remediation.php
tests/yii2-typed-deprecation.php
tests/yii2-classname-deprecation.php
```

A fixture de caching verifica parâmetros tipados, propriedades tipadas, subclasses locais, variáveis inicializadas com `new`, receivers não comprovados, emissão dos mesmos casos como findings de `assist` e idempotência do `fix`.

A fixture de `className()` cobre classe explícita, `static`, subclasses locais, `self`/`parent` preservados, parent externo inconclusivo, comentários/strings ignorados, findings de `assist` e idempotência. Todos os testes integram `make profile-test`.

## Limite atual

As tranches de caching e `className()` estão fechadas no contrato atual: detecção, finding e autofix SAFE compartilham a mesma fonte de evidência. Ampliações futuras da prova de tipo — por exemplo unions, hierarquia externa completa, factory/container ou PHPDoc como fonte auxiliar — exigem política explícita de confiança antes de entrarem em autofix.
