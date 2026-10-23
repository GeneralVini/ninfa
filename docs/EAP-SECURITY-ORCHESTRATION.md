# EAP — Orquestração de segurança do NINFA

## Controle da entrega

| Campo | Valor |
| --- | --- |
| Objetivo | Integrar ZAP e Nuclei, preservar Semgrep e coordenar as fontes de segurança com controle de repetição e relatório próprio em Markdown e PDF. |
| Baseline inspecionada | `main@3f7ece2f049038076128f837a82804869ca2eaa2` |
| Registro iniciado em | `2026-10-22T21:36:00-03:00` |
| Fuso dos registros | `America/Sao_Paulo`; datetime ISO 8601 com deslocamento UTC. |
| Escopo desta entrega documental | EAP e critérios de aceite; implementação futura permanece pendente. |
| Instalação prevista | Nativa, sem contêineres; ferramentas e artefatos fora do projeto analisado. |
| Responsável pela coordenação técnica | NINFA; responsável humano por tarefa a atribuir quando ela for iniciada. |
| Prazo de implementação | Ainda não estimado; depende do detalhamento das tarefas e da disponibilidade de execução. |

### Como atualizar

- `[ ]` identifica uma entrega pendente, em andamento, bloqueada ou em revisão; `[x]` identifica somente entrega validada com evidência.
- Estados: `TODO`, `IN_PROGRESS`, `BLOCKED`, `IN_REVIEW` e `VALIDATED`.
- **Início** é o datetime real em que o trabalho da tarefa começou. **Fim da entrega** é o datetime real da validação do seu critério de aceite, não uma previsão.
- `—` significa que o evento ainda não ocorreu ou não foi registrado. Não preencher retroativamente por estimativa.
- Ao começar, registrar início e responsável. Ao concluir, registrar fim, marcar `[x]`, mudar para `VALIDATED` e acrescentar evidência verificável, como arquivo, teste, artefato ou commit.
- Em caso de bloqueio, manter o checkbox aberto, registrar motivo e dependência. Ao reabrir uma entrega, preservar o histórico anterior em uma nota datada.
- Esta EAP registra apenas as entregas deste ciclo. Capacidades preexistentes são baseline; não recebem datas de implementação inventadas.

## Requisitos e limites de aceite

1. **ZAP e Nuclei são módulos obrigatórios da auditoria web completa.** Ausência, falha ou perda de cobertura impede declarar execução completa. Em projetos sem superfície web, `NOT_APPLICABLE` exige motivo explícito; não se inventa uma URL.
2. **Semgrep permanece no fluxo.** Composer Audit, OSV e Psalm Taint existentes são preservados e revisados dentro do contrato comum.
3. **NINFA coordena todo o fluxo:** contexto, ferramentas, configurações, descoberta, autenticação, plano, limites, execução, retomada, evidências e relatórios.
4. **Não repetir testes equivalentes agendados pelo NINFA.** Cada unidade registrada tem proprietário e contexto de equivalência. A garantia não abrange requisições internas inevitáveis dos motores nem sobreposições sem mapeamento demonstrado. Essas limitações devem aparecer no relatório.
5. **Markdown e PDF derivam do mesmo modelo de relatório.** Não interpretar a saída de terminal como fonte primária quando houver formato estruturado.
6. **Sem alterações no código/configuração do projeto consumidor durante a auditoria.** Varredura web pode alterar o estado da aplicação: seu perfil, autenticação, limites e escopo precisam ser definidos antes da execução.
7. **Achado, erro operacional, cobertura e decisão de bloqueio são dimensões distintas.** Ausência de achados não prova ausência de vulnerabilidades; correspondência de template não prova exploração.

## Baseline verificada

| Capacidade | Situação observada no main |
| --- | --- |
| Fluxo de segurança | `composer-audit`, `osv`, `psalm-taint` e `semgrep` no `PipelinePlan`. |
| Contratos | `Finding`, `ToolResult`, `RunResult` e cobertura estruturada existentes. |
| Relatório | `security-report.json`, schema 3, com SCA/SAST. |
| Deduplicação | Aliases de advisories SCA; não equivale ao controle de execução entre motores. |
| ZAP | Script manual legado; não integrado ao fluxo público de segurança. |
| Nuclei | Integração ausente. |
| Markdown/PDF próprios | Entrega ainda pendente. |
| Workspace | Externo ao consumidor; preservar esse contrato. |

