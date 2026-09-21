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
Psalm Taint Analysis
Semgrep
```

As etapas independentes continuam mesmo quando uma delas encontra um bloqueio ou falha. O resumo final distingue sucesso, falha, erro de execução e etapa ignorada; o exit code do comando preserva a primeira falha observada.

## Diretriz de maturidade

| Frente | Estado | Diretriz |
|---|---|---|
| SCA | baseline funcional | estruturar inventário/resultados e depois integrar OSV |
| SAST | MVP funcional / beta interna | prioridade de evolução |
| DAST | fora do pipeline | delegado; evolução congelada |

O foco do Ninfa é amadurecer SAST por profile sem transformar o projeto em um agregador indiscriminado de scanners.

A especialização SAST ativa nesta fase está limitada a:

```text
PHP common
├── Yii3
└── GLPI Plugin 11
```

Yii2 continua suportado pelo pipeline geral, mas não recebe agora `SecurityContract` específico. PHP genérico usa o baseline comum. Outros frameworks permanecem fora do escopo ativo.

A arquitetura detalhada, os contratos SAST, a evolução SCA e a ordem de implementação estão em [SECURITY-ARCHITECTURE.md](SECURITY-ARCHITECTURE.md).

## Composer Audit

Executa `composer audit --locked --no-interaction` somente quando o projeto possui `composer.lock`. Projetos PHP genéricos sem Composer não falham apenas pela ausência desse recurso.

A consulta de advisories depende de conectividade. Um timeout de rede deve ser tratado como condição de infraestrutura, não como achado de vulnerabilidade nem como evidência de ausência de vulnerabilidades.

A próxima evolução SCA deve ocorrer nesta ordem:

1. inventário estruturado, preferindo `composer.lock` para versões resolvidas;
2. separar runtime PHP real de constraint declarada no `composer.json`;
3. obter saída estruturada do Composer Audit e normalizar findings;
4. integrar OSV em batch;
5. deduplicar CVE/GHSA/PKSA/OSV antes de enrichment;
6. somente depois avaliar EPSS, CISA KEV, NVD e evidência de exploit público.

GitHub Advisory não deve entrar agora como terceiro detector primário apenas para repetir advisories já representados por Composer/OSV. ExploitDB/SearchSploit não é detector primário e só pode enriquecer vulnerabilidades já identificadas.

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

A evolução deve concentrar-se em:

1. normalizar Semgrep e Psalm Taint em `Finding`;
2. distinguir finding, erro de ferramenta, indisponibilidade e não aplicabilidade;
3. executar fixtures reais positivas e negativas no CI;
4. registrar cobertura efetiva de paths/arquivos;
5. formalizar os contratos SAST comuns;
6. implementar capabilities + `SecurityContract` de Yii3;
7. implementar especializações de GLPI Plugin 11;
8. preservar regra, severidade, confiança, arquivo, linha, mensagem, proveniência e tipo de evidência;
9. aplicar quality gates somente depois que o modelo de resultado estiver estável.

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

## Relatório estruturado

A direção é produzir um relatório canônico externo, por exemplo:

```text
/tmp/ninfa/<hash>/security-report.json
```

O terminal deve renderizar esse modelo. O relatório deve registrar inventário, findings, fontes consultadas, fontes indisponíveis, coverage e evidências relevantes.

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

Antes de ampliar fontes online, o Ninfa deve prever cache externo, batch APIs, timeouts, rate limits e estados explícitos de indisponibilidade.

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
