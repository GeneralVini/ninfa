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

As configurações geradas ficam no workspace externo, por exemplo:

```text
phpstan.neon
psalm.xml
rector.php
ecs.php
lefthook.yml
semantic-index.json
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
SCA   Composer Audit
SAST  Psalm Taint + Semgrep
```

DAST não faz mais parte do pipeline público do Ninfa. Essa capacidade é atendida por outra frente institucional, portanto o projeto evita duplicar operação e especialização em análise dinâmica.

A maturidade atual deve ser interpretada assim:

| Frente | Estado atual no Ninfa | Diretriz |
|---|---|---|
| SCA | baseline funcional | estruturar inventário/resultados e depois incorporar OSV |
| SAST | MVP funcional / beta interna | **prioridade de evolução** |
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

### SCA / Composer Audit

Composer Audit só é executado quando existe `composer.lock`. A consulta depende de conectividade e indisponibilidade de rede não deve ser interpretada como ausência de vulnerabilidades.

O baseline atual é útil, mas ainda precisa evoluir para diferenciar explicitamente estados como `passed`, `failed`, `unavailable`, `not_applicable` e cobertura parcial.

Antes de multiplicar fontes externas, o SCA deve criar inventário estruturado a partir de `composer.lock`, `composer.json`, runtime PHP real e demais sinais confiáveis. Depois, a próxima fonte planejada é OSV, com normalização e deduplicação de aliases antes de qualquer enrichment por EPSS, KEV, NVD ou evidência de exploit público.

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

## Evolução prevista

A prioridade atual é estabilizar os quatro profiles em projetos reais e amadurecer o **SAST orientado a profile**, com especialização ativa somente para Yii3 e GLPI Plugin 11.

Antes de avançar para scheduler, DAG, baseline/new-code ou novas camadas de automação, o core deve consolidar resultados estruturados (`ToolResult`, `RunResult`, `Finding`) e reduzir a dependência de exit codes brutos como representação principal de segurança.

A evolução de DAST permanece congelada e fora do pipeline do Ninfa enquanto essa capacidade for tratada por outra frente institucional. O foco do projeto é evitar duplicação de esforço e investir onde há lacuna real: análise estática de segurança, contexto de profile, normalização de findings e políticas auditáveis.

Em etapa posterior, o core poderá alimentar uma interface web/dashboard para histórico, findings, tendências e acompanhamento de execuções. API, banco e frontend não fazem parte do MVP atual.

## Documentação

- [CLI](docs/CLI-DESIGN.md)
- [Instalação](docs/INSTALACAO.md)
- [Integração](docs/INTEGRACAO.md)
- [Comandos](docs/COMANDOS.md)
- [Profile GLPI](docs/GLPI_PLUGIN.md)
- [Customização](docs/CUSTOMIZACAO.md)
- [Segurança](docs/SEGURANCA.md)
- [Arquitetura de segurança](docs/SECURITY-ARCHITECTURE.md)