Evidências principais: [`PipelinePlan.php`](../src/PipelinePlan.php), [`PipelineRunner.php`](../src/PipelineRunner.php), [`SecurityReport.php`](../src/SecurityReport.php), [`ScaFindingDeduplicator.php`](../src/ScaFindingDeduplicator.php), [`SecurityInventory.php`](../src/SecurityInventory.php) e [`zap-scan.sh`](../scripts/zap-scan.sh).

## Pacotes de trabalho

### SEC-00 — Planejamento e baseline

- [x] **SEC-00.01 — Registrar a baseline após a mudança de main.**
  Estado: `VALIDATED` · Início: `2026-10-22T21:36:00-03:00` · Fim da entrega: `2026-10-22T21:36:00-03:00` · Dependências: nenhuma.
  Aceite/evidência: hash acima conferido com `git rev-parse HEAD`, árvore limpa e contratos existentes registrados nesta EAP. O horário registra esta conferência; não a implementação histórica das capacidades.

- [x] **SEC-00.02 — Disponibilizar a EAP navegável no checkout local.**
  Estado: `VALIDATED` · Início: `2026-10-22T21:36:00-03:00` · Fim da entrega: `2026-10-22T21:37:31-03:00` · Dependências: SEC-00.01.
  Aceite/evidência: este arquivo e links em `README.md`/`README.en.md`; conferência das 37 tarefas, IDs únicos, metadados, datetimes, dependências sem ciclos e links locais; `git diff --check` sem erros. Arquivos locais, sem commit ou publicação remota nesta entrega.

### SEC-01 — Contratos de execução e escopo

- [ ] **SEC-01.01 — Formalizar modos e obrigatoriedade dos módulos.**
  Estado: `TODO` · Início: — · Fim da entrega: — · Dependências: SEC-00.02.
  Aceite: definir auditoria web completa e demais escopos; ZAP/Nuclei obrigatórios no primeiro, Semgrep preservado e indisponibilidade/dispensa sem sucesso completo indevido.

- [ ] **SEC-01.02 — Definir contexto único da auditoria.**
  Estado: `TODO` · Início: — · Fim da entrega: — · Dependências: SEC-01.01.
  Aceite: contrato para caminho do projeto, revisão, URL implantada, vínculo código/deploy declarado, vhost, base path, perfil, escopo permitido/excluído, papel autenticado e identidade da execução; distinguir plugin e aplicação hospedeira.

- [ ] **SEC-01.03 — Definir perfis e limites operacionais.**
  Estado: `TODO` · Início: — · Fim da entrega: — · Dependências: SEC-01.02.
  Aceite: perfis de descoberta/passivo/ativo, taxa, concorrência, timeout, orçamento, redirecionamento e política de alteração de estado explícitos, sem ampliar o escopo silenciosamente.

- [ ] **SEC-01.04 — Projetar contratos de resultado e evolução do schema.**
  Estado: `TODO` · Início: — · Fim da entrega: — · Dependências: SEC-01.02.
  Aceite: separar operação, cobertura, achados e gate; definir DAST, compatibilidade com schema 3, IDs estáveis e campos desconhecidos sem inferência indevida.

### SEC-02 — Ferramentas nativas e reprodutibilidade

- [ ] **SEC-02.01 — Preparar instalação e resolução do ZAP.**
  Estado: `TODO` · Início: — · Fim da entrega: — · Dependências: SEC-01.01, SEC-01.03.
  Aceite: versão, Java, add-ons e navegador quando necessário documentados e verificáveis; instalação externa ao consumidor; substituir ou retirar a orientação legada de instalação que hoje não instala ZAP.

