# Revisão de lacunas — profile GLPI Plugin 11

**Data:** 2026-10-15  
**Escopo auditado:** PR #15 (`feature/glpi-plugin-closure`) versus `main`.  
**Estado:** ainda **não elegível** ao gate de fechamento GLPI.

Esta revisão separa defeitos efetivos, cobertura deliberadamente parcial e trabalho de homologação. Verde em CI sintético não implica compatibilidade comprovada em plugins reais.

## Correções objetivas realizadas durante esta revisão

| Identificação | Problema | Situação |
| --- | --- | --- |
| REV-001 | Harness criava cópia sempre com basename `plugin`, invalidando resolução de `plugin_init_<nome>` e callbacks de lifecycle; field test SIGAPS produziria falsos positivos. | **Corrigido:** `CLONE="$SANDBOX/$(basename "$SOURCE")"`. |
| REV-002 | Parser de hooks aceitava método de classe homônimo `plugin_init_<nome>()` (com `global $PLUGIN_HOOKS`) como se fosse callback global real. | **Corrigido:** definição precisa ter profundidade estrutural zero, com regressão para método homônimo e init condicional. |
| REV-003 | Harness não possuía teste automatizado de preservação do nome técnico. | **Corrigido:** `tests/glpi-field-test.sh` simula `php bin/ninfa` sem executar scanners/consumidor real e comprova cópia, basename e comparação de integridade. |

## Lacunas de implementação a resolver ou classificar explicitamente

| Prioridade | Assunto | Consequência / decisão de aceite |
| --- | --- | --- |
| P0 | **Isolamento de field test real** | Cópia e hash posterior não impedem escrita transversa por autoload, paths absolutos ou ferramentas externas. Homologar apenas sob conta sem permissão de escrita no SIGAPS original e, preferencialmente, isolamento/mount read-only do original e host, além de workspace próprio. |
| P1 | **Semântica nativa disponível somente em `assist`** | `COR-001..003` entram no `ninfa assist`, não no `ninfa check` executado via `PipelineRunner`. Definir contrato de gate/exit codes e integrar check (sem contaminar security) antes de declarar cobertura automática. |
| P1 | **Hooks definidos com `Glpi\Plugin\Hooks::*`** | Formato importante do GLPI 11, ainda marcado `unknown` por não resolver constantes do host; extrair mapa oficial de versões e resolver apenas símbolos comprovados. |
| P1 | **Callbacks e classes registrados** | Não verifica resolução das strings de callback, callables `[Classe::class, método]`, first-class callable ou `Plugin::registerClass()`. Necessário índice de funções/classes local + host para emitir apenas negativas conclusivas. |
| P1 | **Autorização / Session / CSRF em endpoints** | Nenhuma regra nativa baseada em fluxo ou entrypoint HTTP comprovado; não promover cheiro de código a vulnerabilidade. Validar guards, origem e sinks com fixtures e host 11 antes de `security`. |
| P1 | **DB / migrations / versões** | Falta modelagem de `Migration`, lifecycle de schema, permissões de tabela e deprecações de GLPI 11. Não executar migrations do consumidor como forma de inferir fatos. |
| P1 | **Calibração Semgrep/taint** | Overlay tem regras existentes, mas são necessários corpus real e análise de falso positivo/negativo, especialmente `$DB->request`, sanitizers, SSRF e redirecionamentos. |
| P2 | **Contrato `plugin_version_<nome>()`** | O modelo prova apenas existência, não valida shape estático/compatibilidade declarada GLPI/PHP; valores calculados precisam permanecer `unknown`. |
| P2 | **Lifecycle pré-sessão `plugin_<nome>_boot()`** | Callback opcional documentado, pertinente a APIs stateless e GLPI 11; não é obrigatório, portanto ausência nunca deve gerar `COR-002`. |
| P2 | **Detecção e política operacional** | Testar variantes reais de nome/layout de plugin, resolução de host, symlinks, caches, permissões, extensões/autoload e fronteira consumidor/host. |
| P2 | **Custo e precisão de parsing** | `GlpiHookRegistryAnalyzer` examina escopos repetidamente e aceita apenas literais; documentar/perfilar em `setup.php` grandes antes de generalizar. |

## Contratos GLPI usados como referência

- https://glpi-developer-documentation.readthedocs.io/en/master/plugins/requirements.html
- https://github.com/glpi-project/docdev/blob/master/source/plugins/hooks.rst
- https://github.com/pluginsGLPI/fields/blob/main/setup.php
- https://github.com/pluginsGLPI/treeview/blob/main/setup.php
- https://github.com/pluginsGLPI/empty/blob/main/setup.php.tpl
- https://github.com/pluginsGLPI/empty/blob/main/hook.php.tpl

As fontes oficiais confirmam `setup.php`/`hook.php`, callbacks de lifecycle, registry indexado por hook e nome do plugin e o uso real de constantes `Hooks::*`. A existência de um exemplo upstream não prova sozinho que toda variante dinâmica seja inválida.

## Plano recomendado para encerrar com baixo falso positivo

1. Fechar regressões da presente revisão e comprovar CI no **HEAD final**.
2. Definir integração `check` vs `assist` da rule engine GLPI sem alterar a taxonomia de segurança.
3. Resolver constantes e callbacks de hook com índice estático e três estados (exists/missing/unknown), incluindo `Plugin::registerClass()`.
4. Validar contratos de `plugin_version`, Session/rights e DB sem executar código do consumidor.
5. Calibrar overlay Semgrep e executar corpus GLPI 11 de múltiplos plugins.
6. Homologar SIGAPS **apenas por cópia e isolamento efetivo de escrita**, registrar hash/metadata do original e evidências de scanner.
7. Revalidar syntax, shell/internal-docs, profiles, Semgrep e Go; só depois solicitar revisão de merge.

**Não cumprido:** homologação SIGAPS original; não há acesso ao path local neste ambiente e nenhuma ferramenta foi executada sobre ele.
