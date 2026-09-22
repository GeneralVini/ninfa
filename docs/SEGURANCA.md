# Segurança

O Ninfa mantém segurança separada do pipeline comum de qualidade. O escopo ativo é SCA + SAST; DAST foi retirado do pipeline público porque a análise dinâmica é atendida por uma frente institucional especializada.

## Preparação das ferramentas

No repositório do Ninfa, execute:

```bash
make security-tools
```

Esse target instala o Semgrep no ambiente gerenciado do próprio Ninfa, em `.tools/semgrep`, sem alterar o projeto consumidor.

OWASP ZAP não é mais instalado pelo fluxo oficial. Se `NINFA_INSTALL_ZAP=1` for informado, o instalador emite aviso e ignora a solicitação.

O comando `make setup` executa `security-tools`, valida sintaxe e roda a suíte interna do Ninfa.

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

As etapas independentes continuam mesmo quando uma delas encontra um bloqueio ou falha. O resumo final distingue sucesso, falha, erro de execução e etapa ignorada; o exit code do comando preserva a primeira falha observada.

## Diretriz de maturidade

| Frente | Estado | Diretriz |
|---|---|---|
| SCA | inventário + Composer Audit + OSV + deduplicação/report estruturados | estrutura base concluída; enrichment fica para depois |
| SAST | findings e cobertura estruturados | etapa estrutural concluída |
| DAST | fora do pipeline | delegado; evolução congelada |

O foco do Ninfa passa agora a amadurecer SAST por profile sem transformar o projeto em um agregador indiscriminado de scanners.

A especialização SAST ativa nesta fase está limitada a:

```text
PHP common
├── Yii3
└── GLPI Plugin 11
```

Yii2 continua suportado pelo pipeline geral, mas não recebe agora `SecurityContract` específico. PHP genérico usa o baseline comum. Outros frameworks permanecem fora do escopo ativo.

A arquitetura detalhada, os contratos SAST, a evolução SCA e a ordem de implementação estão em [SECURITY-ARCHITECTURE.md](SECURITY-ARCHITECTURE.md).

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

Executa Composer Audit somente quando o projeto possui `composer.lock`. A chamada é feita em modo estruturado e defensivo, sem carregar plugins ou scripts do consumidor:

```text
composer --no-plugins --no-scripts --no-interaction audit --locked --format=json
```

Os advisories retornados em JSON são normalizados em `Finding` SCA. Cada finding preserva, quando disponível:

- package e versão resolvida;
- relação direta ou transitiva;
- escopo runtime ou dev;
- advisory principal e aliases, como CVE/GHSA/PKSA;
- severidade informada pela fonte;
- intervalo afetado, link e data do advisory;
- proveniência da fonte.

Pacotes abandonados reportados pelo Composer também são estruturados, mas como `dependency-policy`: abandono de dependência não é apresentado como vulnerabilidade.

JSON inválido, falha de rede ou término não-zero sem finding normalizável são tratados como erro de execução, e não como evidência de ausência de vulnerabilidades.

## OSV

OSV é a segunda fonte SCA do Ninfa. A consulta usa o inventário resolvido em batch:

```text
package name + installed version + ecosystem Packagist
        ↓
POST /v1/querybatch
        ↓
IDs de vulnerabilidade
        ↓
GET /v1/vulns/{id}
        ↓
Finding SCA normalizado
```

A resposta de `querybatch` é correlacionada pela posição da consulta e o Ninfa acompanha `next_page_token` quando houver paginação. Cada ID é buscado uma única vez por execução e depois associado aos componentes que o originaram.

Os findings OSV preservam package, versão, relação, escopo, aliases, severidade quando disponível, referência e proveniência. O Ninfa envia para OSV somente package/versão; código fonte não é enviado.

Quando não existem packages Composer resolvidos, a etapa OSV é marcada como ignorada. Falha de transporte, HTTP ou JSON é erro de execução e não equivale a “nenhuma vulnerabilidade”.

`NINFA_OSV_FIXTURE` existe como seam de teste/replay controlado e não altera o modelo público do relatório.

## Deduplicação SCA

