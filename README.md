# Ninfa

**Ninfa** é uma esteira externa de qualidade e segurança para projetos PHP. O estado atual é **MVP experimental / 0.1.0-alpha**, destinado a testes controlados em projetos reais.

## Profiles ativos

O MVP possui quatro profiles:

- **Yii2** — detectado por `yiisoft/yii2`;
- **Yii3** — detectado por sinais consistentes de aplicação/runner e infraestrutura Yii;
- **GLPI Plugin 11** — profile `glpi-plugin`, com contexto do host GLPI 11 e PHPStan/Psalm em nível 8;
- **PHP genérico** — profile `php-generic`, para aplicações, bibliotecas e CLIs PHP sem framework reconhecido.

Profiles especializados têm precedência sobre `php-generic`. Um diretório sem evidência real de código PHP não é aceito como projeto genérico. Laravel e Symfony permanecem em **stand by**.

## Execução rápida

Enquanto o MVP estiver na branch `feature/glpi-plugin-profile`, clone o Ninfa fora do projeto consumidor:

```bash
git clone --branch feature/glpi-plugin-profile --single-branch \
  https://github.com/GeneralVini/ninfa.git /opt/ninfa
```

Adicione o CLI ao `PATH`:

```bash
export PATH="/opt/ninfa/bin:$PATH"
```

Depois execute diretamente:

```bash
ninfa check /path/to/project
ninfa fix /path/to/project
ninfa security /path/to/project
ninfa assist /path/to/project
```

Quando `root` é omitido, o Ninfa usa o diretório atual. Não existe etapa obrigatória de instalação ou preparação do projeto consumidor.

## Profiles

### Yii2

```bash
ninfa check /path/to/yii2-app
```

O contexto considera estruturas Yii2 simples e Advanced, incluindo `common`, `frontend`, `backend` e `console` quando existentes.

### Yii3

```bash
ninfa check /path/to/yii3-app
```

Uma dependência `yiisoft/*` isolada não é suficiente para classificar um projeto como Yii3.

### GLPI Plugin 11

Quando o plugin está em `<glpi>/plugins/<plugin>`, o host pode ser descoberto automaticamente. Fora dessa árvore, informe:

```bash
NINFA_GLPI_ROOT=/opt/glpi \
ninfa check /path/to/myplugin
```

Somente GLPI 11 é aceito e a versão do host precisa ser identificável.

### PHP genérico

O profile `php-generic` é usado somente quando não há correspondência com um profile especializado e existem sinais reais de código PHP.

São suportados projetos Composer e projetos PHP simples sem Composer, por exemplo:

```bash
ninfa check /path/to/php-library
ninfa check /path/to/php-cli
ninfa check /path/to/simple-php-app
```

O Ninfa detecta apenas paths existentes e não exige `src/`, `public/` ou `tests/` de forma obrigatória.

## Comandos públicos

```bash
ninfa check [root]
ninfa fix [root]
ninfa security [root]
ninfa assist [root]
```

`check` executa qualidade, análise estática e testes disponíveis. Findings de PHPStan e Psalm são apresentados pelo renderer visual do Ninfa com arquivo, linha, regra e problema, sem expor como interface principal as tabelas nativas das ferramentas.

`fix` aplica correções automáticas e executa **um único `check`** ao final. `security` permanece separado. `assist` reutiliza o renderer do `check`, acrescenta orientação de correção e grava auditoria estruturada no workspace externo sem alterar o consumidor.

## Arquitetura externa

```text
/opt/ninfa/                   código do Ninfa
/caminho/do/projeto/          projeto consumidor analisado
/tmp/ninfa/<hash-do-projeto>/ workspace externo gerado
```

O workspace é descartável e pode ser redefinido por `NINFA_WORKSPACE_ROOT`, mas não pode ficar dentro do projeto consumidor.

As configurações e artefatos gerados ficam no workspace externo, por exemplo:

```text
phpstan.neon
psalm.xml
rector.php
ecs.php
lefthook.yml
semantic-index.json
security-inventory.json
security-report.json
glpi-bootstrap.php   # quando aplicável
```

O fluxo público do Ninfa não altera `composer.json` nem copia boilerplate para o consumidor.

## Pipeline de qualidade

`check` inclui, conforme o contexto:

```text
ECS
Rector --dry-run
PHPStan
Psalm
ESLint           # quando aplicável
Prettier --check # quando aplicável
PHPUnit          # quando disponível
```

