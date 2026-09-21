# Arquitetura interna e documentação de código

Este documento descreve o fluxo interno implementado no Ninfa a partir do código atual. Ele não substitui PHPDoc, comentários de shell ou documentação junto ao código: serve como mapa para localizar responsabilidades e para evitar que comentários futuros contradigam a implementação.

## Objetivo da Etapa 2.5

A Etapa 2.5 existe para tornar o código compreensível sem depender de conhecimento tácito ou de uma IA para reconstruir o fluxo. A documentação interna deve registrar fatos observáveis na implementação: entradas, saídas, efeitos colaterais, invariantes, precedências, variáveis de ambiente, arquivos gerados, códigos de saída e limites de responsabilidade.

Não é objetivo repetir README, explicar sintaxe trivial ou escrever comentários que apenas traduzam o nome de um método.

## Fluxo executável atual

O caminho normal dos comandos públicos é:

```text
bin/ninfa
  ↓
ProjectContext::fromRoot()
  ├─ ProfileDetector
  ├─ Workspace
  └─ resolução de host GLPI quando profile = glpi-plugin
  ↓
RecheckingPipelineRunner
  ↓
PipelineRunner
  ├─ ExternalConfigGenerator
  ├─ PipelinePlan
  ├─ ToolResolver
  ├─ ProcessRunner
  └─ scanners/parsers específicos
       ↓
    Finding[]
       ↓
    ToolResult[]
       ↓
    RunResult
```

`fix` possui um comportamento adicional: `RecheckingPipelineRunner` executa `fix` e, somente se ele terminar com código 0, executa um `check` completo. Outros comandos não recebem esse recheck.

`assist` não passa por `RecheckingPipelineRunner`; `bin/ninfa` chama `scripts/ninfa-configure.php --assist` por meio de `ProcessRunner`.

## Entrada e contexto do projeto

### `bin/ninfa`

É o entrypoint do CLI. Aceita `check`, `fix`, `security` e `assist`, resolve a raiz informada ou o diretório atual, cria `ProjectContext` e imprime projeto, profile e workspace. Para `assist`, delega ao configurador; para os demais comandos, usa `RecheckingPipelineRunner`.

### `src/ProjectContext.php`

Constrói o contexto imutável usado pelo restante da pipeline. A implementação atual:

- resolve a raiz com `realpath()` e rejeita diretórios inválidos;
- carrega `composer.json`, quando existe;
- delega a classificação a `ProfileDetector`;
- mantém separadas a constraint PHP declarada no Composer e a versão real do PHP em execução;
- escolhe paths analisáveis conforme o profile e somente inclui paths existentes;
- cria um `Workspace` externo;
- para `glpi-plugin`, exige um host GLPI 11 identificável.

`phpVersion()` representa `major.minor` do runtime que está executando o Ninfa. `runtimePhpVersion()` preserva `PHP_VERSION`. `phpConstraint()` representa o valor de `require.php` do `composer.json`, quando presente.

### `src/ProfileDetector.php`

Classifica o projeto nesta ordem: GLPI Plugin, Yii2, Yii3 e PHP genérico. GLPI usa uma pontuação baseada em `setup.php`, `hook.php`, funções de plugin e namespace `GlpiPlugin`. Yii2 depende de `yiisoft/yii2`. Yii3 exige ao menos um marcador de aplicação/runner e um marcador de infraestrutura entre os pacotes Composer. O fallback PHP genérico exige evidência real de arquivos PHP.

O detector responde apenas qual profile se aplica; ele não resolve semântica de segurança de framework.

### `src/Workspace.php`

Cria o workspace por projeto. Por padrão usa `<tmp>/ninfa/<hash>`, onde o hash deriva da raiz real do projeto. `NINFA_WORKSPACE_ROOT` pode mudar a base, mas a implementação rejeita uma base igual ou interna ao projeto consumidor. `file()` somente monta caminhos dentro desse workspace.

## Planejamento e execução

### `src/PipelinePlan.php`

Define quais etapas pertencem a cada operação.

No estado atual:

```text
check
  ECS
  Rector --dry-run
  PHPStan
  Psalm
  ESLint      quando FrontendDetector indicar
  Prettier    quando FrontendDetector indicar
  testes

fix
  somente etapas de check marcadas como fixable

security
  Composer Audit
  OSV
  Psalm Taint
  Semgrep
```

O plano não executa processos. Ele apenas retorna os hooks e modos que `PipelineRunner` deve executar.

### `src/PipelineRunner.php`

É o orquestrador das operações `check`, `fix` e `security`. Ele:

- gera configurações externas antes da execução;
- obtém o plano da operação;
- resolve comandos e ferramentas;
- executa todas as etapas sem fail-fast;
- preserva o primeiro exit code não zero como exit code final;
- cria `ToolResult` para sucesso, falha, erro ou etapa ignorada;
- renderiza um resumo no final.

No modo `security`, há efeitos adicionais:

