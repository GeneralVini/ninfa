[Português](README.md) | [English](README.en.md)

# Ninfa

**Ninfa** é uma esteira externa de qualidade e segurança para projetos PHP. O estado atual é **MVP experimental / 0.1.0-alpha**, destinado a testes controlados em projetos reais.

## Profiles ativos

O MVP possui quatro profiles PHP oficiais:

- **Yii2** — detectado por `yiisoft/yii2`, com overlay SAST próprio, análise semântica nativa e remediação SAFE seletiva;
- **Yii3** — detectado por sinais consistentes de aplicação/runner e infraestrutura Yii;
- **GLPI Plugin 11** — profile `glpi-plugin`, com contexto do host GLPI 11 e PHPStan/Psalm em nível 8;
- **PHP genérico** — profile `php-generic`, para aplicações, bibliotecas e CLIs PHP sem framework reconhecido.

Profiles especializados têm precedência sobre `php-generic`. Um diretório sem evidência real de código PHP não é aceito como projeto genérico.

A linha **Yii 22** é acompanhada como evolução da família Yii2 e não recebe profile separado enquanto não houver necessidade técnica concreta de diferenciar regras/capabilities. **Laravel** é o próximo candidato de profile PHP no roadmap. **Python** permanece feature futura de outro ecossistema.

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

Depois execute:

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

O contexto considera estruturas Yii2 simples e Advanced, incluindo `common`, `frontend`, `backend` e `console` quando existentes. O profile de segurança combina o baseline PHP com semântica de Request/Response, DB/Command, HTML, redirects, headers e filesystem.

Além do overlay SAST, `ninfa assist` possui uma camada semântica nativa em field test. O catálogo atual cobre:

```text
COR-001   view literal inexistente
COR-002   action inexistente em filtros/behaviors estáticos
COR-003   relation path literal inexistente em with/joinWith/innerJoinWith
COR-004   atributo inexistente em link literal de hasOne/hasMany
COR-005   aridade inválida em query condition array estática
COR-006   atributo inexistente em Model::rules()
COR-007   cenário/atributo inválido em Model::scenarios()
COR-008   atributo inválido em Model::attributeLabels()
COR-009   atributo inválido em Model::attributeHints()
PERF-001  one()/count() usados apenas para testar existência
MOD-001   find()->where(hash)->one/all com shortcut findOne/findAll
DEP-001   Yii::trace() deprecated
DEP-002   constante legada de exit code
DEP-003   return 0/1 em console action
TYPE-001  relation sem @property* em classe que já mantém esse contrato PHPDoc
ARCH-001  Yii::$app->request/response dentro de controller comprovado
```

A taxonomia é deliberada: `COR-*` representa correctness; `PERF-*` performance; `MOD-*` modernization; `DEP-*` deprecation; `TYPE-*` static-analysis/PHPDoc; `ARCH-*` architecture advisory. Essas famílias não são automaticamente vulnerabilidades.

Referências dinâmicas, herança externa desconhecida, schema runtime e tipos não comprováveis permanecem `unknown` em vez de gerar falso positivo.

`ninfa fix` possui remediação nativa SAFE para Yii2 antes dos fixers externos. Atualmente ela cobre `DEP-001..003` e `MOD-001`. Os patches são aplicados por offsets exatos, sobreposição é rejeitada e a operação precisa ser idempotente. Depois o fluxo normal executa **um único `check` final**.

`PERF-001`, `TYPE-001` e `ARCH-001` permanecem REVIEW/SEMANTIC sem autofix. Em especial, PHPDoc mágico não é tratado como comentário cosmético e não é reescrito automaticamente.

O contrato técnico, limites e exemplos de cada regra estão em [Análise semântica Yii2](docs/YII2-ANALYSIS.md).

Yii 22 permanece dentro dessa família. O Ninfa não presume que Yii2 exija Repository, DTO, DDD, Clean Architecture ou Vertical Slice; decisões arquiteturais pertencem ao projeto consumidor.

### Yii3

```bash
ninfa check /path/to/yii3-app
```

Uma dependência `yiisoft/*` isolada não é suficiente para classificar um projeto como Yii3. O overlay SAST considera APIs e convenções específicas de Yii DB, PSR-7/HTTP e saída HTML sem impor estilo arquitetural.

### GLPI Plugin 11

Quando o plugin está em `<glpi>/plugins/<plugin>`, o host pode ser descoberto automaticamente. Fora dessa árvore:

```bash
NINFA_GLPI_ROOT=/opt/glpi \
ninfa check /path/to/myplugin
```

Somente GLPI 11 é aceito e a versão do host precisa ser identificável. O overlay SAST incorpora APIs específicas de banco, redirect, URL e filesystem do host/plugin.

### PHP genérico

O profile `php-generic` é usado somente quando não há correspondência com profile especializado e existem sinais reais de código PHP.

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

`check` executa qualidade, análise estática e testes disponíveis. Findings de PHPStan e Psalm são apresentados pelo renderer visual do Ninfa com arquivo, linha, regra e problema.

`fix` aplica correções automáticas homologadas e executa **um único `check`** ao final. No Yii2, remediações nativas SAFE antecedem ECS/Rector e participam da mesma revalidação final.

`security` permanece separado. `assist` acrescenta orientação de correção e grava auditoria estruturada no workspace externo sem alterar o consumidor.

## Arquitetura externa

```text
/opt/ninfa/                   código do Ninfa
/caminho/do/projeto/          projeto consumidor analisado
/tmp/ninfa/<hash-do-projeto>/ workspace externo gerado
```

O workspace é descartável e pode ser redefinido por `NINFA_WORKSPACE_ROOT`, mas não pode ficar dentro do projeto consumidor.

Artefatos gerados incluem, conforme o profile/operação:

```text
phpstan.neon
psalm.xml
rector.php
ecs.php
lefthook.yml
semantic-index.json
security-inventory.json
security-report.json
glpi-bootstrap.php
assist/yii2-semantic.json
assist/yii2-findings.json
```

O fluxo público não altera `composer.json` nem copia boilerplate/config persistente para o consumidor.

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

`fix` executa apenas correções homologadas e revalida o projeto uma vez ao final.

## Assistência auditável

```bash
ninfa assist /path/to/project
```

O comando não modifica o projeto. PHPStan/Psalm são executados em formato estruturado; no Yii2, `Yii2RuleEngine` acrescenta findings nativos e preserva `yii2-semantic.json`/`yii2-findings.json`.

A política é conservadora: uma referência só vira erro de correctness quando a ausência/violação pode ser demonstrada. Findings performance, modernization, deprecation, PHPDoc e arquitetura conservam sua categoria e risco próprios.

## Segurança

`security` permanece separado do pipeline comum de qualidade:

```text
SCA   Composer Audit + OSV
SAST  Psalm Taint + Semgrep
```

DAST não faz parte do pipeline público do Ninfa.

A especialização SAST atual é:

```text
PHP common
├── php-generic        # baseline somente
├── yii2               # baseline + overlay Yii2
├── yii3               # baseline + overlay Yii3
└── glpi-plugin        # baseline + overlay GLPI Plugin 11
```

Os doze contratos canônicos permanecem independentes de framework: command injection, SQL injection, XSS, path traversal, file access, SSRF, unsafe redirect, header injection, dynamic include/require, unsafe deserialization, dangerous eval/assert e cryptographic misuse.

### Semgrep

Regras:

```text
security/semgrep/
├── common.yml
└── profiles/
    ├── yii2.yml
    ├── yii3.yml
    └── glpi-plugin-11.yml
```

Fixtures paralelas:

```text
security/semgrep-tests/
├── common.php
└── profiles/
    ├── yii2.php
    ├── yii3.php
    └── glpi-plugin-11.php
```

Validação:

```bash
make semgrep-rules
```

No gate:

```text
ERROR    bloqueante
WARNING  hotspot para revisão; não bloqueia sozinho
```

Erro do motor ou cobertura parcial inesperada continua bloqueante. Exclusões deliberadas são registradas separadamente.

### SCA / Composer Audit + OSV

Composer Audit é executado quando existe `composer.lock` em modo estruturado/defensivo:

```text
composer --no-plugins --no-scripts --no-interaction audit --locked --format=json
```

OSV usa o inventário resolvido. Aliases CVE/GHSA/PKSA/OSV são correlacionados preservando proveniência.

`ninfa security` grava:

```text
security-inventory.json
security-report.json
```

Falha de rede ou saída inválida é erro de execução, não evidência de ausência de vulnerabilidades.

### DAST

DAST está desabilitado no pipeline do Ninfa. `scripts/zap-scan.sh` permanece artefato congelado para referência/uso manual controlado.

### Interpretação