`fix` executa apenas hooks corrigíveis e revalida o projeto uma vez ao final.

## Assistência auditável

`assist` é a camada para findings sem correção mecânica segura:

```bash
ninfa assist /path/to/project
```

O comando não modifica o projeto. PHPStan e Psalm são executados em formato estruturado, os achados são exibidos com orientação de correção e a evidência completa fica em `/tmp/ninfa/<hash>/assist/`.

## Segurança

`security` permanece separado do pipeline comum de qualidade e, no escopo atual do Ninfa, executa duas frentes:

```text
SCA   Composer Audit + OSV
SAST  Psalm Taint + Semgrep
```

DAST não faz mais parte do pipeline público do Ninfa. Essa capacidade é atendida por outra frente institucional, portanto o projeto evita duplicar operação e especialização em análise dinâmica.

A maturidade atual deve ser interpretada assim:

| Frente | Estado atual no Ninfa | Diretriz |
|---|---|---|
| SCA | inventário + Composer Audit + OSV + deduplicação/report estruturados | etapa estrutural concluída; enrichment fica para depois |
| SAST | MVP funcional / beta interna | **próxima etapa de evolução** |
| DAST | fora do pipeline público | **delegado; evolução congelada no Ninfa** |

### Diretriz atual: foco em SAST

A prioridade de segurança do Ninfa é **SAST orientado a profile**. Nesta fase, o baseline comum continua PHP e a evolução específica de `SecurityContract` fica restrita a **Yii3** e **GLPI Plugin 11**.

Yii2 continua suportado pelo pipeline geral, mas não recebe agora evolução SAST específica. O profile `php-generic` utiliza o baseline comum sem contrato especializado adicional. Outros frameworks permanecem fora do escopo ativo.

DAST permanece deliberadamente fora do `ninfa security`. Se `NINFA_DAST=1` for informado, o Ninfa apenas avisa que a capacidade está desabilitada/delegada e **não executa OWASP ZAP**.

O utilitário `scripts/zap-scan.sh` permanece no repositório como artefato congelado para referência ou uso manual controlado, mas não integra a interface pública nem o roadmap ativo.

### SAST atual

O baseline SAST usa:

```text
Psalm Taint Analysis
Semgrep
```

O Semgrep atualmente possui regras próprias do Ninfa para casos básicos como:

- execução direta de shell;
- `unserialize()`;
- SQL construído por concatenação em padrões conhecidos.

Essas regras já foram validadas com controles positivos e negativos em teste de campo. Isso comprova o encadeamento básico `Ninfa -> ferramenta -> finding -> falha`, mas **não representa cobertura SAST abrangente**.

O próximo estágio de maturidade SAST deve priorizar:

1. normalizar resultados de Semgrep e Psalm Taint em um modelo único de `Finding`;
2. distinguir finding, erro de ferramenta, indisponibilidade e etapa não aplicável;
3. criar fixtures reais de segurança com casos positivos e negativos em CI;
4. medir cobertura efetiva dos paths e arquivos analisados;
5. formalizar contratos SAST para command injection, SQL injection, XSS, path traversal, file access, SSRF, unsafe redirect, header injection, dynamic include/require, unsafe deserialization, dangerous eval/assert e cryptographic misuse;
6. especializar esses contratos, quando necessário, apenas para `yii3` e `glpi-plugin-11` nesta fase;
7. preservar proveniência de regra, severidade, confiança, arquivo, linha, mensagem e tipo de evidência;
8. só depois aplicar políticas/quality gates de segurança mais sofisticados.

Não é objetivo imediato adicionar vários scanners diferentes. Primeiro, o Ninfa deve extrair resultados confiáveis, estruturados e auditáveis das ferramentas que já utiliza.

A separação arquitetural pretendida é: contrato SAST define **o que** caracteriza a vulnerabilidade, `Profile SecurityContract` define **o que** as APIs daquele ecossistema significam e o adapter define **como** Psalm/Semgrep executam essa semântica.

### SCA / Composer Audit + OSV

Composer Audit só é executado quando existe `composer.lock`. O Ninfa o chama em modo estruturado e defensivo, com plugins/scripts do consumidor desabilitados e saída JSON:

```text
composer --no-plugins --no-scripts --no-interaction audit --locked --format=json
```