- cria `SecurityInventory` antes dos scanners;
- grava `security-inventory.json` no workspace;
- executa OSV diretamente pelo `OsvClient`, sem processo externo;
- ao final monta `SecurityReport` e grava `security-report.json`.

A presença de `NINFA_DAST` só produz aviso. O runner não chama ZAP.

### `src/RecheckingPipelineRunner.php`

É uma camada fina ao redor de `PipelineRunner`. Só altera o fluxo de `fix`: depois de um `fix` bem-sucedido, executa exatamente um `check`. Se esse check ainda falhar, imprime orientação para `ninfa assist`.

### `src/ToolResolver.php`

Resolve binários nesta ordem de candidatos explícitos:

1. `node_modules/.bin` do consumidor;
2. `vendor/bin` do consumidor;
3. `.tools/<tool>/bin/<tool>` do Ninfa;
4. `node_modules/.bin` do Ninfa;
5. `vendor/bin` do Ninfa;
6. diretórios de `PATH`.

Se Semgrep não for encontrado, a exceção orienta a executar `make security-tools` no repositório do Ninfa.

### `src/ProcessRunner.php`

Atualmente reúne três responsabilidades relacionadas, mas distintas:

- `ProcessResult`: transporta exit code, stdout e stderr capturados;
- `FindingRenderer`: converte JSON de PHPStan/Psalm em `Finding`, renderiza findings e produz sugestões simples de correção;
- `ProcessRunner`: inicia processos com `proc_open()`, em modo direto ou capturado.

Esse agrupamento é um fato da implementação atual e não deve ser documentado como três arquivos separados enquanto o código não for refatorado.

## Configuração e detecção auxiliar

### `src/ExternalConfigGenerator.php`

Gera no workspace as configurações consumidas por PHPStan, Psalm, ECS e Rector. Os paths vêm de `ProjectContext`.

Para GLPI Plugin 11, a implementação também pode:

- localizar a extensão `phpstan-glpi` no plugin ou no host;
- gerar `glpi-bootstrap.php` no workspace;
- adicionar diretórios, arquivos de autoload e stubs do host ao PHPStan;
- configurar o global `$DB` e uma exceção específica de `InvalidGlobal` no Psalm.

Os arquivos de configuração gerados são descartáveis e não são escritos no projeto consumidor.

### `src/FrontendDetector.php`

Detecta sinais de JavaScript/TypeScript e disponibilidade de ESLint/Prettier no projeto consumidor. Ele consulta dependências em `package.json`, binários em `node_modules/.bin`, arquivos de configuração conhecidos e, para detecção geral de JavaScript, extensões de arquivos em diretórios candidatos.

O repositório Ninfa não possui atualmente arquivos `.js` versionados; a regra de documentação JavaScript deve ser aplicada quando módulos JS próprios forem introduzidos.

### `src/LefthookConfigGenerator.php`

Gera `lefthook.yml` no workspace. O hook de `pre-commit` chama `ninfa fix` e usa `stage_fixed: true`; o `pre-push` chama `ninfa check`. O gerador escapa os comandos como strings YAML single-quoted.

### `src/SemanticHints.php`

Lê documentação do projeto consumidor (`README.md`, `AGENTS.md`, `CONTRIBUTING.md`, `ARCHITECTURE.md` e `docs/*.md`) com limites de quantidade e tamanho. Extrai textos entre crases como símbolos documentados e conta menções a GLPI/Yii2/Yii3.

O resultado é documental. Essa classe não percorre AST, chamadas ou símbolos reais de código e não deve ser descrita como índice de reachability.

## Modelo de resultado

### `src/Finding.php`

Representa um achado normalizado. Exige `tool` e `rule`, rejeita linha negativa e serializa campos básicos mais severity, confidence, evidence type, provenance e metadata quando presentes.

### `src/ToolResult.php`

Representa o resultado de uma etapa. Os estados implementados são `ok`, `failed`, `error` e `skipped`. Mantém exit code, detalhe, duração e findings associados.

### `src/RunResult.php`

Consolida a operação e todos os `ToolResult`. `findings()` apenas achata os findings das ferramentas; o objeto não deduplica vulnerabilidades nem calcula prioridade.

## SCA implementado

### `src/SecurityInventory.php`

Constrói o inventário local usado pelo SCA. A precedência implementada para versões de packages é:

```text
composer.lock
  ↓ fallback quando lock não existe
vendor/composer/installed.json
```

O inventário preserva dependências diretas declaradas em `composer.json`, classifica packages como direct/transitive e runtime/dev quando essa informação é conhecida, registra runtime PHP, constraint PHP, extensões requeridas/carregadas, profile, paths e contexto do host GLPI.

### `src/ComposerAuditParser.php`

Converte JSON de `composer audit` em `Finding`. Advisories viram `sca-advisory`; packages abandonados viram `dependency-policy`. O parser associa package/versão ao `SecurityInventory`, preserva aliases, fontes e metadata do advisory e não trata abandono como vulnerabilidade.

