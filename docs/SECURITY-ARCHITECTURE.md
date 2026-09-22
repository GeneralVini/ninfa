# Arquitetura de segurança

Este documento registra a direção arquitetural de `ninfa security`, o que já está implementado e o que permanece como evolução. O README é o painel rápido de progresso; este arquivo preserva os contratos e limites arquiteturais.

## Escopo atual

O pipeline público de segurança está restrito a:

```text
SCA   Composer Audit + OSV
SAST  Psalm Taint + Semgrep
```

DAST está fora do pipeline do Ninfa e é tratado por outra frente institucional.

A especialização SAST por profile, nesta fase, está limitada a:

```text
PHP common
├── Yii3
└── GLPI Plugin 11
```

Yii2 continua suportado pelo pipeline geral, mas não recebe agora um `SecurityContract` especializado. `php-generic` recebe apenas o baseline comum. Outros frameworks e linguagens permanecem fora do escopo ativo e não devem provocar abstrações prematuras.

## Estado da arquitetura

A fundação e o SCA estruturado estão implementados:

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

Os resultados SAST estão estruturados; a próxima etapa formaliza contratos por profile sem ampliar scanners.

## Princípio arquitetural SAST

O Ninfa separa três responsabilidades:

```text
SAST Contract
  define O QUE caracteriza a classe de vulnerabilidade

Profile SecurityContract
  define O QUE as APIs e convenções do profile significam em segurança

Tool Adapter
  define COMO traduzir a semântica para Psalm ou Semgrep
```

O conhecimento de que uma API é source, sink, sanitizer, primitive perigosa ou operação segura pertence ao contrato do Ninfa, não ao adapter da ferramenta.

Conceitualmente:

```text
Project
  ↓
ProfileDetector
  ↓
ProjectContext / capabilities
  ↓
Profile SecurityContract
  ↓
Tool adapters
  ├── Psalm
  └── Semgrep
  ↓
Finding normalizado
```

O detector responde “que projeto é este?”. O contrato responde “o que significa seguro neste ecossistema?”.

## Contratos SAST

O domínio SAST deve possuir contratos explícitos para estas doze classes:

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

Cada contrato comum deve definir, quando aplicável, sources, sinks, sanitizers/escapes, propagators, dangerous primitives, safe patterns, tipos de evidência e proveniência. As especializações de profile complementam o contrato comum somente quando existe semântica própria do ecossistema.

Exemplo:

```text
security.sql-injection
        +
yii3/sql-injection
        ↓
request sources Yii3
DB sinks Yii3
binding/prepared operations seguras
```

Para GLPI Plugin 11, o overlay deve representar inputs e APIs do host/plugin, inclusive wrappers de banco e operações consideradas seguras pelo contrato.

Nem todo profile precisa sobrescrever os doze contratos. SQL injection, XSS, SSRF, redirect e headers tendem a exigir mais conhecimento do framework; primitives como `eval/assert` tendem a permanecer majoritariamente comuns.

## Estrutura-alvo das regras

```text
security/
├── contracts/
│   └── sast/
│       ├── common/
│       │   ├── command-injection.yml
│       │   ├── sql-injection.yml
│       │   ├── xss.yml
│       │   ├── path-traversal.yml
│       │   ├── file-access.yml
│       │   ├── ssrf.yml
│       │   ├── unsafe-redirect.yml
│       │   ├── header-injection.yml
│       │   ├── dynamic-include-require.yml
│       │   ├── unsafe-deserialization.yml
│       │   ├── dangerous-eval-assert.yml
│       │   └── cryptographic-misuse.yml
│       └── profiles/
│           ├── yii3/
│           └── glpi-plugin-11/
├── semgrep/
│   ├── common/
│   └── profiles/
│       ├── yii3/
│       └── glpi-plugin-11/
├── psalm/
│   ├── common/
│   └── profiles/
│       ├── yii3/
│       └── glpi-plugin-11/
└── fixtures/
    └── sast/
        ├── common/
        └── profiles/
            ├── yii3/
            └── glpi-plugin-11/
```

Essa é uma estrutura-alvo. Não criar arquivos vazios apenas para materializar diretórios antes de existir contrato testável.

## Papel dos motores SAST

Nesta fase não será adicionado um terceiro scanner SAST.

### Psalm Taint

É o motor principal de dataflow PHP. Deve assumir prioridade quando o problema depende de fluxo entre source e sink, como command injection, SQL injection, XSS, file/path, SSRF, headers, include, unserialize e eval.

### Semgrep

