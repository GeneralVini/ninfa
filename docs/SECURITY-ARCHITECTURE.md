# Arquitetura de segurança

Este documento registra a direção arquitetural de `ninfa security`, o que está implementado e os limites de evolução. O README é o painel rápido; este arquivo preserva contratos, responsabilidades e critérios de expansão.

## Escopo atual

O pipeline público de segurança está restrito a:

```text
SCA   Composer Audit + OSV
SAST  Psalm Taint + Semgrep
```

DAST está fora do pipeline do Ninfa e é tratado por outra frente institucional.

O ecossistema ativo é PHP:

```text
PHP common
├── php-generic
├── yii2
├── yii3
└── glpi-plugin
```

`php-generic` usa apenas o baseline comum. Yii2, Yii3 e GLPI Plugin 11 adicionam overlays de segurança específicos. Yii 22 continua dentro da família Yii2 e não cria profile separado neste estágio.

Laravel permanece roadmap PHP. Python (`python-generic`, Django, Flask) permanece futuro ecossistema. Esses itens não devem provocar interfaces/abstrações multilíngues antes de existir implementação real.

## Estado da arquitetura

A fundação e o SCA estruturado permanecem:

```text
SecurityInventory
      ↓
Composer Audit ─┐
                ├─ Finding SCA
OSV querybatch ─┘
      ↓
alias/canonical dedup
      ↓
SecurityReport
      ↓
security-report.json
```

O SAST segue:

```text
ProjectContext
      ↓
SecurityContract::forProfile()
      ↓
common + overlay do profile
      ↓
Psalm Taint / Semgrep
      ↓
Finding[] + coverage
      ↓
ToolResult / SecurityReport
```

## Princípio arquitetural SAST

O Ninfa separa três responsabilidades:

```text
SAST Contract
  define O QUE caracteriza a classe de vulnerabilidade

Profile SecurityContract
  define O QUE as APIs/convenções do profile significam

Tool Adapter
  define COMO a ferramenta executa e devolve a análise
```

O detector responde “que projeto é este?”. O contrato responde “quais sources, sinks, sanitizers e primitives existem neste profile?”. O adapter não deve ser a fonte primária de semântica do framework.

Essa arquitetura não transforma o Ninfa em auditor de estilo arquitetural. DTO, Repository, DDD, Clean Architecture e Vertical Slice são decisões do consumidor e só devem gerar regra quando houver risco técnico objetivamente demonstrável.

## Contratos SAST

Os doze contratos canônicos são:

```text
command-injection
sql-injection
xss
path-traversal
file-access
ssrf
unsafe-redirect
header-injection
dynamic-include-require
unsafe-deserialization
dangerous-eval-assert
cryptographic-misuse
```

Cada contrato comum pode definir sources, sinks, sanitizers, primitives perigosas, padrões seguros, tipos de evidência e proveniência. Overlays complementam somente a semântica realmente própria do framework.

Exemplo:

```text
sql-injection common
        +
yii2 overlay
        ↓
yii\web\Request source
yii\db\Connection::createCommand sink
bindValue/bindValues como operação segura conhecida
```

O mesmo contrato em Yii3 ou GLPI mantém a classe de risco, mas troca APIs específicas.

## SecurityContract por profile

### php-generic

Recebe somente os contratos comuns. Não se presume framework, ORM, template engine ou arquitetura.

### yii2

Adiciona capabilities:

```text
web-request
database
view-html
http-client
redirect-response
filesystem
console
```

O overlay representa Request/Response, DB/Command, encoding HTML, redirect, header e filesystem. Yii 22 permanece neste profile; diferenças de geração só devem ser modeladas quando uma regra concreta exigir.

### yii3

Adiciona capabilities semelhantes com semântica própria de Yii3/PSR, incluindo Yii DB, PSR-7, helpers HTML e respostas imutáveis.

Não modelar CSRF como busca textual por `_csrf`. Yii3 pode aplicar proteção por middleware/infraestrutura; apenas bypass/configuração estática confiável deve virar regra.

### glpi-plugin

Adiciona, além das capabilities web usuais, `host-api`. O contrato conhece APIs como `$DB`, `Html::redirect` e `Toolbox`. O host GLPI fornece contexto a PHPStan/Psalm, mas não deve virar alvo indiscriminado do SAST do plugin.

## Estrutura de regras Semgrep

A estrutura implementada é:

```text
security/
├── semgrep/
│   ├── common.yml
│   └── profiles/
│       ├── yii2.yml
│       ├── yii3.yml
│       └── glpi-plugin-11.yml
└── semgrep-tests/
    ├── common.php
    └── profiles/
        ├── yii2.php
        ├── yii3.php
        └── glpi-plugin-11.php
```

As árvores de configuração/teste permanecem paralelas para que `semgrep --test` associe regras e fixtures pelo mesmo basename/caminho relativo.

