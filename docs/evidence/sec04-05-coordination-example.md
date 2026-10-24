# Coordenação dos testes

Plano: `sec-plan-v1:999bbf35950d5fa71f0ba50de9212050b17bba2b8ea62ca0295e56db191c7228`.

Tentativas neste plano: 0. Eventos de reuso: 8.

## Eventos de supressão

| Capacidade | Decisão | Motivo e referência |
| --- | --- | --- |
| ninfa.php.xss.request-to-output | duplicate-proposal | Mesma unidade verificável agendada uma vez no plano; nenhuma execução anterior presumida. Referência: sec-test-v1:1195ac3db86fed93ce05e9d4f430adbbc5583317ef33f9ddab874f5203ed4e0b |
| composer.advisories | valid-coverage-reused | Ledger validou identidade, contexto, operação, cobertura, sessão, prazo e evidência no momento da decisão; adapter não chamado. Referência: 1351bc85bcce2e32d9f7729f2768b30f |
| osv.packagist | valid-coverage-reused | Ledger validou identidade, contexto, operação, cobertura, sessão, prazo e evidência no momento da decisão; adapter não chamado. Referência: 8f21afffed3f1ad0f2efc7f1a868f5bc |
| ninfa.php.xss.request-to-output | valid-coverage-reused | Ledger validou identidade, contexto, operação, cobertura, sessão, prazo e evidência no momento da decisão; adapter não chamado. Referência: a1cd4cfbb9a7a5248ce66461d31fc739 |
| psalm.TaintedHtml | valid-coverage-reused | Ledger validou identidade, contexto, operação, cobertura, sessão, prazo e evidência no momento da decisão; adapter não chamado. Referência: 7a0b2a3c7d05d0c15ccb26a746359bea |
| psalm.TaintedSql | valid-coverage-reused | Ledger validou identidade, contexto, operação, cobertura, sessão, prazo e evidência no momento da decisão; adapter não chamado. Referência: 7a0b2a3c7d05d0c15ccb26a746359bea |
| zap.passive.csp | valid-coverage-reused | Ledger validou identidade, contexto, operação, cobertura, sessão, prazo e evidência no momento da decisão; adapter não chamado. Referência: 0cb190152bc1fe47428940518e660cb5 |
| zap.active.reflected-xss | valid-coverage-reused | Ledger validou identidade, contexto, operação, cobertura, sessão, prazo e evidência no momento da decisão; adapter não chamado. Referência: 42f3f4be78a124b6097a9c8215cd0e55 |
| nuclei.CVE-2023-23752 | valid-coverage-reused | Ledger validou identidade, contexto, operação, cobertura, sessão, prazo e evidência no momento da decisão; adapter não chamado. Referência: 8b02974ff0316286587c5f74ce391807 |
| nuclei.security-headers-bundle | valid-coverage-reused | Ledger validou identidade, contexto, operação, cobertura, sessão, prazo e evidência no momento da decisão; adapter não chamado. Referência: 8920146f4addb59d24d1592e53db89e4 |

## Outras decisões

Exclusões por escopo/profile: 0. Unidades bloqueadas no plano: 0. Capacidades não solicitadas: 43.

## Avisos de sobreposição e cobertura

- complementary-overlap: Bases e snapshots distintos; aliases permitem correlacionar resultados, sem eliminar consultas.
- complementary-overlap: Modelos e fontes/sinks distintos; CWE/posição não demonstram teste igual.
- indivisible-engine-analysis: Tipos de issue compartilham análise do programa; checks fora do catálogo podem ocorrer. Seletores são projeções de cobertura, não filtros de execução.
- uncontrolled-overlap: Somente matcher content-security-policy se sobrepõe. Elegibilidade, valores, status, redirects e subalertas diferem; exclusão do bundle perderia outros checks.

## Limites

- Histórico de coordenação, sem revalidação atual de cobertura/evidência.
- Eventos de supressão não são contagem de vulnerabilidades nem de requisições HTTP poupadas.
- Seleção das fontes não comprova baseline completo ou auditoria concluída.
- Sem equivalência entre motores aprovada no catálogo v1.