Exit code 0 de `ninfa security` significa que as fontes consultadas não produziram bloqueios e não houve perda inesperada de cobertura no escopo observado. Não significa “sistema seguro”.

## Desenvolvimento do próprio Ninfa

```bash
make environment-check
make security-tools
make semgrep-rules
make syntax
make profile-test
make setup
```

`make profile-test` inclui os contratos gerais e fixtures Yii2 para correctness, query performance/conditions, deprecations, modernization SAFE, PHPDoc mágico e architecture advisory.

## Acompanhamento da evolução

**Etapa atual: 4 — Contratos SAST e profiles.**

### Etapa 1 — Fundação

- [x] Consolidar `Finding`, `ToolResult` e `RunResult` como contratos estruturados e serializáveis.
- [x] Gerar `SecurityInventory` externo com runtime PHP, constraint, extensões e inventário Composer.

### Etapa 2 — SCA estruturado

- [x] Executar Composer Audit em modo defensivo/JSON e normalizar advisories como `Finding` SCA.
- [x] Integrar OSV em batch a partir do inventário resolvido.
- [x] Deduplicar aliases CVE/GHSA/PKSA/OSV em vulnerabilidades canônicas.
- [x] Gerar `security-report.json` consolidado.

### Etapa 3 — SAST estruturado

- [x] Normalizar Psalm Taint e Semgrep em `Finding` SAST.
- [x] Distinguir finding, erro, indisponibilidade, não aplicabilidade e cobertura parcial.
- [x] Registrar cobertura observável.
- [x] Preservar regra, severidade, confiança, localização, evidência e proveniência.
- [x] Validar/testar regras Semgrep com fixtures positivas/negativas.

### Etapa 4 — Contratos SAST e profiles

- [x] Formalizar os 12 contratos SAST comuns.
- [x] Implementar baseline PHP comum.
- [x] Implementar overlay Yii2.
- [x] Implementar overlay Yii3.
- [x] Implementar especializações GLPI Plugin 11.
- [x] Manter `php-generic` sem semântica fictícia de framework.
- [x] Tratar `WARNING` Semgrep como hotspot e `ERROR` como bloqueante.

### Roadmap

- [ ] Calibrar/promover seletivamente regras nativas Yii2 após field tests reais.
- [ ] Acompanhar Yii 22 dentro da família Yii2 e especializar somente com diferença concreta.
- [ ] Avaliar Laravel como próximo profile PHP.
- [ ] Avaliar Python como futuro ecossistema (`python-generic` antes de Django/Flask).
- [ ] Avaliar NVD/exploit evidence como enrichment posterior.
- [ ] Evoluir exposure/reachability e gates apenas com evidência demonstrável.

## Projetos que ajudaram o NINFA

O NINFA se beneficiou muito das ideias, práticas e experiência acumulada nestes projetos, que são referências importantes para a evolução da análise semântica Yii2, das remediações seguras e do hardening da esteira:

- [mspirkov/yii2-phpstan-rules](https://github.com/mspirkov/yii2-phpstan-rules) — grande referência para validações estáticas, semântica Yii2 e regras de qualidade;
- [mspirkov/yii2-rector](https://github.com/mspirkov/yii2-rector) — grande referência para modernização Yii2 e transformações seguras;
- [php-forge/foxy](https://github.com/php-forge/foxy) — grande referência para práticas defensivas de tooling, auditoria de dependências, recuperação de falhas e robustez operacional.

O NINFA mantém seus próprios contratos, IDs, políticas de segurança e implementações; essas referências são reconhecidas pela grande contribuição conceitual ao projeto.

## Documentação

- [CLI](docs/CLI-DESIGN.md)
- [Instalação](docs/INSTALACAO.md)
- [Integração](docs/INTEGRACAO.md)
- [Comandos](docs/COMANDOS.md)
- [Análise semântica Yii2](docs/YII2-ANALYSIS.md)
- [Profile GLPI](docs/GLPI_PLUGIN.md)
- [Customização](docs/CUSTOMIZACAO.md)
- [Cores](docs/CORES.md)
- [Segurança](docs/SEGURANCA.md)
- [Arquitetura de segurança](docs/SECURITY-ARCHITECTURE.md)
- [Arquitetura interna e documentação de código](docs/INTERNAL-ARCHITECTURE.md)
- [EAP de integração upstream](docs/EAP-UPSTREAM-INTEGRATION.md)
