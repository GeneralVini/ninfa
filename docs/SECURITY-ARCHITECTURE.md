# Arquitetura de segurança

Este documento registra a direção arquitetural de `ninfa security`. Ele descreve o alvo de evolução e separa explicitamente o que já existe do que ainda será implementado.

## Escopo atual

O pipeline público de segurança permanece restrito a:

```text
SCA   Composer Audit
SAST  Psalm Taint + Semgrep
```

DAST está fora do pipeline do Ninfa e é tratado por outra frente institucional.

A evolução SAST específica por profile está, nesta fase, limitada a:

```text
PHP common
├── Yii3
└── GLPI Plugin 11
```

Yii2 continua suportado pelo pipeline geral, mas não recebe agora um `SecurityContract` especializado. PHP genérico recebe apenas o baseline comum de PHP. Laravel, Symfony e outras linguagens/frameworks permanecem fora deste escopo e não devem provocar abstrações prematuras.

## Princípio arquitetural

O Ninfa deve separar três responsabilidades:

```text
SAST Contract
  define O QUE caracteriza a classe de vulnerabilidade

Profile SecurityContract
  define O QUE as APIs e convenções do profile significam em segurança

Tool Adapter
  define COMO traduzir o contrato para Psalm, Semgrep ou outro motor
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
ProfileContract
  ↓
SecurityContract
  ↓
Tool adapters
  ├── Psalm
  └── Semgrep
  ↓
Finding normalizado
```

O detector responde "que projeto é este?". O contrato responde "o que significa seguro neste ecossistema?".

## Contratos SAST

O domínio SAST deve possuir contratos explícitos para as classes que o Ninfa pretende cobrir:

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

Cada contrato comum deve definir, quando aplicável:

- sources;
- sinks;
- sanitizers/escapes;
- propagators;
- dangerous primitives;
- safe patterns;
- tipos de evidência aceitos;
- proveniência e identificação da regra.

As especializações de profile complementam o contrato comum somente quando existe semântica própria do ecossistema.

Exemplo conceitual:

```text
security.sql-injection
        +
yii3/sql-injection
        ↓
request sources Yii3
DB sinks Yii3
APIs de binding/prepared statements
```

Para GLPI Plugin 11:

```text
security.sql-injection
        +
glpi-plugin-11/sql-injection
        ↓
inputs do ecossistema GLPI
APIs $DB / wrappers relevantes
operações consideradas seguras pelo contrato
```

Nem todo profile precisa sobrescrever os doze contratos. `dangerous-eval-assert`, por exemplo, tende a permanecer majoritariamente comum; SQL injection, XSS, SSRF, redirect e headers tendem a exigir mais conhecimento de framework.

## Estrutura-alvo das regras

A organização desejada é:

```text
security/
├── contracts/
│   ├── sast/
│   │   ├── common/
│   │   │   ├── command-injection.yml
│   │   │   ├── sql-injection.yml
│   │   │   ├── xss.yml
│   │   │   ├── path-traversal.yml
│   │   │   ├── file-access.yml
│   │   │   ├── ssrf.yml
│   │   │   ├── unsafe-redirect.yml
│   │   │   ├── header-injection.yml
│   │   │   ├── dynamic-include-require.yml
│   │   │   ├── unsafe-deserialization.yml
│   │   │   ├── dangerous-eval-assert.yml
│   │   │   └── cryptographic-misuse.yml
│   │   └── profiles/
│   │       ├── yii3/
│   │       └── glpi-plugin-11/
│   └── sca/
│
├── semgrep/
│   ├── common/
│   └── profiles/
│       ├── yii3/
│       └── glpi-plugin-11/
│
├── psalm/
│   ├── common/
│   └── profiles/
│       ├── yii3/
│       └── glpi-plugin-11/
│
└── fixtures/
    ├── sast/
    │   ├── common/
    │   └── profiles/
    │       ├── yii3/
    │       └── glpi-plugin-11/
    └── sca/
```

Essa é uma estrutura-alvo. Não se deve criar arquivos vazios apenas para materializar diretórios antes de existir contrato testável.

## Papel dos motores SAST

Nesta fase não será adicionado um terceiro scanner SAST.

### Psalm Taint

Motor principal de dataflow PHP. Deve assumir prioridade quando o problema depende de fluxo de dados entre source e sink, como command injection, SQL injection, XSS, file/path, SSRF, headers, include, unserialize e eval.

### Semgrep

Complementa Psalm em três frentes:

- primitives perigosas mesmo quando o taint não foi comprovado;
- padrões e APIs específicas do profile;
- regras de misuse/configuração, especialmente criptografia e constructs perigosos.

Sempre que possível, regras que hoje são apenas pattern matching devem evoluir para taint quando a classe realmente depende de fluxo de dados.