### `src/OsvClient.php`

Consulta OSV para packages Composer resolvidos. A implementação atual:

- usa ecossistema `Packagist`;
- envia consultas em lotes de até 100 packages;
- trata `next_page_token` por componente;
- busca o registro completo de cada vulnerabilidade por ID;
- ignora registros retirados (`withdrawn`);
- produz `Finding` `sca-advisory` correlacionado ao componente do inventário.

O cliente não faz deduplicação entre Composer Audit e OSV; essa responsabilidade fica fora dele.

### `src/ScaFindingDeduplicator.php`

Agrupa findings SCA que compartilham identificadores. A implementação normaliza IDs em maiúsculas, considera rule/id/CVE/aliases e usa união de conjuntos para formar grupos conectados. A prioridade de ID canônico é CVE, GHSA, PKSA, OSV e, por fim, outro identificador disponível. O resultado preserva fontes, proveniência, componentes e IDs por fonte.

### `src/SecurityReport.php`

Aceita somente `RunResult` da operação `security`. No schema atual (`schema_version = 1`), inclui inventário, estado das fontes SCA, vulnerabilities deduplicadas, policy findings, source findings e exit code final. Somente `composer-audit` e `osv` são tratados como fontes SCA nessa versão do relatório.

## Scripts executáveis

### `scripts/ninfa-configure.php`

Tem dois modos atuais. No modo normal, gera configurações, `lefthook.yml` e `semantic-index.json`. Com `--assist`, executa PHPStan e Psalm em formato estruturado, grava stdout/stderr no subdiretório `assist`, consolida `findings.json`, renderiza findings e retorna código não zero quando há achados ou uma ferramenta falha sem finding estruturado.

`--force` é mantido somente por compatibilidade e não muda a política de workspace regenerável.

### `scripts/install-security-tools.sh`

Prepara Semgrep em `.tools/semgrep` usando `python3 -m venv` e uma versão fixável por `NINFA_SEMGREP_VERSION` (default atual `1.177.0`). Exige Python 3.10+. `NINFA_INSTALL_ZAP` não instala ZAP; apenas emite aviso de que DAST está fora do fluxo oficial.

### `scripts/setup-glpi-host.sh`

Prepara um host GLPI para uso/teste do profile. Usa `NINFA_GLPI_ROOT` ou um diretório sob `RUNNER_TEMP`, aceita somente versão 11 (`NINFA_GLPI_VERSION`, default atual `11.0.8`), clona o repositório GLPI e executa `composer install --no-dev` quando o host ainda não existe.

### `scripts/zap-scan.sh`

É um wrapper legado/congelado e não faz parte de `ninfa security`. Quando executado manualmente, exige alvo localhost/127.0.0.1, exige caminho de relatório externo, resolve ZAP por `NINFA_ZAP_BIN` ou `zaproxy` e executa quick active scan. A documentação no próprio script deve deixar explícito que ele não integra a pipeline pública atual.

## Padrão para PHPDoc e comentários de código

A documentação deve ser colocada junto ao símbolo que ela explica e conter somente fatos sustentados pelo código ou por uma decisão arquitetural explícita.

Para classes PHP de domínio/orquestração, o docblock deve responder, quando aplicável:

```text
Responsabilidade
Entrada principal
Saída principal
Efeitos colaterais
Invariantes/precedências importantes
O que a classe deliberadamente NÃO faz
```

Métodos públicos não triviais devem documentar formato de coleções/arrays, exceções relevantes e efeitos externos. Métodos privados só precisam de comentário quando a regra não é evidente pela assinatura e pelo nome.

Para scripts PHP executáveis, o cabeçalho deve registrar finalidade, argumentos/flags, arquivos gerados e exit codes relevantes.

Para shell scripts, os comentários iniciais devem registrar finalidade, variáveis de ambiente lidas, efeitos externos e condições de falha. Comentários dentro do script devem explicar decisões como restrição a localhost ou por que uma ferramenta é ignorada, não repetir comandos shell óbvios.

Para JavaScript próprio do Ninfa, quando existir, o cabeçalho do módulo deve registrar responsabilidade, entradas, efeitos sobre DOM/rede/estado e eventos emitidos/consumidos. Hoje não há arquivo `.js` próprio versionado no repositório.

## Critério de conclusão da Etapa 2.5

A etapa só deve ser marcada como concluída quando:

1. classes e scripts de produção tiverem documentação factual junto ao código;
2. scripts shell tiverem cabeçalhos com variáveis, efeitos e limites;
3. entrypoints PHP tiverem propósito, argumentos, efeitos e exit codes documentados;
4. o mapa deste documento estiver consistente com a implementação;
5. a suíte tiver uma verificação simples que impeça novos arquivos de produção sem documentação mínima;
6. a documentação for atualizada quando a responsabilidade de um componente mudar.
