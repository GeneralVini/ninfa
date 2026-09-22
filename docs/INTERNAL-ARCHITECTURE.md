# Arquitetura interna e documentação de código

Este documento descreve o fluxo interno implementado no Ninfa a partir do código atual. Ele não substitui PHPDoc, comentários de shell ou documentação junto ao código: serve como mapa para localizar responsabilidades e para evitar que comentários futuros contradigam a implementação.

## Objetivo da Etapa 2.5

A Etapa 2.5 existe para tornar o código compreensível sem depender de conhecimento tácito ou de uma IA para reconstruir o fluxo. A documentação interna deve registrar fatos observáveis na implementação: entradas, saídas, efeitos colaterais, invariantes, precedências, variáveis de ambiente, arquivos gerados, códigos de saída e limites de responsabilidade.

Não é objetivo repetir README, explicar sintaxe trivial ou escrever comentários que apenas traduzam o nome de um método.

## Premissa obrigatória de documentação

A documentação interna é parte do contrato de implementação do Ninfa, não um acabamento posterior. Código de produção novo ou alterado deve nascer documentado no mesmo commit.

A unidade mínima de documentação não é o arquivo. Um cabeçalho de classe, módulo ou script **não substitui** a documentação dos símbolos e blocos internos relevantes.

Para PHP, a premissa é:

1. toda classe de produção deve possuir PHPDoc com responsabilidade e limites;
2. todo método ou função nomeada de produção deve possuir PHPDoc descritivo, inclusive métodos privados;
3. `@param`, `@return`, `@var` e shapes devem preservar informação que o type hint nativo não consegue expressar;
4. `array` não deve ficar semanticamente sem tipo quando o código conhece o shape, a lista ou os tipos de chave/valor;
5. acumuladores e estruturas locais não triviais devem receber `@var` quando o tipo não é evidente ou se perde entre blocos;
6. blocos de controle relevantes devem explicar a decisão, a precedência ou a invariável que protegem; comentários que apenas narram `if`, `foreach` ou atribuições não atendem ao objetivo;
7. exceções relevantes, I/O, arquivos gerados, rede e mutações devem ser documentados junto do método que os executa.

Exemplo de informação insuficiente:

```php
/** @return list<Finding> */
public function scan(SecurityInventory $inventory): array
```

O tipo de retorno é útil, mas não explica quais componentes entram na consulta, quais efeitos externos existem, quais respostas são ignoradas, como falhas são tratadas ou o que o método deliberadamente não faz.

Quando uma coleção possui contrato conhecido, a documentação deve preservar esse contrato. Por exemplo:

```php
/** @var list<array{name:string,version:string,scope:string}> $packages */
$packages = [];
```

Para shell, a mesma premissa vale em outra sintaxe: cabeçalho do arquivo, funções e blocos semânticos devem registrar finalidade, variáveis relevantes, efeitos externos, restrições e motivo das validações. Um comentário antes de um `if` deve explicar **por que** aquela condição é uma fronteira importante, e não apenas dizer que o `if` verifica algo.

Para JavaScript próprio do Ninfa, quando existir, módulos e funções devem usar JSDoc com responsabilidade, tipos úteis, efeitos em DOM/rede/estado e eventos consumidos/emitidos. Estruturas complexas devem usar typedefs/shapes em vez de `Object` genérico quando o formato for conhecido.

A suíte não mantém mais exceção/allowlist para `src/`: todos os arquivos de produção desse diretório estão sujeitos ao mesmo padrão estrito. Arquivo novo entra automaticamente nas verificações de documentação e não existe mecanismo previsto para registrar nova dívida como exceção permanente.

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

Representa o resultado de uma etapa. Os estados implementados são `ok`, `failed`, `error` e `skipped`. Mantém exit code, detalhe, duração e findings associados. O contrato rejeita estado incompatível com o exit code e duração negativa; somente `skipped` não possui exit code.

### `src/RunResult.php`

Consolida a operação e todos os `ToolResult`. O exit final é derivado do primeiro código diferente de zero, preservando a ordem das etapas. `findings()` apenas achata os findings das ferramentas; o objeto não deduplica vulnerabilidades nem calcula prioridade.

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

`OsvClient` foi o primeiro arquivo usado como referência do padrão documental estrito da Etapa 2.5. O mesmo nível de documentação agora se aplica a **todos os arquivos de `src/`**, incluindo métodos públicos/privados, contratos de collections/shapes e comentários de decisão/invariante.

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