- [ ] **SEC-02.02 — Preparar instalação e resolução do Nuclei.**
  Estado: `TODO` · Início: — · Fim da entrega: — · Dependências: SEC-01.01, SEC-01.03.
  Aceite: binário e templates com versão/hash registrados; atualização controlada, seleção de templates e política de execução documentadas; sem atualização silenciosa no meio da auditoria.

- [ ] **SEC-02.03 — Unificar diagnóstico de pré-requisitos.**
  Estado: `TODO` · Início: — · Fim da entrega: — · Dependências: SEC-02.01, SEC-02.02.
  Aceite: preflight de todas as fontes previstas, inclusive Composer, OSV, Semgrep e Psalm; incompatibilidade e indisponibilidade com causa, versão e efeito sobre cobertura/gate.

### SEC-03 — Avaliação e normalização das saídas

- [ ] **SEC-03.01 — Documentar o contrato real de saída de cada ferramenta.**
  Estado: `TODO` · Início: — · Fim da entrega: — · Dependências: SEC-01.04, SEC-02.03.
  Aceite: matriz da próxima seção confirmada nas versões selecionadas, com referências oficiais, exemplos sanitizados, códigos de saída, limites e informação de cobertura disponível.

- [ ] **SEC-03.02 — Revisar adaptadores Composer Audit e OSV.**
  Estado: `TODO` · Início: — · Fim da entrega: — · Dependências: SEC-03.01.
  Aceite: preservar aliases, versões, fontes e advisories; separar pacote abandonado da política de vulnerabilidade; explicitar cobertura sem lockfile, sem apresentar inventário reconstruído como lockfile original.

- [ ] **SEC-03.03 — Revisar adaptadores Semgrep e Psalm Taint.**
  Estado: `TODO` · Início: — · Fim da entrega: — · Dependências: SEC-03.01.
  Aceite: preservar regra, arquivo, posição, fluxo disponível, erros e cobertura; Semgrep mantido; severidade de regra, confiança e confirmação da vulnerabilidade tratadas separadamente.

- [ ] **SEC-03.04 — Normalizar evidências ZAP e Nuclei.**
  Estado: `TODO` · Início: — · Fim da entrega: — · Dependências: SEC-03.01.
  Aceite: contrato e fixtures para alertas ZAP e eventos JSONL Nuclei; instâncias, regra/template, alvo, método, parâmetro quando disponível, proveniência e erros preservados sem converter match em exploração confirmada.

- [ ] **SEC-03.05 — Definir proteção e retenção das evidências.**
  Estado: `TODO` · Início: — · Fim da entrega: — · Dependências: SEC-03.01.
  Aceite: sanitização de cookies, tokens, credenciais e dados pessoais; permissões e retenção dos artefatos brutos, hashes e referências auditáveis; logs/relatórios sem vazamento em fixtures de verificação.

### SEC-04 — Planejador e controle de repetição

- [ ] **SEC-04.01 — Criar catálogo versionado de capacidades e equivalências.**
  Estado: `TODO` · Início: — · Fim da entrega: — · Dependências: SEC-03.01.
  Aceite: objetivo, proprietário, alternativa, condições e regras/templates exatos por capacidade; CWE ou coincidência de URL/arquivo isoladamente não define equivalência.

- [ ] **SEC-04.02 — Definir identidade de cada unidade de teste.**
  Estado: `TODO` · Início: — · Fim da entrega: — · Dependências: SEC-04.01, SEC-01.02.
  Aceite: chave contempla ativo/contexto, método/parâmetro/papel quando aplicáveis, técnica/família de payload, condições e versões/hashes relevantes; limites de granularidade de cada motor registrados.

- [ ] **SEC-04.03 — Gerar plano antes de executar os motores.**
  Estado: `TODO` · Início: — · Fim da entrega: — · Dependências: SEC-04.02.
  Aceite: seleção de regras e templates por proprietário, exclusões equivalentes com justificativa e aviso de sobreposição não controlável. Inicialmente, avaliar headers/cookies no ZAP passivo, CVEs específicas no Nuclei e testes web genéricos no ZAP ativo.