Não criar um arquivo por contrato apenas para satisfazer uma árvore conceitual. A divisão física só deve aumentar quando volume/manutenção justificar.

## Testes das regras

`make semgrep-rules` executa:

```text
semgrep --validate --config security/semgrep
semgrep --test --config security/semgrep security/semgrep-tests
```

A validação de parsing ocorre antes dos testes. Cada regra material deve manter caso positivo e caso negativo pertinente quando existir construção segura equivalente.

Fixtures funcionam como regressão de falso positivo/falso negativo. Por exemplo, bootstrap local derivado de `dirname(__DIR__)` deve permanecer seguro diante da regra de include/require porque não deriva de entrada externa.

## Papel dos motores SAST

### Psalm Taint

É o motor principal de dataflow PHP quando o problema depende de propagação entre source e sink. Findings taint válidos são bloqueantes.

### Semgrep

Complementa Psalm com:

- taint local orientado a profile;
- primitives perigosas;
- misuse/configuração;
- APIs específicas de framework.

Quando a vulnerabilidade depende de fluxo, preferir `mode: taint` a regras do tipo “qualquer variável é vulnerável”.

## Severidade, confiança e gate

Evidência SAST distingue pelo menos:

```text
DATAFLOW
DANGEROUS_PRIMITIVE
MISUSE
```

`severity` e `confidence` permanecem conceitos diferentes.

Para Semgrep:

```text
ERROR    bloqueia o gate
WARNING  hotspot para revisão; não bloqueia sozinho
```

O runner não usa `semgrep --error` para decidir política. A ferramenta retorna JSON e o Ninfa aplica a política sobre findings normalizados. Assim, WARNING continua visível sem ser transformado artificialmente em falha.

Erro do mecanismo ou cobertura parcial inesperada é falha operacional e bloqueia independentemente da severidade dos findings.

## Targeting e cobertura

O runner envia ao Semgrep somente os paths detectados pelo `ProjectContext` e usa `--no-git-ignore` para incluir arquivos novos ainda não rastreados pelo Git.

Exclusões deliberadas:

```text
**/vendor/**
**/runtime/**
**/public/assets/**
**/web/assets/**
```

O `SemgrepParser` normaliza:

```text
scanned
excluded_by_policy
unexpected_skips
errors
```

A cobertura é `complete` quando não existem `unexpected_skips` nem `errors`. O fato de um arquivo estar deliberadamente excluído continua auditável, mas não deve ser confundido com falha do scanner.

## Saída humana e artefato estruturado

A saída humana usa `CliStyle` e respeita uma única política global:

```text
NINFA_COLOR=auto   padrão
NINFA_COLOR=always
NINFA_COLOR=never
NO_COLOR           precedência absoluta
```

O terminal pode resumir cobertura, bloqueantes e hotspots, mas `security-report.json` continua sendo a representação estruturada destinada a automação e auditoria.

## SCA implementado

### SecurityInventory

A precedência de versões permanece:

```text
composer.lock
  ↓ fallback quando lock não existe
vendor/composer/installed.json
```

O inventário registra dependências, runtime PHP, constraint declarada, extensões, profile, paths e contexto GLPI quando aplicável.

### Composer Audit

Executa:

```text
composer --no-plugins --no-scripts --no-interaction audit --locked --format=json
```

Advisories são findings SCA; pacotes abandonados são policy findings.

### OSV

OSV é segunda fonte SCA sobre packages Composer resolvidos. A deduplicação preserva aliases e proveniência em vez de contar a mesma vulnerabilidade duas vezes.

## SCA versus SAST

Não unir SCA e SAST por inferência fraca. Um finding Semgrep genérico não prova reachability de CVE de dependência.

Estados futuros de exposure devem permitir algo como:

```text
confirmed
observed
not_observed
unknown
```

Ausência de uso observado não equivale a ausência de vulnerabilidade.

## Evolução de frameworks

A ordem de produto é:

```text
agora
  estabilizar php-generic/yii2/yii3/glpi-plugin

acompanhar
  Yii 22 dentro de yii2

futuro PHP
  Laravel

futuro multilíngue
  Python
    python-generic
    Django
    Flask
```

Laravel deve reutilizar o ecossistema PHP existente quando chegar sua vez, preferindo capabilities/APIs a profiles por major version. Python exigirá outro toolchain e, por isso, não deve ser antecipado por interfaces genéricas hoje.

## Critério de evolução

Uma nova regra/profile só deve avançar quando houver:

```text
regra válida
caso positivo
caso negativo quando aplicável
baixo falso positivo observado
coverage auditável
resultado normalizado
sem mutação do consumidor
```

Quantidade de regras não é métrica de qualidade. Dez regras válidas/testadas têm mais valor que dezenas de patterns ruidosos.
