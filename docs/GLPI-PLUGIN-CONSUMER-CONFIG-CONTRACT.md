# GLPI plugin — contrato de cobertura das configurações do consumidor (DUAL-P1-03)

> Estado: proposta verificável, **não implementada**. Documento de engenharia para o PR #15; não altera nem executa SIGAPS/ÉGIDE. O profile `yii2` está fora do escopo.

## Problema confirmado

O NINFA gera configurações de analisadores em workspace externo. A regressão `tests/glpi-consumer-config-coverage.php` comprova que `check` entrega essas configurações a PHPStan, Psalm, ECS e Rector, **não** as configurações locais do plugin; preservação de hashes e aviso `consumer-config-advisory.json` não demonstram execução das regras locais. O P1 original [3998260123](https://github.com/GeneralVini/ninfa/pull/1#discussion_r3998260123) deve continuar **PARTIAL/OPEN**, sem inferir bug confirmado no SIGAPS.

## Consumidores independentes

| Trilha | Evidência confirmada | Evidência ainda necessária |
| --- | --- | --- |
| SIGAPS GLPI | Caminho histórico `/var/www/psglpi/plugins/sigaps` e PR #1 com validação histórica `composer check` | Identificar o checkout real; recuperar integralmente issues, relatórios e configurações atuais. **Quantidade desconhecida, não zero.** |
| ÉGIDE GLPI | `GeneralVini/egide` privado, `main`: `composer.json` declara `type: glpi-plugin`, PSR-4 `GlpiPlugin\\Egide\\`, PHPStan e `glpi-project/phpstan-glpi` | Fixar SHA da revisão auditada; detectar configs realmente presentes, host GLPI 11 e checkout de campo. A dependência no Composer **não prova** extensão instalada. |

Não publicar código nem configurações proprietárias de nenhum dos consumidores no NINFA público. Não equiparar `GeneralVini/sigaps` (possível Yii2) ao plugin local sem prova.

## Contrato proposto: modo explícito, sem mescla implícita

1. **Padrão `generated-only` (comportamento atual):** utilizar apenas configs geradas fora do consumidor; registrar `detected`, `applied=false`, caminho do config efetivamente passado a cada scanner, reason e advertência observável. Não chamar isso de cobertura completa.
2. **Modo opt-in futuro `consumer-aware`:** habilitação explícita por ferramenta e por execução; nenhuma ativação silenciosa. Preservar os configs originais, isolamento externo de temporários, reports e caches, e o contrato de host GLPI 11. Não importar um arquivo de configuração PHP como dado inerte: Rector/ECS podem executar PHP ao carregar config; a aceitação exige sandbox de execução e consentimento específicos.
3. **Falha fechada:** se não for possível provar precedência, resolver caminhos/includes relativos, isolar execução ou verificar versão/extensão, retornar `INCONCLUSIVE/UNSUPPORTED`, nunca `PASSED` nem scan limpo fictício. Configs do consumidor nunca são reescritas; `--force` não altera essa regra.
4. **Matriz por ferramenta:** PHPStan — `includes`, `parameters`, extension schema, caminhos e nível; Psalm — XML, plugins, baseline e paths; ECS/Rector — configuração PHP executável e side effects. PHPUnit fica fora da composição automática até contrato próprio. Registrar argv efetivo, versões e digest das configs aplicadas sem divulgar conteúdo privado.
5. **Escopo semântico:** regras NINFA `COR-001..003` e seus tri-states permanecem independentes. `unknown` não vira `false`; findings SAST não são classificados automaticamente como vulnerabilidades.

## Critérios de aceite, antes de implementação

- Fixtures sintéticas positivas/negativas para ausência/presença de extensão `phpstan-glpi`; troca de host na **mesma raiz**; caminhos relativos; configurações locais conflitantes e `--force`.
- Fixture com regra personalizada sentinela que **altera observavelmente o resultado do analyzer** quando opt-in está habilitado, e não altera quando desligado; inspecionar argv, config carregado e relatório. Testar também falhas de carregamento, precedência e scanner ausente.
- Registrar em artefato externo `mode`, `detected`, `applied`, `unsupported_reason`, `analyzer_version`, `effective_config_digest`, `host_revision` e resultado por ferramenta; nunca afirmar `applied=true` apenas porque o arquivo existe.
- Demonstrar isolamento de escrita antes/depois: original e host efetivamente read-only para a identidade de execução; hash posterior sozinho é insuficiente. Não rodar `ninfa fix`, migrations, instaladores ou testes que escrevam em consumidores originais.
- Homologar **separadamente** SIGAPS e ÉGIDE em cópias isoladas, com baselines assinadas por commit/host, inventário de TP/FP/FN/UNKNOWN/NOT_REPRODUCED, e revisão explícita dos cases SIGAPS legados.
- `make profile-test` e CI Go/PHP verdes no commit **que implementar** o comportamento. CI de uma tranche apenas documental não prova aplicação de regras nem field test.

## Sequência recomendada

1. DUAL-0: continuar reconciliação das fontes históricas SIGAPS com IDs originais, sem encerrar fila desconhecida. Fixar revisão exata do ÉGIDE, inspecionando somente metadados autorizados.
2. DUAL-P1-03A: padronizar evidência por analisador (`detected` vs `applied`) no workspace, mantendo modo atual e adicionando regressões negativas.
3. DUAL-P1-03B: prototipar PHPStan opt-in com provas positivas e negativas; só depois avaliar Psalm. ECS/Rector exigem revisão de isolamento da execução de PHP de configuração.
4. DUAL-1: executar baseline real por cópia isolada apenas quando origem e host estiverem confirmados; jamais usar um sucesso ÉGIDE para resolver ocorrência SIGAPS.
5. DUAL-6/7: regressões cruzadas por tranche, revisão das pendências P0/P1 e gate formal; PR #15 permanece draft e sem merge até aprovação explícita.

**Não há homologação real nesta tranche.** A classificação `GLPI-PR1-REVIEW-03` segue `PARTIAL_SYNTHETIC_REGRESSION_PASSED`; `DUAL-0` e `DUAL-7` seguem abertos.
