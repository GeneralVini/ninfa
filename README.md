# Ninfa

**Ninfa** é uma esteira externa de qualidade e segurança para projetos PHP. O estado atual é **MVP experimental / 0.1.0-alpha**, destinado a testes controlados em projetos reais.

## Profiles ativos

O MVP possui quatro profiles PHP oficiais:

- **Yii2** — detectado por `yiisoft/yii2` e agora com overlay SAST próprio;
- **Yii3** — detectado por sinais consistentes de aplicação/runner e infraestrutura Yii;
- **GLPI Plugin 11** — profile `glpi-plugin`, com contexto do host GLPI 11 e PHPStan/Psalm em nível 8;
- **PHP genérico** — profile `php-generic`, para aplicações, bibliotecas e CLIs PHP sem framework reconhecido.

Profiles especializados têm precedência sobre `php-generic`. Um diretório sem evidência real de código PHP não é aceito como projeto genérico.

A linha **Yii 22** é acompanhada como evolução da família Yii2 e não recebe profile separado enquanto não houver necessidade técnica concreta de diferenciar regras/capabilities. **Laravel** é o próximo candidato de profile PHP no roadmap. **Python** permanece feature futura de outro ecossistema (`python-generic`, Django e Flask) e não participa da implementação atual.

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

O contexto considera estruturas Yii2 simples e Advanced, incluindo `common`, `frontend`, `backend` e `console` quando existentes. O profile de segurança combina o baseline PHP com semântica de Request/Response, DB/Command, HTML, redirects, headers e filesystem do ecossistema Yii2.

Yii 22 permanece dentro dessa família. O Ninfa não presume que Yii2 exija Repository, DTO, DDD, Clean Architecture ou Vertical Slice; essas decisões pertencem ao projeto consumidor, não ao scanner.

### Yii3

```bash
ninfa check /path/to/yii3-app
```

Uma dependência `yiisoft/*` isolada não é suficiente para classificar um projeto como Yii3. O overlay SAST considera APIs e convenções específicas de Yii DB, PSR-7/HTTP e saída HTML sem impor um estilo arquitetural ao consumidor.

### GLPI Plugin 11

Quando o plugin está em `<glpi>/plugins/<plugin>`, o host pode ser descoberto automaticamente. Fora dessa árvore, informe:

```bash
NINFA_GLPI_ROOT=/opt/glpi \
ninfa check /path/to/myplugin
```

Somente GLPI 11 é aceito e a versão do host precisa ser identificável. O overlay SAST incorpora APIs específicas de banco, redirect, URL e filesystem do host/plugin.

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

`security` permanece separado do pipeline comum de qualidade e executa:

```text
SCA   Composer Audit + OSV
SAST  Psalm Taint + Semgrep
```

DAST não faz parte do pipeline público do Ninfa. Essa capacidade é atendida por outra frente institucional, portanto o projeto evita duplicar operação e especialização em análise dinâmica.

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

As regras do Ninfa ficam em:

```text
security/semgrep/
├── common.yml
└── profiles/
    ├── yii2.yml
    ├── yii3.yml
    └── glpi-plugin-11.yml
```

As fixtures usam árvore paralela:

```text
security/semgrep-tests/
├── common.php
└── profiles/
    ├── yii2.php
    ├── yii3.php
    └── glpi-plugin-11.php
```

O próprio Ninfa valida parsing e comportamento das regras com:

```bash
make semgrep-rules
```

Esse target executa `semgrep --validate` e `semgrep --test`. Casos de fluxo usam `mode: taint` quando source → sink é a evidência relevante; primitives/misuse permanecem pattern rules quando isso produz sinal melhor.

No gate:

```text
ERROR    bloqueante
WARNING  hotspot para revisão; não bloqueia sozinho
```

Erro do motor ou cobertura parcial inesperada continua bloqueante. Exclusões deliberadas são registradas separadamente e não equivalem a perda de cobertura.

O scan usa os paths detectados pelo `ProjectContext`, inclui arquivos novos ainda não rastreados pelo Git e exclui explicitamente dependências, runtime e assets gerados.

### Saída humana

Toda saída reutiliza `CliStyle`. O padrão permanece:

```bash
NINFA_COLOR=auto
```

Também são suportados `NINFA_COLOR=always`, `NINFA_COLOR=never` e `NO_COLOR`. Não existe configuração de cor específica para segurança ou Semgrep.

### SCA / Composer Audit + OSV

Composer Audit só é executado quando existe `composer.lock`. O Ninfa o chama em modo estruturado e defensivo, com plugins/scripts do consumidor desabilitados e saída JSON:

```text
composer --no-plugins --no-scripts --no-interaction audit --locked --format=json
```