A documentação deve ser colocada junto ao símbolo ou bloco que ela explica e conter somente fatos sustentados pelo código ou por uma decisão arquitetural explícita.

Para classes PHP de domínio/orquestração, o docblock deve responder, quando aplicável:

```text
Responsabilidade
Entrada principal
Saída principal
Efeitos colaterais
Invariantes/precedências importantes
O que a classe deliberadamente NÃO faz
```

Para **todo método e função nomeada de produção**, inclusive privados, o PHPDoc deve informar a responsabilidade daquele símbolo. Quando houver arrays, coleções, callbacks ou dados heterogêneos, os tipos genéricos/shapes devem ser registrados com `@param`, `@return` e/ou `@var`. Uma anotação contendo apenas `@return list<X>` não é suficiente quando o comportamento do método possui regras relevantes que não aparecem na assinatura.

Variáveis locais não precisam receber comentários redundantes quando o tipo é escalar e evidente. Porém acumuladores, mapas indexados, filas, payloads, respostas JSON e outras estruturas compostas devem receber `@var` quando isso evita perder informação de tipo ou semântica entre blocos.

Blocos de controle devem ser comentados quando expressam uma decisão de negócio, segurança, precedência, fallback, correlação, política de erro ou proteção contra estado ambíguo. O comentário deve explicar a intenção/invariante; não deve apenas traduzir a condição para português.

Para scripts PHP executáveis, o cabeçalho continua obrigatório, mas também são exigidos PHPDoc nas funções nomeadas e comentários nos blocos semânticos relevantes do fluxo principal.

Para shell scripts, os comentários iniciais registram finalidade, variáveis de ambiente lidas, efeitos externos e condições de falha. Além disso, funções e blocos semânticos (`if`, `case`, loops de política/fallback) devem ter comentários locais que expliquem o motivo da decisão, especialmente em validações de segurança, precedência de ferramentas e operações externas. `tests/shell-docs.php` percorre recursivamente os scripts versionados e protege esse contrato.

Para JavaScript próprio do Ninfa, quando existir, o cabeçalho do módulo e cada função nomeada devem registrar responsabilidade e tipos úteis via JSDoc. Efeitos sobre DOM/rede/estado e eventos emitidos/consumidos devem ser explícitos. Hoje não há arquivo `.js` próprio versionado no repositório, mas `tests/internal-docs.php` já aplica o requisito mínimo automaticamente quando um módulo aparecer.

## Padrão estrito aplicado

`tests/internal-docs.php` aplica o padrão documental a **todo `src/*.php`**, sem allowlist. O guard verifica presença de PHPDoc narrativo em classes e métodos/funções nomeadas, exige tipos genéricos/shapes quando uma assinatura PHP usa `array`, exige `@var` próximo de acumuladores inicializados como arrays vazios e sinaliza arquivos com fluxo de controle relevante sem comentário local de decisão/invariante.

`src/OsvClient.php` foi a referência inicial para calibrar o nível de detalhe, mas não possui exceção especial. `PipelineRunner`, `SecurityInventory`, `ProjectContext`, parsers, modelos de resultado, detectores, geradores e utilitários seguem a mesma premissa.

`tests/shell-docs.php` protege todos os `.sh` versionados em `scripts/` e `tests/`. O guard de PHP também prepara a regra de JSDoc para futuros `.js`, `.mjs` e `.cjs` próprios do Ninfa.

Não existe allowlist de dívida documental em `src/`. Se um arquivo novo ou alterado não atender o padrão, a suíte deve falhar em vez de registrar uma nova exceção.

## Conclusão da Etapa 2.5

A Etapa 2.5 está concluída com os seguintes critérios atendidos:

1. classes, métodos e funções nomeadas de produção possuem documentação factual junto ao código;
2. arrays/coleções/estruturas compostas preservam genéricos ou shapes quando a assinatura nativa perde informação;
3. acumuladores e estruturas locais relevantes usam `@var` quando necessário para preservar tipo/semântica;
4. scripts PHP e shell possuem documentação interna em funções e blocos semânticos, não apenas cabeçalhos;
5. futuros módulos JavaScript estão sujeitos ao mesmo princípio por JSDoc;
6. este mapa foi revisado contra a implementação após a migração completa;
7. a suíte impede regressão do padrão estrito;
8. a allowlist de dívida documental foi eliminada;
9. a documentação continua sendo atualizada no mesmo commit quando a responsabilidade de um componente muda.

Com a Etapa 3 encerrada, o roadmap avança para contratos SAST e especializações de profile.
