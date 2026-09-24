# Segurança

O Ninfa mantém segurança separada do pipeline comum de qualidade. O escopo ativo é SCA + SAST; DAST foi retirado do pipeline público porque a análise dinâmica é atendida por uma frente institucional especializada.

## Preparação das ferramentas

No repositório do Ninfa, execute:

```bash
make security-tools
```

Esse target instala o Semgrep no ambiente gerenciado do próprio Ninfa, em `.tools/semgrep`, sem alterar o projeto consumidor.

As regras do próprio Ninfa são validadas separadamente por:

```bash
make semgrep-rules
```

O target garante o Semgrep homologado e executa:

```text
semgrep --validate --config security/semgrep
semgrep --test --config security/semgrep security/semgrep-tests
```

O `make setup` executa preparação da ferramenta, validação/testes das regras, sintaxe e a suíte interna. OWASP ZAP não é instalado pelo fluxo oficial.

## Comando

```bash
ninfa security /caminho/do/projeto
```

O baseline ativo considera:

```text
Composer Audit        # quando houver composer.lock
OSV                   # packages resolvidos do inventário Composer
Psalm Taint Analysis
Semgrep
```

As etapas independentes continuam mesmo quando uma delas encontra bloqueio ou falha. O resumo final distingue sucesso, finding bloqueante, hotspot, erro de execução, indisponibilidade e cobertura parcial.

## Profiles de segurança

O ecossistema ativo é PHP e possui quatro profiles oficiais:

```text
PHP common
├── php-generic
├── yii2
├── yii3
└── glpi-plugin
```

`php-generic` usa somente o baseline PHP comum. Yii2, Yii3 e GLPI Plugin 11 combinam esse baseline com overlays próprios.

Yii 22 é acompanhado como evolução da família Yii2. Não existe profile `yii22` enquanto não houver diferença técnica concreta que exija regras/capabilities distintas. Laravel permanece roadmap PHP. Python permanece futuro ecossistema e não participa do pipeline atual.

O Ninfa não usa SAST para impor estilo arquitetural. Repository, DTO, DDD, Clean Architecture e Vertical Slice não são requisitos dos profiles; regras devem representar bugs, vulnerabilidades, misuse ou contratos observáveis do framework.

## Inventário de segurança

Antes dos scanners, `ninfa security` grava `security-inventory.json` no workspace externo. O inventário separa:

- constraint PHP declarada e runtime PHP real;
- extensões requeridas e carregadas;
- dependências runtime/dev;
- dependências diretas/transitivas;
- `composer.lock` como fonte preferencial de versões resolvidas;
- `vendor/composer/installed.json` apenas como fallback quando o lock não existe;
- profile e contexto de host quando aplicável.

## Composer Audit

Executa Composer Audit somente quando o projeto possui `composer.lock`:

```text
composer --no-plugins --no-scripts --no-interaction audit --locked --format=json
```

Advisories retornados em JSON são normalizados em `Finding` SCA. Pacotes abandonados permanecem `dependency-policy` e não são apresentados como vulnerabilidade.

JSON inválido, falha de rede ou término não-zero sem finding normalizável são erros de execução; não equivalem a ausência de vulnerabilidades.

## OSV

OSV consulta o inventário resolvido usando package + versão no ecossistema Packagist. A consulta em batch é correlacionada por componente e os IDs são convertidos em `Finding` SCA.

Composer Audit e OSV podem representar a mesma vulnerabilidade com IDs diferentes. O Ninfa consolida aliases, preferindo identificadores estáveis como CVE, GHSA, PKSA e OSV sem inventar score próprio.

## Relatório estruturado

`ninfa security` grava:

```text
/tmp/ninfa/<hash>/security-report.json
```

O terminal é feedback operacional. O JSON é a representação estruturada para automação, auditoria e evolução posterior.

## Psalm Taint

Psalm Taint reutiliza a configuração Psalm gerada no workspace externo e atua como motor principal para problemas que dependem de dataflow entre source e sink.

Se um finding taint válido for produzido, ele é bloqueante. Falha do processo sem finding estruturado é tratada como erro da ferramenta, não como resultado limpo.

## Semgrep

A estrutura atual é:

```text
security/semgrep/
├── common.yml
└── profiles/
    ├── yii2.yml
    ├── yii3.yml
    └── glpi-plugin-11.yml
```

As fixtures ficam em árvore paralela:

```text
security/semgrep-tests/
├── common.php
└── profiles/
    ├── yii2.php
    ├── yii3.php
    └── glpi-plugin-11.php
```

Regras de fluxo usam `mode: taint` quando a evidência relevante é source → sink. Padrões de primitive/misuse permanecem regras sintáticas quando isso produz sinal mais confiável.