Composer Audit e OSV podem representar a mesma vulnerabilidade com identificadores diferentes. O Ninfa consolida aliases antes de qualquer enrichment posterior.

A identidade canônica prefere, nessa ordem, quando disponível:

```text
CVE
GHSA
PKSA
OSV
outro identificador estável
```

A deduplicação preserva:

- todos os aliases conhecidos;
- fontes que confirmaram o problema;
- provenance de origem;
- componentes afetados;
- maior severidade técnica observada entre as fontes, sem criar score próprio.

Uma vulnerabilidade confirmada por duas fontes continua sendo uma vulnerabilidade canônica, não dois problemas independentes no relatório consolidado.

## Relatório estruturado

`ninfa security` grava:

```text
/tmp/ninfa/<hash>/security-report.json
```

O relatório canônico SCA contém:

- versão do schema;
- profile e inventário usado;
- estado das fontes `composer-audit` e `osv`;
- vulnerabilidades canônicas deduplicadas;
- policy findings, como dependências abandonadas;
- findings de origem para auditoria;
- exit code consolidado da execução.

O terminal continua sendo um renderer/feedback operacional; o artefato JSON é a representação estruturada para automação e evolução posterior.

A Etapa 2 da evolução está concluída com inventário, Composer Audit estruturado, OSV batch, deduplicação e `security-report.json`. EPSS, CISA KEV, NVD e evidência de exploit público ficam para a fase de intelligence e não devem ser adicionados como scanners primários agora.

GitHub Advisory também não deve entrar como terceiro detector primário apenas para repetir advisories já representados por Composer/OSV. ExploitDB/SearchSploit não é detector primário e só pode enriquecer vulnerabilidades já identificadas.

## Psalm Taint

Reutiliza a configuração Psalm gerada no workspace externo e executa análise de taint sobre os paths detectados.

A evolução prevista é capturar o resultado em formato estruturado e normalizá-lo no mesmo modelo de `Finding` usado pelas demais análises do Ninfa.

Psalm Taint deve ser o motor principal para vulnerabilidades que dependem de dataflow entre source e sink.

## Semgrep

Usa as regras do próprio Ninfa e analisa somente os paths detectados do projeto alvo. Diretórios como `vendor` e `runtime` são excluídos.

A resolução do binário segue a política geral do Ninfa e reconhece também o binário gerenciado em:

```text
/opt/ninfa/.tools/semgrep/bin/semgrep
```

Se a ferramenta não estiver disponível, o Ninfa falha de forma explícita com orientação de instalação, sem despejar warning bruto de `proc_open()`.

As regras atuais cobrem um baseline pequeno, incluindo execução direta de shell, `unserialize()` e alguns padrões de SQL concatenado. Controles positivos e negativos já comprovaram que essas regras carregam e bloqueiam exemplos simples, mas isso não representa cobertura SAST abrangente.

Semgrep deve complementar Psalm em primitives perigosas, regras de profile e misuse/configuração. Onde a vulnerabilidade depender de fluxo, regras relevantes devem evoluir de pattern matching para taint quando isso reduzir falso positivo e aumentar cobertura real.

## Contratos SAST

O Ninfa passa a adotar explicitamente contratos de domínio para estas classes:

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

O contrato do profile deve concentrar sources, sinks, sanitizers, propagators, APIs seguras/perigosas e semântica específica de Yii3 ou GLPI Plugin 11. O adapter não deve ser a fonte primária desse conhecimento.

Nesta fase, não será adicionado um terceiro scanner SAST.

## Prioridades SAST

A Etapa 3 foi concluída com:

1. Psalm Taint normalizado em `Finding` de dataflow;
2. Semgrep normalizado em `Finding` de pattern;
3. finding, erro, indisponibilidade, não aplicabilidade e cobertura parcial distintos;
4. fixtures positivas e negativas executadas na suíte;
5. arquivos escaneados/ignorados do Semgrep e paths configurados do Psalm registrados;
6. regra, severidade, confiança, arquivo, linha, mensagem, proveniência e tipo de evidência preservados.

Depois disso entram os contratos SAST comuns, capabilities/`SecurityContract` de Yii3 e especializações de GLPI Plugin 11.

