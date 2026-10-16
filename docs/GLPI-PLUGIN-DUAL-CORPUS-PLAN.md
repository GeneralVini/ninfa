# EAP — amadurecimento GLPI Plugin por dois consumidores independentes

> **EAP mestre:** [GLPI-PLUGIN-EAP.md](GLPI-PLUGIN-EAP.md) — coordenação das cinco ondas, IDs, prioridades, evidências e gates. Este arquivo mantém seu escopo especializado e não deve ser usado como segunda fonte concorrente de status.

## Atualização da origem SIGAPS — 2026-10-15 (referência GitHub confirmada)

Por indicação explícita do mantenedor e verificação de leitura via GitHub, o corpus **SIGAPS GLPI** adota como referência canônica de código o repositório privado [`GeneralVini/sigaps_glpi`](https://github.com/GeneralVini/sigaps_glpi), branch `main`, revisão imutável `d039aec352b45a89117fa7bf526562bc1a4645bc`. `composer.json` declara `type=glpi-plugin`, PSR-4 `GlpiPlugin\\Sigaps\\`, PHP >=8.2, PHPStan e `glpi-project/phpstan-glpi` em require-dev; `setup.php` e `hook.php` estão presentes. A existência das dependências em Composer **não comprova instalação no host**.

A referência canônica de código **não prova** identidade do checkout local `/var/www/psglpi/plugins/sigaps` nem estabelece host GLPI 11 executável. `GeneralVini/sigaps` continua **fora desta trilha**. Histórico do PR #1 permanece ligado ao seu contexto de origem; não inferir equivalência entre a revisão histórica e a revisão atual. Busca GitHub de issues do repositório retornou nenhuma issue na página consultada, mas **não substitui** chats, relatórios e demais fontes legadas — contagem total segue desconhecida. ÉGIDE continua consumidor independente. Nenhum código privado foi incorporado ao NINFA público.


**Decisão:** o SIGAPS e o ÉGIDE são **dois consumidores permanentes** do perfil `glpi-plugin`, com cobertura, baselines, pendências e critérios de aceite independentes. A inclusão do ÉGIDE **não substitui nem apaga** findings, issues, relatórios anteriores ou trabalho pendente do SIGAPS.

**Repositórios/origens verificadas até 2026-10-15:**
- **SIGAPS GLPI (referência local):** `/var/www/psglpi/plugins/sigaps`. A árvore não está acessível neste ambiente. **Identidade/remote desse checkout e issues locais ainda precisam ser conciliados.** Existe também `GeneralVini/sigaps`, mas sua estrutura vista no GitHub aparenta ser uma aplicação Yii2; **não tratá-la automaticamente como o plugin GLPI** nem migrar suas issues para a linha GLPI sem comprovação de origem.
- **ÉGIDE GLPI:** [GeneralVini/egide](https://github.com/GeneralVini/egide), repositório privado, branch `main`, com `setup.php`, `hook.php`, `composer.json` (`type=glpi-plugin`) e PSR-4. Inspeção textual read-only realizada. **O host e o caminho local de homologação continuam não confirmados.**
- **NINFA:** [PR #15](https://github.com/GeneralVini/ninfa/pull/15), branch `feature/glpi-plugin-closure`. Yii2 permanece congelado.

### Evidências históricas já recuperadas

A primeira recuperação verificável do repositório NINFA está em [GLPI-PLUGIN-SIGAPS-HISTORICAL-EVIDENCE.md](GLPI-PLUGIN-SIGAPS-HISTORICAL-EVIDENCE.md) e no [ledger versionado](GLPI-PLUGIN-HISTORICAL-LEDGER.json): PR #1 documenta validação anterior no SigaPS e três apontamentos P1 de review sobre configuração e portabilidade. Os P1-01/02 já têm regressões sintéticas aprovadas; P1-03 permanece parcialmente aberto quanto à herança de regras personalizadas do consumidor. **Esses apontamentos são issues da infraestrutura NINFA observadas na revisão do PR, não necessariamente defeitos do código SIGAPS.** Nenhum deles está resolvido por mera mudança arquitetural ou por ausência de GitHub Issue. O inventário integral dos chats ainda não foi consultável nesta execução; sua recuperação continua bloqueio de DUAL-0.

## 1. Regras de governança (sem exceções tácitas)

1. **Preservação histórica:** importar e preservar integralmente as issues e findings anteriores do SIGAPS, mesmo se uma execução atual não conseguir reproduzi-los. Um scan novo nunca sobrescreve o registro antigo.
2. **Independência:** cada ocorrência tem `consumer=SIGAPS|EGIDE`, referência de origem, arquivo/linha (em registro **privado**, não no PR público), revisão analisada, host GLPI, ferramenta/regra/versão e status. A mesma causa NINFA pode estar ligada a duas ocorrências, mas isso **não colapsa** as duas issues.
3. **Sem fechamento implícito:** ausência em scan novo = `NOT_REPRODUCED`, não `RESOLVED`. `FALSE_POSITIVE_VERIFIED` requer revisão de evidência e fixture negativa. `FIXED_NINFA` requer regressão; resolver issue do consumidor exige verificação no consumidor.
4. **Prioridade das dívidas antigas:** SIGAPS legado participa **antes** da busca por features do ÉGIDE. Cada tranche deve listar `SIGAPS legacy impacted`, `SIGAPS new`, `EGIDE impacted` e `shared`; nenhuma tranche é aceita só porque o ÉGIDE passou.
5. **Conservadorismo:** `unknown` é um resultado legítimo; falso positivo não é custo tolerável para elevar a cobertura. `correctness`, `security-smell`, `security`, `architecture` e `deprecation` mantêm significados separados. SAFE apenas com prova mecânica de equivalência e idempotência.
6. **Intocabilidade:** os **dois** originais são somente leitura, inclusive caches/temporários. Nunca rodar `ninfa fix` ou migrations nos originais, executar PHP do plugin para obter semântica, escrever no host, rodar install/update, ou copiar código privado como fixture versionada do NINFA.
7. **Privacidade:** manter evidências sensíveis, paths internos e relatórios completos em um registro privado autorizado. No PR público usar apenas estatísticas agregadas e exemplos mínimos sintéticos, sem reproduzir trechos proprietários.

## 2. EAP por entregas e critérios de aceite

| EAP | SIGAPS — obrigação específica | ÉGIDE — obrigação específica | Entrega comum do NINFA / aceite |
| --- | --- | --- | --- |
| **DUAL-0: custódia** | Confirmar qual repositório/checkout corresponde ao plugin GLPI; localizar issues, relatórios, exports, notes, logs e findings legados; registrar IDs e estados originais **sem perdê-los** | Fixar URL privada, commit SHA, layout de plugin e dependências do host; confirmar checkout/host para field test | Inventário rastreável e baseline imutável de fontes; nenhuma issue legada descartada |
| **DUAL-1: baseline reproduzível** | Rodar check/assist/security **na cópia isolada**; capturar falhas dos scanners e preservar findings antigos mesmo quando ausentes | Rodar os mesmos modos na cópia isolada, com configuração de host e nível de ferramentas documentados | Resultado separado por consumidor, revisão e host; métricas e arquivos no workspace externo; nenhum write nos originais |
| **DUAL-2: lifecycle e hooks** | Exercitar os hooks reais observados e validar `COR-001..003`; garantir que registros antigos do SIGAPS não virem unknown/FP silenciosamente | Exercitar constantes `Hooks::*`, hooks de função, arrays, argumentos `::class` e chamadas compostas observadas | Resolver apenas referências comprovadas; fixtures TP/TN/unknown para **cada** padrão incluído |
| **DUAL-3: tipos/callbacks/autoload** | Mapear namespace, registradores dinâmicos, legacy/host callbacks e issues preexistentes relacionadas | Mapear PSR-4 e callables por classe/método, registros `Plugin::registerClass()` e aliases | Modelo `TypeProof/ClassHierarchy/CallableResolution` sem execução; `declared=true/null` até haver prova negativa completa |
| **DUAL-4: autorização e SAST** | Recuperar e triar as pendências SIGAPS de Session/rights/CSRF, SQL, upload e escaping; preservar source IDs | Exercitar endpoints, Session/rights, hooks públicos, request/response, SQL, output e tarefas cron | Security somente com fluxo/contexto comprovado; smells ficam REVIEW; sem falso positivo promovido a vulnerabilidade |
| **DUAL-5: banco, migrations, compat. GLPI 11** | Revisar migrations/lifecycle e achados legados de SQL/schema sem executar install/uninstall | Analisar contratos observados de migrations/installer/desinstalação e versões declaradas | Catálogo GLPI 11 versionado; regras SAFE só se equivalentes/idempotentes; demais REVIEW/SEMANTIC |
| **DUAL-6: regressão cruzada** | Reexecutar **toda** a baseline antiga + nova SIGAPS; justificar cada mudança de status | Reexecutar baseline ÉGIDE após cada tranche | Comparar TP, FP, FN conhecidos e unknowns separadamente; falha num consumidor impede aceitar regra comum |
| **DUAL-7: homologação/gate** | 100% das issues legadas têm identidade e decisão registrada, inclusive `DEFERRED`; P0/P1 sem pendência **não decidida** | Baseline completa, P0/P1 triados; incompatibilidades explicadas | PHP/Go CI, SAST, docs, integridade dos originais e relatórios auditáveis. PR revisado; **sem merge automático** |

**Dependências:** DUAL-0 bloqueia encerramento de DUAL-1 e DUAL-7.**P1-03 da revisão do PR #1 permanece em aberto por cobertura:** o NINFA preserva as configurações personalizadas dos consumidores e informa que não são mescladas. O novo teste `tests/glpi-consumer-config-coverage.php` comprova o alerta em `check/assist`, mas o gate cruzado SIGAPS/ÉGIDE deve registrar quais regras próprias deixam de ser aplicadas. A composição dessas configurações exige decisão técnica separada, sem executar código de terceiros apenas para inferir semântica.

 DUAL-1 fornece corpus real para DUAL-2..5. DUAL-6 repete-se após **cada** alteração semântica, não só ao final.

## 3. Registro mestre de issues e política de não descarte

O ledger é **append-only** no sentido lógico: revisões acrescentam eventos de triagem, não apagam a referência ou a evidência original. Não é necessário publicar achados privados no NINFA público.

**Campos mínimos por ocorrência privada:**

```text
case_id                 ex.: SIGAPS-MIG-0001 (somente ao inventariar caso real)
consumer                SIGAPS | EGIDE
source_kind             existing_issue | previous_scan | current_scan | manual_review
source_ref              URL/ID do issue ou ID local do relatório; nunca criar referência fictícia
first_seen_revision     hash/revisão original (ou unknown)
scan_revision           hash do consumidor atualmente analisado
ninfa_revision          hash do NINFA executado
host_glpi_version       versão detectada, ou unknown
rule_id                 identificação original e mapeamento de renomes
fingerprint             regra + símbolo + localização normalizada + contexto semântico
category / severity     classificação preservada / decisão revisada
outcome                 TP | FP | TN | FN | UNKNOWN | NOT_REPRODUCED
status                  OPEN | IN_TRIAGE | FIXED_NINFA | DEFERRED |
                        FALSE_POSITIVE_VERIFIED | RESOLVED_IN_CONSUMER
evidence_location       referência a artefato privado imutável
decision / reviewer     justificativa e validação
linked_ninfa_issue      issue NINFA compartilhado, se houver
regression_fixture      ID da fixture sintética de regressão, se aplicável
```

**Migração da fila SIGAPS:** importar tudo o que existir em GitHub Issues do **projeto correto**, relatórios NINFA anteriores, saídas Semgrep/PHPStan/Psalm, tickets e anotações locais. Manter também cases originalmente triados como `false-positive` ou `won't-fix` com seus motivos, sem reaproveitar IDs. Até inventariar as fontes, **a quantidade de issues SIGAPS é desconhecida, não zero**.

**Deduplicação:** vários findings da **mesma execução** podem compartilhar fingerprint, mas a ocorrência SIGAPS e a ocorrência ÉGIDE retêm `case_id` separados. Uma issue de engine NINFA pode vincular ambos sem substituir as duas trilhas de evidência.

**Fechamento:** nenhuma issue antiga SIGAPS vira `RESOLVED_IN_CONSUMER` porque o ÉGIDE passou, a regra mudou de nome, o finding ficou `unknown`, ou ela deixou de aparecer após upgrade. A verificação e aprovação precisam mencionar explicitamente o `case_id` original.

## 4. Matriz obrigatória por regra/tranche

| Verificação de aceite | SIGAPS | ÉGIDE |
| --- | --- | --- |
| Fonte e baseline preservados | Obrigatório, inclusive legacy | Obrigatório a partir da primeira inspeção real |
| True positive e false positive revisitados | Obrigatório onde houver casos | Obrigatório onde houver casos |
| Unknown com justificativa de completude | Obrigatório | Obrigatório |
| Scan do consumidor somente por cópia | Obrigatório | Obrigatório |
| Trilha de findings antes/depois | Obrigatório | Obrigatório |
| Regressões do consumidor não pioraram | Obrigatório | Obrigatório |

Se um padrão estiver ausente em um consumidor, registrar `NOT_OBSERVED` (não confundir com `PASSED`), criar fixture sintética relevante e não forçar uma regra para produzir resultados.

## 5. Ordem de trabalho imediata

1. **Prioridade 1: inventário legado do SIGAPS.** Ler o remote e os relatórios existentes do checkout GLPI **sem escrever no original**. Desambiguar do repositório `GeneralVini/sigaps` que aparenta ser Yii2. Capturar cada issue preexistente com sua origem e estado, antes de executar novo baseline.
2. **Prioridade 2: baseline independente ÉGIDE.** Prender `GeneralVini/egide@<SHA>` e confirmar host; inspecionar estaticamente e depois rodar scanners apenas em cópia, sem copiar código privado para NINFA público.
3. **Prioridade 3: triagem cruzada.** Agrupar por regras/provas (lifecycle, hooks, callables, ACL, DB) mantendo casos independentes; priorizar primeiro problemas **reais ou suspeitos herdados do SIGAPS**, depois lacunas observadas no ÉGIDE, ponderando criticidade.
4. **Prioridade 4: tranches curtas.** Uma família semântica por PR/commit, com documentação, fixtures sintéticas TP/TN/unknown, `ninfa check/assist` e validação da baseline dos **dois** plugins quando disponíveis.
5. **Prioridade 5: gate de encerramento.** Só declarar `glpi-plugin` baseline fechado após reconciliação do SIGAPS, corpus real de ambos, precisão revisada, CI verde e integridade comprovada. Questões diferidas permanecem registradas com decisão explícita, não escondidas.

## 6. Execução sem modificar os projetos

- Usar `scripts/glpi-field-test.sh` apenas sobre uma **cópia descartável**; esse harness não substitui isolamento real de filesystem/permissões. O original e host devem ser inacessíveis para escrita à conta de análise.
- Guardar artefatos em `/tmp/ninfa/.../` ou workspace privado autorizado, separado por `consumer/revision/mode`; não pôr reports/caches dentro dos plugins.
- Nunca rodar `ninfa fix`, instaladores ou migrations diretamente no SIGAPS/ÉGIDE originais ou em host/DB produtivos.
- Verificar integridade antes/depois e guardar falhas de ferramenta como `INCONCLUSIVE`, não scan bem-sucedido.

**Estado da proposta:** EAP registrada para orientação e revisão. Isto **não** constitui execução dos field tests nem aprovação automática do gate. Baseline e contagem das issues SIGAPS ainda precisam ser identificadas com evidência.

## DUAL-P1-03A — evidência por analisador

O manifesto GLPI do workspace agora discrimina os quatro analyzers, incluindo detecção de arquivo local, indicação explícita de que sua configuração **não** foi aplicada, proveniência `ninfa-generated` e digest do arquivo externo. A regressão sintética no CLI protege esse contrato. Não é composição opt-in, não é field test e não resolve o caso histórico P1-03. A matriz SIGAPS/ÉGIDE segue independente, com questões SIGAPS legadas ainda por reconciliar.