Advisories são normalizados em `Finding` com package, versão resolvida, relação direta/transitiva, escopo runtime/dev, severidade, aliases e proveniência. Pacotes abandonados permanecem identificados separadamente como `dependency-policy`, sem serem apresentados como vulnerabilidade.

OSV consulta o inventário resolvido em `querybatch` usando package + versão do ecossistema Packagist. Os IDs retornados são enriquecidos com o registro OSV correspondente, normalizados em `Finding` e correlacionados com o mesmo componente do inventário. A deduplicação usa aliases CVE/GHSA/PKSA/OSV e preserva as fontes que confirmaram a vulnerabilidade.

`ninfa security` grava dois artefatos estruturados no workspace externo:

```text
security-inventory.json
security-report.json
```

O relatório SCA consolida estado das fontes, findings de origem, policy findings e vulnerabilidades canônicas deduplicadas. Falha de rede ou saída inválida de uma fonte é tratada como erro de execução, não como evidência de ausência de vulnerabilidades.

EPSS, KEV, NVD e evidência de exploit público permanecem fora desta etapa; quando entrarem, serão enrichment sobre vulnerabilidades já normalizadas e deduplicadas.

### DAST / OWASP ZAP

DAST está **desabilitado no pipeline do Ninfa**. `ninfa security` não executa ZAP, mesmo quando `NINFA_DAST` está definido.

A decisão é de escopo: análise dinâmica é tratada por uma frente especializada externa. O Ninfa mantém o código legado do wrapper apenas como artefato congelado, sem otimização, expansão funcional ou suporte como security gate.

`make security-tools` passa a preparar apenas a ferramenta SAST gerenciada pelo Ninfa (Semgrep). `NINFA_INSTALL_ZAP=1` é ignorado com aviso explícito.

### Interpretação dos resultados de segurança

Um `ninfa security` com exit code 0 significa apenas que as fontes SCA/SAST consultadas não produziram bloqueios no escopo executado. Não significa:

```text
"sistema seguro"
"sem vulnerabilidades"
"cobertura completa"
"aprovado por todos os controles de segurança"
```

Testes de campo mostraram que cobertura, conectividade, cache, inventário e escopo influenciam diretamente a interpretação do resultado. O objetivo da evolução SAST/SCA é tornar essas condições explícitas e auditáveis.

A arquitetura detalhada e a ordem de implementação estão em [Arquitetura de segurança](docs/SECURITY-ARCHITECTURE.md).

## Diagnóstico manual

```bash
php /opt/ninfa/scripts/ninfa-configure.php /caminho/do/projeto
```

O configurador existe para diagnóstico e inspeção. Os comandos públicos não dependem de uma etapa manual de preparação.

## Desenvolvimento do próprio Ninfa

```bash
make syntax
make profile-test
make setup
```

O `Makefile` é interno ao repositório Ninfa e não é requisito para projetos consumidores.

## Acompanhamento da evolução

Este checklist é o painel de progresso do MVP. Ele deve ser revisado a cada commit relevante; itens só são marcados como concluídos quando implementação e testes correspondentes estiverem presentes.

**Etapa atual: 3 — SAST estruturado.**

### Etapa 1 — Fundação

- [x] Consolidar `Finding`, `ToolResult` e `RunResult` como contratos estruturados e serializáveis.
- [x] Gerar `SecurityInventory` externo com runtime PHP, constraint, extensões e inventário Composer.

### Etapa 2 — SCA estruturado

- [x] Executar Composer Audit em modo defensivo/JSON e normalizar advisories como `Finding` SCA.
- [x] Integrar OSV em batch a partir do inventário resolvido.
- [x] Deduplicar aliases CVE/GHSA/PKSA/OSV em vulnerabilidades canônicas.
- [x] Gerar `security-report.json` consolidado para os resultados SCA.

### Etapa 2.5 — Documentação interna e mapa de fluxo

- [x] Criar mapa interno baseado nas responsabilidades observadas no código atual, sem duplicar o README.
- [x] Tornar documentação de símbolo/bloco uma premissa de implementação, e não apenas cabeçalho de arquivo.
- [x] Migrar `src/OsvClient.php` como referência inicial do padrão estrito, incluindo métodos privados, exceções e tipos/shapes de estruturas locais.
- [x] Documentar blocos semânticos dos scripts PHP/shell atuais, além dos cabeçalhos.
- [x] Endurecer a suíte para que arquivos novos e arquivos já migrados cumpram o padrão estrito.
- [x] Definir e proteger o mesmo princípio para JavaScript próprio quando existir; atualmente não há arquivo `.js` versionado no Ninfa.
- [x] Migrar todos os arquivos de `src/` para PHPDoc em classes, métodos/funções e tipos compostos relevantes.
- [x] Eliminar a allowlist temporária de dívida documental usada pelo guard.
- [x] Revisar o mapa interno e os comentários após a migração completa, removendo divergências restantes.

