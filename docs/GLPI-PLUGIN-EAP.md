# EAP mestre — maturação do perfil `glpi-plugin` para GLPI 11

> **Controle:** documento mestre de execução, mantido na branch `feature/glpi-plugin-closure` / [PR #15](https://github.com/GeneralVini/ninfa/pull/15). Atualização inicial: 2026-10-15 (America/Sao_Paulo). **Não é declaração de homologação.**
>
> **Escopo:** evolução reutilizável do NINFA para plugins GLPI 11. O perfil Yii2 permanece fora desta frente. Sem merge automático nem alteração dos consumidores.

## 1. Objetivo e fontes canônicas

Maturar o `glpi-plugin` do NINFA através de **duas trilhas permanentes e independentes**:

| Consumidor | Fonte de código autorizada | Baseline de referência | Evidência e limites |
| --- | --- | --- | --- |
| SIGAPS GLPI | [`GeneralVini/sigaps_glpi`](https://github.com/GeneralVini/sigaps_glpi) (privado) | `main@d039aec352b45a89117fa7bf526562bc1a4645bc` | Metadados de plugin GLPI, `setup.php`, `hook.php`, PSR-4; checkout local `/var/www/psglpi/plugins/sigaps` ainda requer correlação inequívoca de remote/commit e host |
| ÉGIDE GLPI | [`GeneralVini/egide`](https://github.com/GeneralVini/egide) (privado) | `main` inspecionado; **SHA ainda a fixar na baseline operacional** | Metadados GLPI 11 e PSR-4 observados; checkout/host local e field test não confirmados |

**`GeneralVini/sigaps` não é a fonte autorizada da trilha SIGAPS GLPI.** A validação histórica no PR #1 não equivale à validação atual. Os três P1 históricos do PR #1 são preocupações de infraestrutura NINFA, não bugs automaticamente comprovados do código SIGAPS.

Fontes normativas sem duplicação: [plano dual de custódia](GLPI-PLUGIN-DUAL-CORPUS-PLAN.md), [corpus de campo](GLPI-PLUGIN-FIELD-TEST-CORPUS.md), [histórico SIGAPS](GLPI-PLUGIN-SIGAPS-HISTORICAL-EVIDENCE.md), [ledger imutável](GLPI-PLUGIN-HISTORICAL-LEDGER.json), [contrato de configuração](GLPI-PLUGIN-CONSUMER-CONFIG-CONTRACT.md) e [gate técnico](GLPI-PLUGIN-CLOSURE.md).

## 2. Políticas inegociáveis

1. SIGAPS e ÉGIDE têm **identidades, baselines, findings, revisões e decisões distintas**. Uma correção comum pode relacionar duas ocorrências, nunca substituí-las.
2. Histórico SIGAPS é preservado mesmo quando findings deixam de aparecer: `NOT_REPRODUCED` não significa `RESOLVED`. Quantidade histórica ainda **desconhecida**, não zero; manter as filas de chats e relatórios abertas até inventário comprovado.
3. Original de ambos os consumidores e host GLPI: **somente leitura efetiva**, inclusive cache e arquivos temporários. Executar scanners em cópias isoladas, sem privilégios de escrita nos originais. Não executar `ninfa fix`, migrations, instalações, updates ou código do consumidor para obter semântica.
4. Não incluir conteúdo privado dos consumidores em commits/fixtures públicas; usar somente cenários sintéticos autorizados. Artefatos sensíveis em armazenamento privado.
5. SAST, correctness, security-smell, vulnerabilidade e deprecation são classificações independentes. `unknown` e `INCONCLUSIVE` devem permanecer observáveis.
6. Gates exigem evidência por SHA de consumidor, SHA NINFA, host, tool/version, configuração, execução e revisor. CI sintético verde não prova homologação real.
7. Manter PR #15 em **draft** até aprovação formal. Yii2 fora do escopo.

## 3. Legenda operacional

**Estado:** `TODO` · `IN_PROGRESS` · `BLOCKED` · `IN_REVIEW` · `VALIDATED` · `DEFERRED`. Só usar `VALIDATED` quando todos os critérios e evidências **do escopo declarado** estão comprovados; registrar limitações explicitamente. `BLOCKED` significa dependência externa não satisfeita.

**Prioridade:** `P0` risco impeditivo imediato, `P1` lacuna de gate/correção prioritária, `P2` melhoria sem impedimento crítico. IDs `EAP-xx.yy` são permanentes; não renumerar ao replanejar.

**Regra de unidade de entrega:** ondas integradas de implementação + testes + documentação + rastreabilidade; commits menores são aceitos, mas **não** declarar uma onda concluída com apenas documento ou fixture sem execução quando sua definição exige field test.

## 4. EAP hierárquica e critérios de conclusão

| EAP | Pacote de trabalho / artefato | Dependências | Estado inicial | Evidência obrigatória para conclusão |
| --- | --- | --- | --- | --- |
| **EAP-01** | **Onda 1 — baseline, fontes e custódia** | — | IN_PROGRESS | Dossiês independentes e históricos reconciliados |
| EAP-01.01 | Fixar origem, SHA e identidade dos dois consumidores | — | IN_PROGRESS | URL+SHA de ambos, `composer.json`, entrypoints, host e correlação do checkout (ou bloqueio registrado) |
| EAP-01.02 | Recuperar ledger histórico SIGAPS e reconciliar fontes | 01.01 | BLOCKED | Cada finding antigo com ID, fonte, severidade, resultado e decisão; total conhecido; chats/relatórios revisados |
| EAP-01.03 | Baseline `check/assist/security` em cópias isoladas | 01.01 | BLOCKED | Host GLPI confirmado, integridade original antes/depois, logs privados por operação/consumer/SHA, falhas inconclusivas explícitas |
| **EAP-02** | **Onda 2 — motor semântico GLPI** | 01.01; regressões sintéticas podem antecipar 01.03 | IN_PROGRESS | Provas e regressões cruzadas sem falsos positivos presumidos |
| EAP-02.01 | Lifecycle e hooks `setup.php`, `hook.php`, `Hooks::*` | 01.01 | IN_PROGRESS | Registros provados; fixtures TP/TN/unknown e `COR-001..003` consistentes no CLI |
| EAP-02.02 | Classes, aliases, PSR-4, métodos, callables e `registerClass()` | 02.01 | IN_PROGRESS | Indices declarativos e cenários desconhecidos; testes comparativos sem importar código privado |
| EAP-02.03 | Integração de fatos semânticos ao `check/assist` | 02.01–02.02 | IN_PROGRESS | Findings, `RunResult` e JSON externo consistentes; regressões e schema documentados |
| **EAP-03** | **Onda 3 — pipeline de qualidade e SAST** | 01.01; 02.03 quando a semântica for consumida | IN_PROGRESS | Origem e aplicação real dos configs rastreadas |
| EAP-03.01 | Transparência das configurações por analisador (P1-03A) | — | IN_REVIEW | `consumer-config-advisory.json` por ferramenta, SHA dos arquivos gerados, `applied=false`, regressão CLI e CI do head aprovados |
| EAP-03.02 | PHPStan `consumer-aware` opt-in (P1-03B) | 03.01 | TODO | Regra sentinela local muda resultado **real** do scanner com opt-in; precedência/host/extension e fail-closed testados |
| EAP-03.03 | Psalm, ECS e Rector com política por ferramenta | 03.02 | TODO | Psalm config/baseline testados; PHP executável (ECS/Rector) apenas após isolamento demonstrado; falhas explícitas |
| EAP-03.04 | Semgrep, Composer Audit, taint e correlação de findings | 03.01 | TODO | Proveniência e fingerprint, versão de ruleset, TP/FP/FN/unknown e deduplicação com trilhas independentes |
| **EAP-04** | **Onda 4 — segurança e compatibilidade GLPI 11** | 02; 03; corpus da 01.03 para aceite real | TODO | Regras conservadoras verificáveis com cenário positivo/negativo |
| EAP-04.01 | ACL/Session/rights, endpoints e CSRF | 02.03 | TODO | Contexto, fluxo e direitos comprovados; smells não promovidos a vulnerabilidade sem evidência |
| EAP-04.02 | SQL, escaping, upload e taint | 03.04 | TODO | Fonte→sink e mitigação documentados; triagem independente SIGAPS/ÉGIDE |
| EAP-04.03 | Migrations, instaladores e contratos de APIs GLPI 11 | 02.03 | TODO | Leitura estática apenas; versionamento de API; SAFE exige equivalência e idempotência, sem executar migrations |
| **EAP-05** | **Onda 5 — homologação e gate** | 01–04 | BLOCKED | Aprovação explícita e evidências completas |
| EAP-05.01 | Regressão cruzada por tranche | 01.03; ondas semânticas aplicáveis | BLOCKED | Revisões antes/depois por consumidor, `TP/FP/FN/UNKNOWN/NOT_REPRODUCED`, diferenças justificadas |
| EAP-05.02 | CI PHP/Go/SAST e documentação consolidada | 02–04 | TODO | CI verde no SHA candidato, testes, PHPDoc, `.md`, comandos, READMEs e regras versionadas coerentes |
| EAP-05.03 | Revisão P0/P1 e decisão de release | 01.02; 05.01–05.02 | BLOCKED | 100% das ocorrências legadas SIGAPS identificadas/decididas, P0/P1 explicitamente triados nos dois consumidores, aprovação humana; nunca merge automático |

*Estados iniciais são estimativas conservadoras de coordenação, não execução ou aprovação de gates. A revisão documental e os testes sintéticos anteriormente criados não equivalem a field test.*

## 5. Sequenciamento e estratégia de execução

- **Onda 1 (fundação):** firmar duas revisões congeladas, inventariar fontes e preparar isolamento. Não suspender desenvolvimento puramente sintético das ondas 2 e 3 enquanto o host/checkout não estiver disponível; registrar impedimentos sem mascará-los.
- **Onda 2 (semântica):** priorizar padrões reais documentados em SIGAPS e ÉGIDE, converter lacunas em fixtures públicas **sintéticas** e validar estados `true/null/false` somente quando a prova suportar.
- **Onda 3 (analyzers):** implementar primeiro telemetria; depois PHPStan com opt-in; Psalm em etapa seguinte e ECS/Rector somente com isolamento adicional. Jamais declarar regras locais `applied` pela mera detecção de arquivo.
- **Onda 4 (segurança/compatibilidade):** estudos estáticos de permissões, hooks, banco e GLPI 11, sem ação invasiva sobre consumidores.
- **Onda 5 (homologação):** repetição cruzada de todas as regressões, validação dos dois consumidores e fechamento da fila SIGAPS legada antes de promover o gate.

**Princípio anti-microentregas:** cada onda termina com uma entrega integrada e revisável. Registros intermediários servem à rastreabilidade, não representam aceite isolado.

## 6. Matriz de rastreabilidade mínima

| EAP | Código ou artefato | Testes / evidência | SIGAPS | ÉGIDE |
| --- | --- | --- | --- | --- |
| 01.01–01.03 | `docs/GLPI-PLUGIN-FIELD-TEST-CORPUS.md`; `scripts/glpi-field-test.sh` | Relatórios privados por SHA + check/assist/security | Referência GitHub confirmada; field test pendente | Referência GitHub confirmada; field test pendente |
| 01.02 | `docs/GLPI-PLUGIN-HISTORICAL-LEDGER.json` | `tests/glpi-historical-ledger.php` + fontes legadas | Contagem desconhecida | Não substitui baseline SIGAPS |
| 02.01–02.03 | `src/GlpiPluginSemanticModel.php`; hook/class indexes; `src/PipelineRunner.php` | `tests/glpi-plugin-semantic.php`, `glpi-hook-registry.php`, `glpi-check.php`, `glpi-class-references.php` | Padrões inspeccionados; field test pendente | Padrões inspeccionados; field test pendente |
| 03.01–03.03 | `src/ExternalConfigGenerator.php`, `src/GlpiConsumerConfigAdvisory.php` | `tests/glpi-consumer-config-coverage.php`, `tests/glpi-pr1-regressions.php` | Config própria a verificar | Config própria a verificar |
| 03.04–04.03 | Rulesets e adapters ainda a definir | Futuros testes TP/TN/unknown; relatórios sanitizados | Baseline requerida | Baseline requerida |
| 05.01–05.03 | `docs/GLPI-PLUGIN-CLOSURE.md`; PR #15 | CI do commit, ledger revisado, aprovações | Aceite próprio obrigatório | Aceite próprio obrigatório |

## 7. Registro e evolução das decisões

Para cada tranche, registrar no PR e nesta EAP: ID, dono/revisor, código alterado, SHA NINFA, SHAs dos consumidores, versão host, comando exato, hashes/configs, CI, resultados, pendências P0/P1, regressões e justificativas `DEFERRED`. Registrar `NOT_OBSERVED` por consumidor quando o padrão não existir e `INCONCLUSIVE` se ambiente/ferramenta impedir avaliação.

**Baseline histórica conhecida:** PR #1 documenta um `composer check` histórico e comentários P1 [3998260114](https://github.com/GeneralVini/ninfa/pull/1#discussion_r3998260114), [3998260120](https://github.com/GeneralVini/ninfa/pull/1#discussion_r3998260120) e [3998260123](https://github.com/GeneralVini/ninfa/pull/1#discussion_r3998260123). Regressões sintéticas cobrem parcialmente os riscos; P1-03 permanece parcialmente aberto quanto à execução das regras customizadas do consumidor. **Não encerrar essas origens sem evidência de escopo completo.**

## 8. Indicadores e gates

Registrar, para **cada** consumidor: quantidade total e classificada de issues históricas, baseline por operação, cobertura de regras, TP/FP/FN/UNKNOWN, achados não reproduzidos, falhas de ferramenta, regressões, P0/P1 em aberto, integridade do original e hash de evidência. Nunca usar denominador desconhecido como zero nem juntar SIGAPS+ÉGIDE numa única taxa sem valores separados.

**Gate formal GLPI-6 / DUAL-7:** fonte e histórico SIGAPS conciliados; revisão fixa ÉGIDE; ambos os field tests validados em isolamento; cobertura e limites documentados; CI íntegro; segurança triada; aprovação explícita. A passagem de um só consumidor, CI sintético verde ou desaparecimento de um finding **não** satisfaz o gate.

## 9. Próximas ações executáveis

1. Fixar SHA do ÉGIDE e verificar metadata de origem para SIGAPS; manter `main` móvel apenas para descoberta, não como baseline de regressão.
2. Reconciliar issues/relatórios históricos SIGAPS a partir de fontes rastreáveis, preservando todos os IDs; preparar evidência privada.
3. Completar EAP-03.01 com CI do head e testes negativos; avançar EAP-03.02 somente com aceitação explícita do modo opt-in.
4. Criar matrizes sintéticas dos hooks e classes observados nos dois consumidores; executar field tests apenas quando host/isolamento estiverem comprovados.
5. Revisar EAP em cada onda e impedir promoção de estado sem links verificáveis.

**Esta EAP é um contrato de execução, não autorização para modificar consumidores nem para realizar merge.**