## Tipos de evidência SAST

Findings devem distinguir pelo menos:

```text
DATAFLOW
  source → sink comprovado

DANGEROUS_PRIMITIVE
  API/construct perigoso encontrado, sem fluxo externo comprovado

MISUSE
  uso semanticamente inseguro ou configuração inadequada
```

`severity` e `confidence` devem permanecer conceitos distintos. Um primitive perigoso pode ter impacto alto, mas confiança menor do que um dataflow comprovado.

## Yii3 como primeiro contrato de framework

Yii3 será o primeiro framework usado para provar a arquitetura `ProfileContract → SecurityContract → adapters`.

O detector atual identifica Yii3 por sinais de pacotes, mas a evolução de segurança precisa resolver capacidades reais do projeto, por exemplo:

```text
web-request
database
view/html
http-client
redirect/response
filesystem
console
```

O objetivo não é espalhar `if profile == yii3` pelos scanners. O contexto resolve capabilities e o `SecurityContract` fornece sources, sinks, sanitizers e semântica das APIs aplicáveis.

GLPI Plugin 11 seguirá o mesmo princípio, usando conhecimento específico do host e das APIs relevantes do ecossistema GLPI.

## Fixtures obrigatórias

Cada contrato SAST implementado deve possuir testes que provem comportamento e evitem regressões. O conjunto mínimo desejado por classe é:

```text
positive-direct
positive-indirect
negative-sanitized
negative-constant
negative-profile-safe   # quando aplicável
```

O objetivo é medir cobertura demonstrável das classes, não simplesmente aumentar o número de regras.

## SCA: inventário antes de novas fontes

A evolução SCA deve começar por um `SecurityInventory` confiável.

Fontes locais previstas:

```text
composer.lock                 versões efetivamente resolvidas
composer.json                 constraints, dependências diretas e plataforma
vendor/composer/installed.json  evidência complementar, quando disponível
runtime PHP real
constraint PHP declarada
extensões requeridas/instaladas
profile
paths/contexto
```

`composer.lock` é a fonte preferencial para versões de packages. `composer.json` não deve ser usado como substituto da versão efetivamente instalada.

A versão PHP declarada e o runtime real precisam ser campos diferentes. Uma constraint como `8.2 - 8.5` não pode ser reduzida a `8.2` e tratada como versão do runtime.

## Fontes SCA por fase

### Fase inicial

```text
Composer Audit
OSV
```

Composer Audit permanece como fonte nativa do ecossistema PHP. A integração deve evoluir para saída estruturada e execução defensiva, sem plugins/scripts do consumidor quando não forem necessários.

OSV entra depois que o inventário estiver estruturado, preferencialmente em batch para os packages do `composer.lock`.

### Enrichment posterior

Somente após normalização e deduplicação devem entrar fontes como:

```text
EPSS
CISA KEV
NVD quando agregar metadado confiável
public exploit evidence
```

GitHub Advisory não deve ser adicionado de imediato como terceiro detector primário apenas para repetir advisories já presentes em Composer/OSV. Pode servir como enrichment/fallback quando houver valor adicional verificável.

ExploitDB/SearchSploit não é detector primário. Exploit público apenas enriquece uma vulnerabilidade já identificada e normalizada.

## Normalização e deduplicação SCA

A mesma vulnerabilidade pode aparecer como CVE, GHSA, PKSA, OSV ou outro alias. O Ninfa deve consolidar essas identidades antes de enrichment.

Exemplo conceitual:

```json
{
  "canonical_id": "CVE-2026-1234",
  "aliases": ["GHSA-...", "PKSA-..."],
  "sources": ["composer-audit", "osv"]
}
```

Uma fonte adicional confirma ou enriquece o finding; não cria automaticamente um segundo problema.

## Severity, exploitability, exposure e priority

Esses conceitos não devem ser misturados.

### Severity

É propriedade técnica do advisory/vulnerabilidade. O Ninfa deve preservar a severidade oficial, preferindo CVSS v4 quando disponível e CVSS v3.1 como fallback. O Ninfa não deve alterar a severity oficial com score próprio.

### Exploitability

Representa evidência/likelihood de exploração. EPSS é probabilidade, não severity. KEV e exploit público são sinais relacionados e não devem ser somados ingenuamente como se fossem independentes.

### Exposure

Representa evidência específica do projeto analisado. Ausência de reachability observada não significa ausência de vulnerabilidade.

Estados previstos:

```text
confirmed
observed
not_observed
unknown
```

Dependência direta ou transitiva deve permanecer atributo de inventário/remediação; não é, por si só, prova de exposição.

