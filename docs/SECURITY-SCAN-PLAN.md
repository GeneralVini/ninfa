# Plano de segurança antes da execução — SEC-04.03

`SecurityScanPlan::build($catalog, $scope, $proposals)` transforma propostas explícitas dos adapters em um snapshot serializável, antes de resolver binários ou iniciar processos. Consome o [catálogo SEC-04.01](SECURITY-CAPABILITY-CATALOG.md) e a [identidade SEC-04.02](SECURITY-TEST-IDENTITY.md). Esta entrega não conecta DAST ao CLI público; fornece o planejador para as integrações seguintes.

## Entrada e controles

`scope` aceita `code`, `plugin` ou `web-full`. `plugin` exige profile `glpi-plugin` e conclui somente o escopo de código do plugin; DAST exige `web-full` explícito com host, deploy e rotas identificados. Cada proposta contém exatamente:

| Campo | Contrato |
| --- | --- |
| `capability` | ID presente no catálogo revisado. IDs desconhecidos são rejeitados. |
| `input` | Fatos completos aceitos por `SecurityTestIdentity::create()`. |
| `applicability_verified` | Booleano: adapter comprovou condições da capacidade no contexto. `false` bloqueia a unidade. |
| `selection_control` | `exact` ou `uncontrolled`. Sem comprovação da seleção, a unidade fica bloqueada e há aviso de sobreposição não controlada. |
| `limits_verified` | Booleano: adapter comprovou que o motor respeita escopo, redirects e limites aplicáveis. `false` bloqueia DAST. |

Todos os itens de um plano devem pertencer ao mesmo run, projeto e profile. Papéis, sessões, ativos e vetores podem variar e formam unidades distintas. O planejador não coleta fatos nem comprova os booleanos recebidos; os adapters deverão manter a evidência das afirmações, vinculada aos hashes efetivos da identidade. Estes campos não constituem autorização de execução por si mesmos.

Propostas de headers/cookies passivos selecionam regras ZAP; testes web genéricos ativos selecionam regras ZAP ativas; CVEs específicas selecionam templates Nuclei. Os proprietários vêm do catálogo e não podem ser substituídos pela entrada. A capacidade Nuclei de headers continua disponível para seleção explícita. Como seu bundle não tem equivalência integral comprovada com ZAP, solicitar ambos mantém ambos e produz aviso, sem alegar ausência de repetição.

Não existe seleção implícita de todo o catálogo nesta API. Os adapters futuros deverão gerar as propostas aplicáveis; o plano expõe `unrequested_capabilities` para tornar omissões visíveis. A lista não é uma declaração de não aplicabilidade.

## Decisões registradas

- **Selecionada:** capacidade aplicável, com seleção controlada e, para DAST, limites comprovados. A identidade e os controles ficam registrados na unidade.
- **Excluída por escopo:** DAST solicitado em `code` recebe `outside-code-scope`; em `plugin`, recebe `outside-plugin-code-scope`. Não é uma exclusão por equivalência.
- **Excluída por profile:** regra Semgrep fora dos configs de `SecurityContract::forProfile()` recebe `profile-not-applicable`, com profile, configs permitidos e config da regra como evidência.
- **Excluída por identidade:** a mesma unidade verificável solicitada novamente recebe `identical-verifiable-unit`, justificativa e `covered_by` apontando para a unidade selecionada. A execução anterior não é consultada; trata-se apenas de agendar uma vez no plano atual.
- **Bloqueada:** aplicabilidade desconhecida, seleção não controlada ou limites DAST não comprovados. O motivo aparece em `blocked`; a fonte não desaparece do plano.

Identidades incompletas não são excluídas por duplicação e não compartilham grupos. Recebem aviso `identity-unverifiable`; isso impede interpretar dados ausentes como prova de igualdade. A falta de snapshot de deploy, por exemplo, impede reutilização, mas não é por si só uma proibição de observar um alvo autorizado quando escopo/limites/aplicabilidade foram comprovados.

O catálogo v1 não aprova equivalência entre motores: `cross_engine_equivalence_exclusions` permanece vazio. Relações candidatas selecionadas geram `uncontrolled-overlap`; complementares geram `complementary-overlap`. Esses avisos são conservadores e podem indicar sobreposição potencial entre unidades com ativos distintos; nunca justificam supressão. O template Nuclei de headers mantém seus 12 matchers como uma seleção indivisível.