- [ ] **SEC-04.04 — Controlar execução, retomada, retries e fallback.**
  Estado: `TODO` · Início: — · Fim da entrega: — · Dependências: SEC-04.03.
  Aceite: ledger e trava evitam agendamento concorrente/repetido no mesmo contexto; retries explícitos; alternativa só após falta de cobertura válida; retomada invalida resultados quando contexto muda ou não é verificável.

- [ ] **SEC-04.05 — Provar o contrato de não repetição.**
  Estado: `TODO` · Início: — · Fim da entrega: — · Dependências: SEC-04.04.
  Aceite: testes de equivalência, contextos distintos, concorrência, fallback, retomada e sobreposição complementar; relatório explica o que foi suprimido. Não deduplicar cegamente requisições HTTP internas necessárias aos testes.

### SEC-05 — Adaptação DAST

- [ ] **SEC-05.01 — Integrar descoberta e passivo do ZAP.**
  Estado: `TODO` · Início: — · Fim da entrega: — · Dependências: SEC-02.01, SEC-03.04, SEC-03.05, SEC-04.03.
  Aceite: execução gerenciada pelo NINFA, URLs descobertas dentro do escopo, espera de término dos jobs/passivo e evidência de páginas/rotas observadas; quantidade de alertas não usada como prova de crawl completo.

- [ ] **SEC-05.02 — Integrar autenticação e sessões verificáveis.**
  Estado: `TODO` · Início: — · Fim da entrega: — · Dependências: SEC-05.01.
  Aceite: suportar os mecanismos definidos no escopo inicial, verificar login/logout e expiração por papel; sessão inválida produz perda de cobertura explícita e não equivale a auditoria autenticada concluída.

- [ ] **SEC-05.03 — Integrar ZAP ativo conforme o plano.**
  Estado: `TODO` · Início: — · Fim da entrega: — · Dependências: SEC-05.02, SEC-04.04.
  Aceite: regras permitidas, alvos e parâmetros aplicáveis respeitados; limites e cancelamento efetivos; testes capazes de alterar estado seguem o perfil definido e não concorrem indevidamente com outra etapa.

- [ ] **SEC-05.04 — Integrar Nuclei conforme o plano.**
  Estado: `TODO` · Início: — · Fim da entrega: — · Dependências: SEC-02.02, SEC-03.04, SEC-03.05, SEC-04.04, SEC-05.01.
  Aceite: executar apenas seleção aprovada pelo planejador sobre alvos elegíveis; propagar autenticação quando suportada e prevista, respeitar limites/escopo e armazenar JSONL, diagnósticos e estatísticas separadamente.

### SEC-06 — Orquestração central

- [ ] **SEC-06.01 — Coordenar dependências e estados de todas as etapas.**
  Estado: `TODO` · Início: — · Fim da entrega: — · Dependências: SEC-03.02, SEC-03.03, SEC-04.05, SEC-05.03, SEC-05.04.
  Aceite: NINFA inicia e encerra ferramentas, transmite contexto/artefatos e respeita dependências; falha não impede etapas independentes, mas impede declarar cobertura completa quando obrigatória.

- [ ] **SEC-06.02 — Aplicar políticas de conclusão e bloqueio.**
  Estado: `TODO` · Início: — · Fim da entrega: — · Dependências: SEC-06.01.
  Aceite: matriz testada de sucesso operacional, finding, parcial, erro, indisponível e não aplicável; códigos finais e resumo explicam a decisão sem confundir vulnerabilidade com falha do scanner.

- [ ] **SEC-06.03 — Consolidar manifesto reprodutível da auditoria.**
  Estado: `TODO` · Início: — · Fim da entrega: — · Dependências: SEC-06.01.
  Aceite: manifesto externo ao consumidor com identidade do contexto, versões, configurações sanitizadas/hashes, plano, estados, tempos, evidências e limitações; encerramento seguro de subprocessos e artefatos parciais após cancelamento.

### SEC-07 — Correlação e tratamento dos achados

- [ ] **SEC-07.01 — Consolidar achados preservando proveniência.**
  Estado: `TODO` · Início: — · Fim da entrega: — · Dependências: SEC-06.01.
  Aceite: agrupamento canônico distinto de supressão de testes; preservar múltiplas evidências e fontes; Semgrep e Psalm complementares não são descartados só por apontarem o mesmo trecho.

