# Inventário de fontes — SIGAPS GLPI / DUAL-0

**Fonte técnica de código autorizada:** repositório privado [GeneralVini/sigaps_glpi](https://github.com/GeneralVini/sigaps_glpi), revisão `d039aec352b45a89117fa7bf526562bc1a4645bc`. **Segundo consumidor:** [GeneralVini/egide](https://github.com/GeneralVini/egide), revisão `0c9d43105cee533537dccc8b3f5bddbf7adca81c`. Consultas nesta etapa foram **somente leitura**. Não reproduzir dados sensíveis dos consumidores no NINFA público.

## A. Catálogo de origens verificadas

| ID de fonte | Origem | Resultado documental | Alcance / restrição |
| --- | --- | --- | --- |
| S-GH-ISSUE-1 | [SIGAPS PR/issue #1](https://github.com/GeneralVini/sigaps_glpi/pull/1) | Fechado em 2026-07-30; descreve scaffold GLPI, lint, testes, PHPStan e verificação CLI histórica | É registro de validação **na época**, não validação atual do NINFA nem incidente comprovado |
| S-GH-ISSUE-2 | [SIGAPS PR/issue #2](https://github.com/GeneralVini/sigaps_glpi/pull/2) | Fechado em 2026-05-26; descrição sumária relativa a dashboards | Não inferir causa, severidade ou cobertura técnica |
| S-DOC-EAP | [SIGAPS docs/EAP.md](https://github.com/GeneralVini/sigaps_glpi/blob/main/docs/EAP.md) | EAP principal, estados, requisitos e decisões funcionais | Não converter pendência funcional automaticamente em finding NINFA |
| S-DOC-TRAIL | [SIGAPS docs/EAP_TRILHA.md](https://github.com/GeneralVini/sigaps_glpi/blob/main/docs/EAP_TRILHA.md) | Diário histórico append-only de validações, decisões e pendências, com marcações de revisões ainda não homologadas | Leitura parcial na recuperação inicial; exige reconciliação integral |
| S-CHAT-MANIFEST | [SIGAPS docs/chats/README.md](https://github.com/GeneralVini/sigaps_glpi/blob/main/docs/chats/README.md) | Documenta backups versionados de sessões Codex e exclusão de credenciais | Não considerar as sessões automaticamente processadas |
| S-CHAT-SESSIONS | `docs/chats/codex/sessions/2026/05/14` | Três arquivos de sessões Codex existentes na árvore da revisão fixada | Conteúdo ainda sem inventário integral; pode conter dados privados; não reproduzir |
| S-CHAT-ARCHIVE | `scripts/backups/codex-chats/` | Arquivo de backup compactado fracionado (quatro partes) presente na árvore | Não acessado/descompactado; não declarar completude |
| N-PR1 | [NINFA PR #1](https://github.com/GeneralVini/ninfa/pull/1) | Validação histórica `composer check` e três revisões P1 infra | Preservado em [ledger](GLPI-PLUGIN-HISTORICAL-LEDGER.json), distinto de issues do consumidor |

A consulta REST de `issues?state=all&per_page=100` no repositório SIGAPS retornou duas entradas, ambas correspondentes a PRs. **Isso não é prova de zero issues históricas**: avaliações de chats, relatórios e tickets podem não constar de GitHub Issues e podem existir mais páginas/fora do repo. Conserva-se `count=null` no ledger para a fila ainda não conciliada.

## B. Distinção entre requisitos do consumidor e defeitos do analisador

A EAP funcional SIGAPS e sua trilha documentam atividades de autorização, workflow, migração e homologação; esses registros são importantes para direcionar verificações GLPI, mas **não são automaticamente vulnerabilidades ou falsos positivos**. Criar um `case_id` NINFA somente quando uma evidência de execução/revisão identificar ferramenta, regra, local, resultado e decisão; guardar detalhes privados fora do PR público.

## C. Pendências concretas

- [ ] Inspecionar a origem do checkout local `/var/www/psglpi/plugins/sigaps` e confrontar remote + SHA com `GeneralVini/sigaps_glpi`; host e condições de permissão read-only não estão disponíveis nesta sessão.
- [ ] Inspecionar e catalogar integralmente `EAP_TRILHA.md`, `EAP.md`, arquivos de sessões e relatórios legados, incluindo material do projeto que não esteja presente no GitHub; registrar fonte/revisão/hash e resultado por ocorrência **em ledger privado**.
- [ ] Verificar se há issues/artefatos externos e comentários de PR relevantes, sem misturar PR fechado com achado SAST.
- [ ] Executar os modos NINFA somente em cópia do plugin, isolada do original e do host, após autorização técnica de filesystem.
- [ ] Reconciliar histórico das outras conversas do Projeto, não disponível integralmente por busca GitHub; confirmar fechamento das filas com evidência, nunca por ausência em novo scan.

**Estado auditável:** `EAP-01.01=IN_PROGRESS` (fontes GitHub fixadas; identidade de instalação/host não comprovada); `EAP-01.02=BLOCKED` para encerramento (fontes novas catalogadas, reconciliação não exaustiva). Nenhuma issue histórica foi apagada, reclassificada como bug do consumidor ou encerrada por presunção.
