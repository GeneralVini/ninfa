# Catálogo de capacidades — SEC-04.01

O [catálogo v1.0.0](../security/capabilities.v1.json) registra objetivos, proprietários, seletores nativos, condições de aplicação, granularidade e limites das alternativas. É a entrada revisada para o futuro planejador. Não agenda scanners nem altera o pipeline público.

| Fonte | Capacidades revisadas | Seleção e limites |
| --- | ---: | --- |
| Semgrep | 30 | Todas as regras locais: 13 comuns, 5 Yii2, 6 Yii3, 6 GLPI. IDs e hashes dos YAMLs exatos; overlays dependem do profile. |
| Psalm | 10 | Tipos de issue taint presentes no PHAR 6.20.1; execução do programa configurado é compartilhada. Um tipo de resultado não equivale a uma execução independente. |
| Composer Audit | 1 | Operação `audit --locked --format=json`, com plugins/scripts desativados. Exige lock e versão real do executável. |
| OSV | 1 | Operações `querybatch` e leitura dos registros completos para Packagist. Exige versão exata, paginação e tratamento de `withdrawn`. |
| ZAP | 8 | Passivas 10020, 10035, 10038, 10021, 10010, 10011; ativas 40012 e 40018. Add-ons pscanrules 70 e ascanrules 78 fixados por hash. |
| Nuclei | 2 | Templates `http-missing-security-headers` e `CVE-2023-23752`, com caminho, hash e revisão do repositório fixados. |

As 52 entradas são um conjunto inicial revisado. Não representam todas as capacidades upstream. Regras/configurações externas a esse conjunto têm sobreposição não controlada; sua ausência no catálogo não autoriza silenciá-las nem declarar cobertura. O contrato SAST existente, com seus 12 objetivos semânticos, permanece em `SecurityContract`.

## Equivalências e alternativas

Nenhuma equivalência entre motores foi comprovada nesta versão. Por isso, todas as alternativas têm `capability: null` e uma razão explícita. As oito relações registradas têm `suppress: false`:

- Quatro candidatas relacionam headers do ZAP ao bundle do Nuclei. São pontos para comparação posterior, sem permissão de substituição.
- Composer e OSV são complementares: bases e snapshots diferem. Um alias comum permite correlacionar achados, mas não elimina a consulta.
- Três exemplos Semgrep/Psalm documentam complementaridade em XSS, SQL e shell. A mesma regra vale para as demais capacidades SAST: CWE, arquivo ou posição coincidentes não provam testes iguais.

O template de headers possui 12 matchers OR e seleção apenas do template completo nesta revisão. Suprimir esse template porque o ZAP observou um header perderia verificações adicionais. ZAP também considera valores, tipo da resposta, thresholds e subalertas; o Nuclei usa condições próprias de status e redirects. As condições precisam ser comparadas para a mesma resposta, sessão e contexto antes de qualquer equivalência futura.

As regras de cookies pertencem ao ZAP passivo: [HttpOnly 10010](https://www.zaproxy.org/docs/alerts/10010/) e [Secure 10011](https://www.zaproxy.org/docs/alerts/10011/). A regra [nosniff 10021](https://www.zaproxy.org/docs/alerts/10021/) verifica também o valor e depende do threshold. Demais referências oficiais constam dos seletores ZAP no JSON; o hash do add-on é a referência executável revisada, pois a documentação online pode evoluir.

A entrada Joomla demonstra a seleção exata de uma CVE. Seus GETs podem revelar configuração sensível; aplicabilidade exige tecnologia/versão verificadas e autorização específica. A presença no catálogo não autoriza executar esse template nem o transforma em default.

## Carregamento e manutenção

`CapabilityCatalog::load($raizNinfa)` valida o documento e devolve um objeto serializável. O carregamento não precisa instalar ferramentas, acessar a rede ou ler um consumidor. Confere:

- Schema e versão do catálogo; proprietários e tipos de seletores compatíveis.
- Hash do lock completo, versões/revisão e hashes dos artefatos upstream referenciados nele.
- Hashes dos arquivos Ninfa e existência dos IDs Semgrep locais.
- IDs e ownership de seletores únicos, condições e razões obrigatórias, referências de relações válidas e bloqueio de supressão/fallback não aprovados.
- Caminhos locais contidos fisicamente na raiz, incluindo resolução de symlinks.

O validador não executa regras para provar sua semântica, nem verifica a instalação efetiva dos motores. IDs ZAP/Psalm e matchers Nuclei foram confrontados com as fontes/documentação e payloads locais fixados na revisão inicial. A instalação efetiva deve passar pelo doctor. Antes de reutilizar cobertura, o futuro planejador deverá registrar versões, hashes da configuração efetiva, regras, contexto e evidência da execução. Configuração gerada, versão real do Composer e snapshot/instante das bases SCA não são fixados só pelo catálogo.

Ao mudar uma regra, add-on, template, motor ou gerador de configuração: revisar o objetivo e as condições; atualizar o lock quando necessário; recalcular os hashes; revisar relações; incrementar `catalog_version`; executar `make syntax profile-test`. Não atualizar hashes automaticamente para aceitar uma mudança ainda não revisada. Alterações de formato exigem migração explícita de `schema_version` e leitor compatível.

## Entrega e continuação

Responsável técnico: Codex. Início: `2026-10-24T13:14:28-03:00`. A validação e o fim estão registrados na [EAP](EAP-SECURITY-ORCHESTRATION.md) e na [evidência SEC-04.01](evidence/sec04-01-validation-2026-10-24.json).

`tests/capability-catalog.php`, integrado ao `profile-test`, verifica as seis fontes, inventário completo das regras Semgrep locais e rejeição de deriva, IDs inexistentes, seletores duplicados, proprietário incorreto, condições ausentes, versões não revisadas, referências quebradas e supressão/fallback indevidos.

A [SEC-04.02](SECURITY-TEST-IDENTITY.md) define identidade por ativo/contexto, método, parâmetro, papel, técnica e hashes. A [SEC-04.03](SECURITY-SCAN-PLAN.md) gera o plano com exclusões justificadas e avisos de sobreposição. O [ledger SEC-04.04](SECURITY-EXECUTION-LEDGER.md) e a [prova SEC-04.05](SECURITY-NON-REPETITION.md) complementam o catálogo; a integração aos adapters reais permanece futura. O catálogo isolado não garante ausência de testes repetidos.