- [ ] **SEC-07.02 — Definir remediação, triagem e reteste.**
  Estado: `TODO` · Início: — · Fim da entrega: — · Dependências: SEC-07.01.
  Aceite: cada achado inclui ação contextualizada, referência, responsável de correção quando atribuído e procedimento de reteste; triagem preserva histórico e não inventa confiança ou explorabilidade.

- [ ] **SEC-07.03 — Comparar execuções compatíveis.**
  Estado: `TODO` · Início: — · Fim da entrega: — · Dependências: SEC-07.01, SEC-06.03.
  Aceite: distinguir novo, persistente, não observado e resolvido por reteste; falta de cobertura, mudança de papel, regra ou escopo não encerra achado automaticamente.

### SEC-08 — Relatórios próprios em Markdown e PDF

- [ ] **SEC-08.01 — Definir modelo canônico e estrutura editorial.**
  Estado: `TODO` · Início: — · Fim da entrega: — · Dependências: SEC-07.02, SEC-06.02, SEC-06.03.
  Aceite: modelo único contém sumário, escopo, cobertura, achados, ações, reteste, versões, limitações e registro de exclusões/equivalências; contagens distinguem achados canônicos de instâncias.

- [ ] **SEC-08.02 — Gerar Markdown a partir do modelo.**
  Estado: `TODO` · Início: — · Fim da entrega: — · Dependências: SEC-08.01.
  Aceite: relatório legível e navegável, evidências sanitizadas, identificadores estáveis e links portáveis; execução parcial continua identificada como parcial.

- [ ] **SEC-08.03 — Validar renderizador e gerar PDF local.**
  Estado: `TODO` · Início: — · Fim da entrega: — · Dependências: SEC-08.01, SEC-03.05.
  Aceite: avaliar WeasyPrint como primeira opção nativa; validar dependências, paginação, fontes e conteúdo extenso; HTML controlado com saída externa escapada e recursos restritos, sem carregamento arbitrário de arquivos/URLs pelo conteúdo dos scanners.

- [ ] **SEC-08.04 — Verificar paridade e falhas de geração.**
  Estado: `TODO` · Início: — · Fim da entrega: — · Dependências: SEC-08.02, SEC-08.03.
  Aceite: MD/PDF com mesmo contexto, IDs, achados, estados e contagens; falha do PDF registrada como entrega de relatório incompleta, preservando JSON/MD disponíveis.

### SEC-09 — Validação, homologação e encerramento

- [ ] **SEC-09.01 — Validar contratos com fixtures e motores simulados.**
  Estado: `TODO` · Início: — · Fim da entrega: — · Dependências: SEC-06.02, SEC-07.03, SEC-08.04.
  Aceite: casos positivos, negativos, desconhecidos, saída inválida, timeout, sessão expirada, erro, parcial, cancelamento e segredos; verificações do controle de repetição e obrigatoriedade incluídas.

- [ ] **SEC-09.02 — Executar regressão e instalação nativa limpa.**
  Estado: `TODO` · Início: — · Fim da entrega: — · Dependências: SEC-09.01.
  Aceite: checks apropriados do Ninfa e perfis existentes preservados; instalação reproduzida sem contêineres e sem alteração do consumidor; registrar comandos, versões, resultados e limitações reais.

- [ ] **SEC-09.03 — Homologar em aplicações locais no escopo autorizado.**
  Estado: `TODO` · Início: — · Fim da entrega: — · Dependências: SEC-09.02.
  Aceite: definir aplicação, URL, ambiente, papel e perfil antes de testar; usar `/var/www/psglpi` e `/var/www/hecate` apenas quando confirmados nesse contexto; conferir achados conhecidos, cobertura e MD/PDF com evidências de execução real. Esta EAP não inicia varreduras.