Complementa Psalm em primitives perigosas, padrões/APIs específicas do profile e misuse/configuração, especialmente criptografia e constructs perigosos. Onde a vulnerabilidade realmente depender de fluxo de dados, regras relevantes devem evoluir para taint quando isso reduzir falso positivo e aumentar cobertura.

## Evidência SAST

Findings SAST devem distinguir pelo menos:

```text
DATAFLOW
  source → sink comprovado

DANGEROUS_PRIMITIVE
  API/construct perigoso encontrado sem fluxo externo comprovado

MISUSE
  uso semanticamente inseguro ou configuração inadequada
```

`severity` e `confidence` permanecem conceitos distintos. Um primitive perigoso pode ter impacto alto e confiança menor que um dataflow comprovado.

## Yii3 e GLPI Plugin 11

Yii3 será o primeiro framework usado para provar a arquitetura `Profile SecurityContract → adapters`.

O detector identifica o profile; a evolução de segurança precisa resolver capabilities reais, por exemplo:

```text
web-request
database
view/html
http-client
redirect/response
filesystem
console
```

O objetivo é evitar condicionais espalhadas como `if profile == yii3` dentro dos scanners. O contexto resolve capabilities, o `SecurityContract` fornece sources/sinks/sanitizers e os adapters traduzem isso para Psalm/Semgrep.

GLPI Plugin 11 seguirá o mesmo princípio, incorporando conhecimento específico do host e das APIs relevantes do ecossistema GLPI.

## Fixtures SAST

Cada contrato implementado deve possuir evidência positiva e negativa. O conjunto mínimo desejado por classe é:

```text
positive-direct
positive-indirect
negative-sanitized
negative-constant
negative-profile-safe   # quando aplicável
```

O objetivo é medir cobertura demonstrável, não aumentar apenas a quantidade de regras.

## SCA implementado

### SecurityInventory

O inventário é gerado antes dos scanners e preserva, entre outros dados:

```text
composer.lock                   versões efetivamente resolvidas
composer.json                   constraints e dependências diretas
vendor/composer/installed.json  fallback quando necessário
runtime PHP real
constraint PHP declarada
extensões requeridas/carregadas
profile
paths/contexto
```

`composer.lock` é a fonte preferencial para versões de packages. A versão PHP declarada e o runtime real são campos distintos.

### Composer Audit

É a fonte nativa do ecossistema PHP e roda de forma defensiva/estruturada:

```text
composer --no-plugins --no-scripts --no-interaction audit --locked --format=json
```

Advisories e políticas de dependência são normalizados em `Finding`. Pacotes abandonados são `dependency-policy`, não vulnerabilidades.

### OSV

OSV é a segunda fonte SCA. O fluxo implementado é:

```text
SecurityInventory packages
       ↓
POST /v1/querybatch
  package + version + Packagist
       ↓
IDs de vulnerabilidade
       ↓
GET /v1/vulns/{id}
       ↓
Finding SCA
```

A implementação usa batch, acompanha `next_page_token` e evita buscar repetidamente o mesmo ID dentro da execução. Consultas externas usam somente package/versão e identificadores; código fonte não é enviado.

Quando não existem packages Composer resolvidos, OSV é não aplicável. Falha de rede/HTTP/JSON é erro de execução, não evidência de ausência de vulnerabilidades.

## Normalização e deduplicação SCA

A mesma vulnerabilidade pode aparecer como CVE, GHSA, PKSA, OSV ou outro alias. O Ninfa consolida essas identidades antes de enrichment.

A preferência atual de identidade canônica é:

```text
CVE
GHSA
PKSA
OSV
outro ID estável
```

O registro canônico preserva aliases, fontes, provenance, componentes afetados e severidade observada. Uma segunda fonte confirma/enriquece a vulnerabilidade; não cria automaticamente um segundo problema no relatório consolidado.

Exemplo conceitual:

```json
{
  "canonical_id": "CVE-2026-1234",
  "aliases": ["GHSA-...", "PKSA-..."],
  "sources": ["composer-audit", "osv"]
}
```

## Relatório estruturado

`ninfa security` produz no workspace externo:

```text
security-inventory.json
security-report.json
```

`security-report.json` possui `schema_version`, inventário, estado das fontes SCA, findings de origem, policy findings, vulnerabilidades canônicas e exit code consolidado. Esse artefato é a base estruturada para automação futura; o terminal permanece como feedback operacional/renderização.

## Intelligence posterior

Somente após a normalização/deduplicação entram enrichers como:

```text
EPSS
CISA KEV
NVD quando agregar metadado confiável
public exploit evidence
```