### Etapa 3 — SAST estruturado

- [ ] Normalizar Psalm Taint em `Finding` SAST.
- [ ] Normalizar Semgrep em `Finding` SAST.
- [ ] Distinguir explicitamente finding, erro de ferramenta, indisponibilidade, não aplicabilidade e cobertura parcial.
- [ ] Executar fixtures SAST reais positivas e negativas em CI.
- [ ] Registrar cobertura efetiva de paths e arquivos analisados.
- [ ] Preservar regra, severidade, confiança, arquivo, linha, mensagem, evidência e proveniência nos findings SAST.

### Etapa 4 — Contratos SAST e profiles

- [ ] Formalizar os 12 contratos SAST comuns: command injection, SQL injection, XSS, path traversal, file access, SSRF, unsafe redirect, header injection, dynamic include/require, unsafe deserialization, dangerous eval/assert e cryptographic misuse.
- [ ] Implementar capabilities de segurança para Yii3.
- [ ] Implementar `SecurityContract` do Yii3 sobre os contratos comuns.
- [ ] Implementar especializações de segurança para GLPI Plugin 11.
- [ ] Manter Yii2 e `php-generic` fora de especializações adicionais nesta fase; `php-generic` usa apenas o baseline comum.

### Etapa 5 — Intelligence

- [ ] Enriquecer CVEs canônicos com EPSS.
- [ ] Correlacionar CISA KEV sem transformar KEV em scanner primário.
- [ ] Avaliar NVD e evidência de exploit público somente como enrichment posterior.

### Etapa 6 — Exposure, prioridade e gates

- [ ] Criar índice de código apropriado para correlação de símbolos/calls; não reutilizar o `semantic-index.json` documental como prova de uso real.
- [ ] Correlacionar SCA com código somente quando houver evidência demonstrável de exposição/reachability.
- [ ] Modelar Exposure separadamente de direct/transitive dependency.
- [ ] Calibrar `Ninfa Priority` sem substituir a severidade oficial do advisory.
- [ ] Introduzir quality gates de segurança somente após estabilizar findings, coverage e prioridade.

### Etapa 7 — Evolução posterior

- [ ] Avaliar baseline/new-code e análise diff-aware.
- [ ] Avaliar DAG, scheduler e paralelismo após estabilização dos contratos de execução.
- [ ] Avaliar automações avançadas e dashboard/histórico sem acoplar essas camadas ao core prematuramente.

## Evolução prevista

A **Etapa 2.5 está fechada**: todos os arquivos de `src/` estão sujeitos ao padrão documental estrito, a allowlist de dívida foi eliminada, scripts PHP/shell têm documentação interna protegida por testes e futuros módulos JavaScript entram no requisito de JSDoc. A prioridade volta para a **Etapa 3**, normalizando Psalm Taint e Semgrep em `Finding` SAST antes de ampliar regras ou scanners.

A especialização ativa de segurança permanece restrita a **Yii3** e **GLPI Plugin 11**. DAST continua congelado e delegado a outra frente institucional.

A arquitetura detalhada, os limites de escopo e a ordem das decisões estão em [Arquitetura de segurança](docs/SECURITY-ARCHITECTURE.md). O fluxo interno implementado e o padrão de documentação de código estão em [Arquitetura interna](docs/INTERNAL-ARCHITECTURE.md).

## Documentação

- [CLI](docs/CLI-DESIGN.md)
- [Instalação](docs/INSTALACAO.md)
- [Integração](docs/INTEGRACAO.md)
- [Comandos](docs/COMANDOS.md)
- [Profile GLPI](docs/GLPI_PLUGIN.md)
- [Customização](docs/CUSTOMIZACAO.md)
- [Segurança](docs/SEGURANCA.md)
- [Arquitetura de segurança](docs/SECURITY-ARCHITECTURE.md)
- [Arquitetura interna e documentação de código](docs/INTERNAL-ARCHITECTURE.md)