- [ ] **SEC-09.04 — Entregar documentação operacional e encerrar o ciclo.**
  Estado: `TODO` · Início: — · Fim da entrega: — · Dependências: SEC-09.03.
  Aceite: instalação, configuração, execução, retomada, triagem e leitura dos relatórios documentadas; pendências e limites declarados; todas as entregas obrigatórias validadas com evidência. Publicação de release segue a autorização aplicável quando solicitada.

## Matriz inicial de saídas a avaliar

Esta matriz orienta SEC-03.01; não substitui validação nas versões que forem selecionadas.

| Fonte | Entrada primária para o NINFA | Preservar e conferir | Limite de interpretação |
| --- | --- | --- | --- |
| Composer Audit | JSON de advisories e dados de inventário; stderr/exit separados | Pacote, versão instalada, advisory, aliases, referências, faixas afetadas e abandoned como política própria | Advisory de dependência não demonstra alcançabilidade/exploração; sem lockfile a cobertura precisa ser explícita. |
| OSV | Resposta estruturada da consulta ao inventário | Identidade e versão do pacote, aliases, faixas e referências, falhas/parcialidade | Complementa Composer; agrupar o mesmo advisory não elimina a proveniência de cada fonte. |
| Semgrep | JSON | `check_id`, caminho/posição, mensagem, metadata, fluxo quando disponível, erros e arquivos observados/excluídos | Severidade da regra não equivale automaticamente à gravidade/explorabilidade da vulnerabilidade. |
| Psalm Taint | JSON de issues | Tipo, localização, trecho e `taint_trace` disponível; diagnóstico e cobertura | Fluxo estático não confirma exploração em runtime; registrar limitações do modelo. |
| ZAP | JSON de alertas/relatório e resultados dos jobs/API | Plugin/regra, risco, confiança, URL, método/parâmetro quando disponíveis, evidência e instâncias; crawl, sessão e filas | Alertas isolados não comprovam que descoberta, autenticação e passivo/ativo terminaram. |
| Nuclei | JSONL de matches; logs/estatísticas separados | Template/versão/hash, matcher, alvo, classificação, referências e extrações sanitizadas disponíveis | Zero matches não comprova execução completa; assinatura de template não comprova aplicabilidade nem exploração. |

Saídas brutas ficam como evidência protegida. O relatório próprio usa o modelo normalizado e registra omissões, campos indisponíveis e erros de parsing.

## Estrutura proposta do relatório

1. Identificação da auditoria, horários reais, revisão do código e vínculo com o deploy.
2. Sumário executivo com prioridades e conclusão qualificada pela cobertura.
3. Escopo, exclusões, papéis, perfis e limites operacionais.
4. Cobertura por ferramenta: versão, estado, duração, alvos e limitações observadas.
5. Achados canônicos: ID, gravidade e origem, confiança, ativo, evidência sanitizada e proveniência.
6. Ação para sanar cada achado, prioridade e procedimento/resultado de reteste.
7. Testes selecionados, equivalências suprimidas, fallbacks, retries e limites do controle de repetição.
8. Falhas operacionais, pendências e diferenças em relação à execução anterior comparável.
9. Anexos: manifesto, regras/templates/configurações por versão/hash e referências aos artefatos protegidos.

## Sequência de entrega e marcos

As dependências por tarefa governam a execução; a tabela agrupa os pontos de revisão, sem estimar datas futuras.

| Marco | Entrega verificável | Condição para fechamento |
| --- | --- | --- |
| M0 | EAP e baseline | SEC-00 validado. |
| M1 | Contratos, instalação e avaliação das saídas | SEC-01, SEC-02 e SEC-03 validados. |
| M2 | Planejamento e controle de repetição | SEC-04 validado. |
| M3 | ZAP/Nuclei coordenados no fluxo completo | SEC-05 e SEC-06 validados. |
| M4 | Correlação e relatórios MD/PDF | SEC-07 e SEC-08 validados. |
| M5 | Homologação e entrega operacional | SEC-09 validado; evidências vinculadas e pendências declaradas. |

Concluir M0 não significa que os módulos ou relatórios estejam implementados. Datas de início e fim das próximas entregas serão registradas à medida que o trabalho ocorrer.