SAST só deve enriquecer um finding SCA quando existir correlação demonstrável, por exemplo função vulnerável conhecida → símbolo/call path observado. Um finding Semgrep genérico não prova reachability de um CVE de dependência.

### Ninfa Priority

`Vendor/Advisory Severity` e `Ninfa Priority` são conceitos independentes.

Uma decomposição futura pode usar aproximadamente:

```text
Severity       0–50
Exploitability 0–30
Exposure       0–20
```

mas o score não deve virar gate enquanto inventário, aliases, enrichment e exposure não estiverem suficientemente confiáveis e calibrados em projetos reais.

As classes operacionais previstas são distintas do CVSS:

```text
90–100  IMMEDIATE
70–89   URGENT
40–69   REVIEW
20–39   LOW PRIORITY
0–19    INFORMATIONAL
```

## Relatório estruturado

O objetivo é produzir um artefato canônico, por exemplo:

```text
/tmp/ninfa/<hash>/security-report.json
```

com inventário, coverage/source status, findings normalizados e resumo. O terminal deve ser um renderer desse modelo, não a fonte primária de verdade.

O finding deve conseguir representar, conforme aplicável:

```text
identity / aliases
component + installed version
source provenance
severity/CVSS
exploitability enrichment
exposure evidence
remediation
confidence
priority futura
```

## semantic-index.json

O `semantic-index.json` atual é documental: ele deriva principalmente de README/AGENTS/docs e símbolos citados em documentação. Ele não representa chamadas reais, reachability ou uso efetivo de funções no código.

Portanto ele não pode ser usado como evidência de exposure.

Uma futura análise de exposure exigirá índice de código/call evidence próprio, com símbolos declarados/referenciados, calls e entrypoints quando essa precisão for necessária.

## Runtime PHP e limites de responsabilidade

O Ninfa deve distinguir:

```text
Composer ecosystem vulnerability
PHP runtime vulnerability
native library vulnerability
operating-system vulnerability
```

Para packages Composer, matching por package+version no ecossistema é preferível a matching textual/CPE.

Runtime PHP pode ser analisado futuramente se versão real e fonte de vulnerabilidade forem correlacionadas de forma confiável.

Extensões como `ext-curl`, `ext-openssl`, `ext-libxml`, `ext-gd` e `ext-imagick` não devem ser associadas automaticamente a CVEs de bibliotecas nativas apenas pelo nome ou pela versão da extensão. Quando a biblioteca nativa real não puder ser identificada com confiança, o estado deve permanecer desconhecido em vez de gerar finding especulativo.

## Cache, rede e privacidade

Antes de ampliar fontes online, o Ninfa deve possuir cache externo e estados explícitos de degradação.

Princípios:

- batch quando a fonte suportar;
- não consultar repetidamente o mesmo package/CVE na mesma execução;
- timeouts e rate limits explícitos;
- indisponibilidade de enrichment não equivale a ausência de vulnerabilidade;
- nenhuma falha de fonte externa deve virar finding de vulnerabilidade;
- não enviar código fonte para serviços de vulnerability intelligence;
- consultas externas devem se limitar preferencialmente a package/version, IDs de advisory e runtime version.

## Ordem de implementação

A sequência recomendada é:

1. consolidar `Finding`, `ToolResult` e `RunResult`;
2. criar `SecurityInventory` e separar runtime PHP de constraint;
3. estruturar Composer Audit e produzir finding SCA normalizado;
4. estruturar Psalm Taint e Semgrep em findings SAST;
5. criar os contratos SAST comuns e fixtures das classes implementadas;
6. implementar capabilities + `SecurityContract` de Yii3;
7. implementar especializações de GLPI Plugin 11;
8. integrar OSV em batch e deduplicar aliases;
9. produzir `security-report.json` canônico e renderers;
10. só depois adicionar EPSS/KEV e calibrar exploitability;
11. só depois implementar exposure/reachability correlacionada;
12. somente com dados reais calibrar e ativar Ninfa Priority;
13. NVD, exploit enrichment e outras fontes ficam para fase posterior quando agregarem evidência confiável.

Não avançar para dezenas de scanners, scheduler de segurança, score operacional ou reachability sofisticada antes de estabilizar os contratos de resultado e as fixtures.

## Critério de qualidade

O objetivo de `ninfa security` não é maximizar quantidade de CVEs ou findings. O comando deve conseguir responder, com evidência auditável:

```text
O que foi analisado?
O que foi encontrado?
A versão instalada está no intervalo afetado?
Qual é a severidade técnica?
Que evidência de exploração existe?
Que evidência de exposição existe neste projeto?
Existe correção conhecida?
Quais fontes falharam ou ficaram indisponíveis?
```

Quando a evidência não permitir uma conclusão, o resultado deve dizer `unknown` ou `partial`, não inventar certeza.