Não é prioridade adicionar novos scanners antes de tornar confiáveis e auditáveis os resultados das ferramentas já adotadas.

## Evidência SAST

Findings devem conseguir diferenciar pelo menos:

```text
DATAFLOW
DANGEROUS_PRIMITIVE
MISUSE
```

`severity` e `confidence` são conceitos diferentes. A existência de uma API perigosa pode exigir revisão sem ter a mesma confiança de um caminho source → sink comprovado.

## SCA, SAST e exposure

SCA e SAST não devem ser unidos por inferência fraca.

Um finding Semgrep genérico no código da aplicação não prova que um CVE em uma dependência é alcançável. SAST só deve enriquecer exposure de um finding SCA quando houver correlação demonstrável, por exemplo função vulnerável conhecida → símbolo/call path observado.

Ausência de reachability observada não significa ausência de vulnerabilidade. Estados de exposure devem permitir `confirmed`, `observed`, `not_observed` e `unknown`.

Dependência direta ou transitiva permanece atributo de inventário/remediação; não é, isoladamente, prova de exposure.

## Severity e prioridade

O Ninfa deve manter separados:

```text
Vendor/Advisory Severity
Ninfa Priority
```

Severity técnica deve preservar o advisory/CVSS e não ser reclassificada por score próprio.

Exploitability deve representar likelihood/evidência de exploração; EPSS não é severity. CISA KEV, EPSS e exploit público são sinais relacionados e não devem ser somados ingenuamente.

Uma futura prioridade operacional pode considerar aproximadamente:

```text
Severity       0–50
Exploitability 0–30
Exposure       0–20
```

mas não deve virar security gate enquanto inventário, normalização, aliases, enrichment e exposure não estiverem calibrados em projetos reais.

## semantic-index.json

O `semantic-index.json` atual deriva de documentação e símbolos mencionados nela. Ele não representa uso real de funções, calls ou reachability e não deve ser usado como prova de exposure.

Uma futura análise de exposure precisará de evidência própria de código/call graph quando essa precisão for necessária.

## Runtime PHP e extensões

O Ninfa deve distinguir:

```text
Composer ecosystem vulnerability
PHP runtime vulnerability
native library vulnerability
operating-system vulnerability
```

A constraint PHP do `composer.json` e a versão real do runtime são dados diferentes e devem ser armazenados separadamente.

Não correlacionar extensões como `ext-curl`, `ext-openssl`, `ext-libxml`, `ext-gd` ou `ext-imagick` a CVEs de bibliotecas nativas por simples semelhança de nome. Quando não houver matching confiável, o estado deve permanecer desconhecido.

## Cache, degradação e privacidade

OSV já usa batch e evita buscar repetidamente o mesmo ID dentro da execução. Cache externo persistente, política explícita de retry/rate limit e estados mais ricos de degradação continuam como evolução futura.

Falha de uma fonte externa não deve virar finding de vulnerabilidade e não deve ser interpretada como ausência de vulnerabilidades.

Consultas externas de vulnerability intelligence devem preferencialmente enviar apenas package/version, identificadores de advisory e versão de runtime; código fonte não deve ser enviado para esse fim.

## DAST / OWASP ZAP

DAST está desabilitado no pipeline público do Ninfa.

`ninfa security` não executa OWASP ZAP. Se `NINFA_DAST=1` for definido, o CLI apenas emite aviso de que a análise dinâmica foi delegada e continua com SCA/SAST.

O arquivo `scripts/zap-scan.sh` permanece no repositório como artefato congelado para referência ou eventual uso manual controlado. Ele não faz parte da interface pública, não é instalado por `make security-tools` e não integra o roadmap ativo.

## Interpretação do resultado

Um exit code 0 em `ninfa security` significa somente que as fontes SCA/SAST consultadas não produziram bloqueios no escopo executado. Não significa “sistema seguro”, “sem vulnerabilidades”, “cobertura completa” ou aprovação por todos os controles de segurança.

## Princípio

Achados devem ser corrigidos ou tratados por regra específica e revisável. O MVP não deve criar exclusões globais apenas para silenciar a pipeline.