GitHub Advisory não deve ser adicionado agora como terceiro detector primário apenas para repetir Composer/OSV. NVD não deve fazer matching textual de package Composer para CPE. ExploitDB/SearchSploit não é detector primário.

## Severity, exploitability, exposure e priority

Esses conceitos não devem ser misturados.

### Severity

É propriedade técnica do advisory. O Ninfa preserva a severidade da fonte e não deve substituí-la por score próprio.

### Exploitability

Representa likelihood/evidência de exploração. EPSS é probabilidade; KEV e exploit público são sinais relacionados e não devem ser somados ingenuamente como independentes.

### Exposure

Representa evidência específica do projeto. Dependência direta/transitiva é atributo de inventário/remediação e não, isoladamente, prova de exposição.

Estados previstos:

```text
confirmed
observed
not_observed
unknown
```

SAST só deve enriquecer um finding SCA quando existir correlação demonstrável, como função vulnerável conhecida → símbolo/call path observado. Um finding Semgrep genérico não prova reachability de um CVE de dependência.

### Ninfa Priority

`Vendor/Advisory Severity` e `Ninfa Priority` são independentes. Uma decomposição futura pode considerar severity, exploitability e exposure, mas o score não deve virar gate antes de dados reais e contratos confiáveis.

## semantic-index.json

O `semantic-index.json` atual é documental: deriva principalmente de README/AGENTS/docs e símbolos citados em documentação. Ele não representa calls, reachability ou uso real de funções e não pode ser usado como evidência de exposure.

Uma futura análise de exposure exigirá índice de código/call evidence próprio.

## Runtime PHP e limites de responsabilidade

O Ninfa deve distinguir:

```text
Composer ecosystem vulnerability
PHP runtime vulnerability
native library vulnerability
operating-system vulnerability
```

Para packages Composer, matching por package+version no ecossistema é preferível a matching textual/CPE. Extensões PHP não devem ser associadas automaticamente a CVEs de bibliotecas nativas por semelhança de nome ou versão.

## Cache, rede e privacidade

O SCA já usa batch para OSV e elimina consultas duplicadas do mesmo ID na mesma execução. Cache persistente, retry e políticas mais ricas de rate limit permanecem evolução posterior.

Indisponibilidade de fonte externa não equivale a ausência de vulnerabilidade. Nenhuma falha de fonte deve virar finding de vulnerabilidade. Vulnerability intelligence externa deve receber apenas os identificadores mínimos necessários, nunca código fonte.

## Roadmap por etapas

### Etapa 1 — Fundação — concluída

- `Finding`, `ToolResult`, `RunResult`.
- `SecurityInventory` e separação runtime/constraint.

### Etapa 2 — SCA estruturado — concluída

- Composer Audit estruturado em `Finding`.
- OSV em batch.
- deduplicação canônica por aliases.
- `security-report.json`.

### Etapa 3 — SAST estruturado — concluída

- Psalm Taint → `Finding` SAST de dataflow.
- Semgrep → `Finding` SAST de pattern.
- estados explícitos de finding/erro/indisponibilidade/não aplicabilidade/cobertura parcial.
- fixtures positivas/negativas na suíte.
- cobertura observável por scanner e intelligence no `security-report.json` schema 3.
- regra, severity, confidence, localização, evidência e provenance preservadas.

### Etapa 4 — Contratos SAST e profiles — concluída

- formalizar os doze contratos comuns.
- capabilities + `SecurityContract` de Yii3.
- especializações de GLPI Plugin 11.

### Etapa 5 — Intelligence — concluída

- EPSS e CISA KEV sobre CVEs canônicos.
- NVD/exploit evidence somente quando agregarem evidência confiável.

### Etapa 6 — Exposure, prioridade e gates

- índice real de código/calls.
- correlação SCA ↔ código apenas com evidência demonstrável.
- Exposure separado de direct/transitive.
- calibrar Ninfa Priority.
- quality gates depois da estabilização.

### Etapa 7 — Evolução posterior

- baseline/new-code e diff-aware.
- DAG/scheduler/paralelismo.
- automações avançadas e dashboard/histórico.

## Critério de qualidade

O objetivo de `ninfa security` não é maximizar quantidade de CVEs ou findings. O comando deve conseguir responder com evidência auditável o que foi analisado, o que foi encontrado, quais versões/componentes foram correlacionados, quais fontes participaram e quais falharam.

Quando a evidência não permitir conclusão, o resultado deve permanecer `unknown`/parcial em vez de fabricar certeza.