Advisories são normalizados em `Finding` com package, versão resolvida, relação direta/transitiva, escopo runtime/dev, severidade, aliases e proveniência. Pacotes abandonados permanecem identificados separadamente como `dependency-policy`, sem serem apresentados como vulnerabilidade.

OSV consulta o inventário resolvido em `querybatch` usando package + versão do ecossistema Packagist. Os IDs retornados são normalizados e correlacionados com o mesmo componente do inventário. A deduplicação usa aliases CVE/GHSA/PKSA/OSV e preserva as fontes que confirmaram a vulnerabilidade.

`ninfa security` grava no workspace externo:

```text
security-inventory.json
security-report.json
```

Falha de rede ou saída inválida de uma fonte é tratada como erro de execução, não como evidência de ausência de vulnerabilidades.

### DAST / OWASP ZAP

DAST está **desabilitado no pipeline do Ninfa**. `ninfa security` não executa ZAP, mesmo quando `NINFA_DAST` está definido.

O utilitário `scripts/zap-scan.sh` permanece como artefato congelado para referência/uso manual controlado, sem integrar o roadmap ativo.

### Interpretação

Um `ninfa security` com exit code 0 significa que as fontes SCA/SAST consultadas não produziram bloqueios e que não houve perda inesperada de cobertura no escopo observado. Não significa “sistema seguro” ou “sem vulnerabilidades”.

A arquitetura detalhada está em [Arquitetura de segurança](docs/SECURITY-ARCHITECTURE.md).

## Desenvolvimento do próprio Ninfa

```bash
make environment-check
make security-tools
make semgrep-rules
make syntax
make profile-test
make setup
```

`make environment-check` valida o ambiente do próprio Ninfa sem instalar ou alterar ferramentas no projeto consumidor. `make security-tools` prepara a versão homologada do Semgrep. `make semgrep-rules` valida e testa as regras. `make setup` executa a cadeia completa.

## Acompanhamento da evolução

**Etapa atual: 4 — Contratos SAST e profiles.**

### Etapa 1 — Fundação

- [x] Consolidar `Finding`, `ToolResult` e `RunResult` como contratos estruturados e serializáveis.
- [x] Gerar `SecurityInventory` externo com runtime PHP, constraint, extensões e inventário Composer.

### Etapa 2 — SCA estruturado

- [x] Executar Composer Audit em modo defensivo/JSON e normalizar advisories como `Finding` SCA.
- [x] Integrar OSV em batch a partir do inventário resolvido.
- [x] Deduplicar aliases CVE/GHSA/PKSA/OSV em vulnerabilidades canônicas.
- [x] Gerar `security-report.json` consolidado para os resultados SCA.

### Etapa 3 — SAST estruturado

- [x] Normalizar Psalm Taint e Semgrep em `Finding` SAST.
- [x] Distinguir finding, erro, indisponibilidade, não aplicabilidade e cobertura parcial.
- [x] Registrar cobertura observável.
- [x] Preservar regra, severidade, confiança, arquivo, linha, mensagem, evidência e proveniência.
- [x] Separar exclusões deliberadas de skips inesperados no Semgrep.
- [x] Validar e testar as regras Semgrep com fixtures positivas/negativas.

### Etapa 4 — Contratos SAST e profiles

- [x] Formalizar os 12 contratos SAST comuns.
- [x] Implementar baseline PHP comum.
- [x] Implementar `SecurityContract`/overlay do Yii2.
- [x] Implementar `SecurityContract`/overlay do Yii3.
- [x] Implementar especializações do GLPI Plugin 11.
- [x] Manter `php-generic` no baseline comum sem semântica fictícia de framework.
- [x] Tratar `WARNING` Semgrep como hotspot não bloqueante e `ERROR` como bloqueante.

### Roadmap

- [ ] Acompanhar Yii 22 dentro da família Yii2 e especializar somente quando diferenças concretas exigirem.
- [ ] Avaliar Laravel como próximo profile PHP após estabilização dos quatro atuais.
- [ ] Avaliar Python como futuro ecossistema, começando por `python-generic`; Django/Flask somente depois.
- [ ] Avaliar NVD/exploit evidence como enrichment posterior.
- [ ] Evoluir exposure/reachability e gates apenas com evidência demonstrável.

## Documentação

- [CLI](docs/CLI-DESIGN.md)
- [Instalação](docs/INSTALACAO.md)
- [Integração](docs/INTEGRACAO.md)
- [Comandos](docs/COMANDOS.md)
- [Profile GLPI](docs/GLPI_PLUGIN.md)
- [Customização](docs/CUSTOMIZACAO.md)
- [Cores](docs/CORES.md)
- [Segurança](docs/SEGURANCA.md)
- [Arquitetura de segurança](docs/SECURITY-ARCHITECTURE.md)
- [Arquitetura interna e documentação de código](docs/INTERNAL-ARCHITECTURE.md)