### Política de severidade

```text
ERROR    finding bloqueante
WARNING  hotspot para revisão; não bloqueia sozinho
```

A presença de um `WARNING` não é automaticamente chamada de vulnerabilidade confirmada. Erro do mecanismo ou cobertura parcial inesperada continua bloqueante independentemente da severidade dos findings.

### Cobertura

O Semgrep recebe somente os paths autorizados pelo `ProjectContext`. O comando usa `--no-git-ignore`, portanto um arquivo novo dentro desses paths também é analisado antes do `git add`.

Exclusões operacionais são explícitas, por exemplo:

```text
**/vendor/**
**/runtime/**
**/public/assets/**
**/web/assets/**
```

O parser separa:

```text
excluded_by_policy   exclusões deliberadas
unexpected_skips     arquivos não analisados sem justificativa esperada
errors               erros reportados pelo mecanismo
```

Somente skips inesperados ou erros tornam a cobertura `partial`. Exclusão deliberada continua registrada para auditoria, mas não é tratada como falha de cobertura.

## Contratos SAST

O Ninfa mantém doze contratos canônicos:

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

A separação arquitetural é:

```text
SAST Contract
  define O QUE caracteriza a vulnerabilidade

Profile SecurityContract
  define O QUE as APIs do profile significam

Tool Adapter
  define COMO Psalm/Semgrep executam a análise
```

Nem todo contrato precisa de regra específica de todos os profiles. O baseline comum deve permanecer responsável pelo que é semântica PHP; overlays entram apenas quando a API do framework muda o significado do fluxo ou do sink.

### Yii2

O overlay Yii2 acrescenta semântica de Request/Response, DB/Command, encoding HTML, redirects, headers e filesystem. A intenção é reconhecer APIs próprias do framework sem transformar qualquer uso de variável em vulnerabilidade.

Yii 22 continua nessa família. Quando houver release estável e diferenças relevantes de API, a evolução deve preferir capabilities/condições localizadas em vez de duplicar todo o profile.

### Yii3

O overlay Yii3 cobre semântica própria de Yii DB, PSR-7 request/response, saída HTML e headers/redirects. Bypass de encoding como `encode(false)` ou `NoEncode` é hotspot, não prova isolada de XSS.

CSRF não deve ser reduzido a uma regra ingênua do tipo “todo POST precisa conter `_csrf`”. Controles de middleware/configuração só devem entrar quando existir padrão estático confiável.

### GLPI Plugin 11

O overlay GLPI conhece `$DB`, `Html::redirect`, `Toolbox::getURLContent`, `Toolbox::getFileContent` e contexto do host. O scan permanece sobre o plugin consumidor; o host é contexto para análise, não alvo indiscriminado do Semgrep.

## Evidência SAST

Findings distinguem pelo menos:

```text
DATAFLOW
DANGEROUS_PRIMITIVE
MISUSE
```

`severity` e `confidence` são conceitos distintos. Uma primitive perigosa pode exigir revisão sem ter a mesma confiança de um caminho source → sink comprovado.

## Saída visual

O Ninfa usa exclusivamente `CliStyle` e a política global:

```bash
NINFA_COLOR=auto
```

Também existem `always`, `never` e `NO_COLOR`. Não há variável específica para Semgrep.

O resumo Semgrep apresenta arquivos analisados, exclusões por política, skips inesperados e erros do mecanismo. Símbolos/cores seguem [CORES.md](CORES.md).

## DAST

DAST está fora do pipeline público. Se `NINFA_DAST=1` for informado, o Ninfa apenas avisa que a capacidade está delegada e não executa OWASP ZAP.

`scripts/zap-scan.sh` permanece artefato congelado para referência ou uso manual controlado.

## Interpretação dos resultados

Exit code 0 significa apenas que as fontes executadas não produziram bloqueios e que a cobertura observada não apresentou falha inesperada. Não significa:

```text
sistema seguro
sem vulnerabilidades
cobertura absoluta
aprovação por todos os controles possíveis
```

Findings devem ser investigados e corrigidos ou justificados de forma localizada; suppressions globais destinadas apenas a liberar pipeline não são aceitáveis.

## Roadmap

A ordem planejada é:

```text
agora
  estabilizar php-generic + yii2 + yii3 + glpi-plugin

acompanhar
  Yii 22 dentro da família yii2

próximo candidato PHP
  Laravel

futuro ecossistema
  Python
    python-generic
    Django
    Flask
```

Não criar abstrações multilíngues antecipadamente. A separação de ecossistema deve surgir quando houver pelo menos dois ecossistemas reais a suportar.

A arquitetura detalhada está em [SECURITY-ARCHITECTURE.md](SECURITY-ARCHITECTURE.md).