## Agrupamento e ordem

Unidades selecionadas podem compartilhar uma execução somente quando proprietário, fase, contexto, ativo, requisição e bindings efetivos coincidem, exceto o fingerprint da regra específica. A seleção exata de cada unidade permanece no grupo. Regras/templates e hashes distintos continuam representados nas identidades e no `execution_id` final.

Psalm recebe `selection_mode: whole-program`: vários tipos de issue compartilham a análise do programa. O plano inclui `indivisible-engine-analysis`, pois o motor pode analisar tipos não enumerados no catálogo. Seus seletores são projeções de cobertura, não filtros independentes de execução. Os demais grupos recebem `exact-selectors`, cuja tradução para configuração/comando cabe ao adapter.

O plano ordena SCA Composer, SCA OSV, SAST Semgrep, SAST Psalm, ZAP passivo, ZAP ativo e Nuclei HTTP. Isso não representa descoberta já concluída ou dependências satisfeitas: os adapters ZAP ainda deverão observar o escopo, aguardar jobs e comprovar cobertura. Requisições internas de um teste não são eliminadas por este agrupamento.

`execution_id` é calculado sobre o grupo e sua seleção ordenada. `plan_id` inclui o snapshot completo, inclusive run e decisões. Mapas são ordenados; listas de grupos/unidades/decisões são estabilizadas. Reordenar propostas verificáveis equivalentes mantém o plano. Propostas incompletas contêm nonces e produzem novos IDs por segurança.

## Estado das fontes e cobertura

`code` e `plugin` exigem seleção das quatro fontes Composer Audit, OSV, Semgrep e Psalm. `web-full` exige as seis, acrescentando ZAP e Nuclei. Cada fonte aparece como `missing`, `blocked` ou `selected`. Fonte ausente gera `required-source-missing`; ausência não equivale a não aplicabilidade. Mesmo com uma unidade selecionada, outras unidades bloqueadas continuam no plano e impedem `source_selection_complete`.

`source_selection_complete: true` significa somente que todas as fontes exigidas têm unidades selecionadas e não há bloqueios. Não prova seleção de todas as capacidades aplicáveis, baseline completo, execução ou cobertura. `unrequested_capabilities`, exclusões e avisos precisam ser avaliados com a política. O estado de execução é sempre `not-started`; a validade de cobertura permanece para o ledger SEC-04.04 e integrações seguintes.

## Validação e continuação

Responsável técnico: Codex. Início: `2026-10-24T13:27:07-03:00`. Fim e evidências na [EAP](EAP-SECURITY-ORCHESTRATION.md) e no [registro de validação](evidence/sec04-03-validation-2026-10-24.json). O [exemplo de plano](evidence/sec04-03-example-plan.json) usa somente fatos sintéticos; nenhum alvo foi acessado.

`tests/security-scan-plan.php`, integrado ao `profile-test`, verifica agrupamento Psalm/Semgrep, duplicação exata, separação por papel/vetor, identidade incompleta, bundle Nuclei, sobreposição entre motores, escopo/profile, controles ausentes, fontes obrigatórias e estabilidade da ordenação. O plano de exemplo seleciona sete unidades nas seis fontes; registra explicitamente as 45 capacidades não solicitadas, sem afirmar cobertura completa.

A [SEC-04.04](SECURITY-EXECUTION-LEDGER.md) implementa ledger, trava, concorrência, retomada e retries, com fallback bloqueado na ausência de equivalência aprovada. A integração dos adapters deverá persistir o plano antes de iniciar motores, vincular resultados aos IDs e registrar qualquer alteração de escopo/seleção como novo snapshot. Esta classe não grava resultados, monta comandos, resolve credenciais nem executa scans.

Revisão cruzada: [SEC-04 x SEC-01/02/03](REVISAO-SEC-04-2026-10-24.md). O suporte explícito a `plugin` foi alinhado ao escopo SEC-01 nesta revisão. Não aplicabilidade de fonte por ausência de PHP/inventário ainda exige política/evidência futura na integração; a API atual é conservadora e mantém fonte sem proposta como `missing`.
