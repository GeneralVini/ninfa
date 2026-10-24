# Plano de execução — SEC-01/02/03

Planejamento iniciado em `2026-10-23T20:48:49-03:00`, fuso `America/Sao_Paulo`. Repositório: `/opt/ninfa`, `origin=https://github.com/GeneralVini/ninfa.git`, baseline `main@438de43` acrescida das correções locais descritas na [revisão](REVISAO-SEC-01-02-03-2026-10-23.md). Este plano detalha a [EAP](EAP-SECURITY-ORCHESTRATION.md); não substitui seus critérios nem marca implementação como concluída.

Objetivo: concluir as 12 tarefas de SEC-01/02/03 e verificar as condições para implementar SEC-04. Execução nativa, ferramentas e artefatos fora do projeto consumidor, Semgrep preservado e Playwright para a automação de navegador prevista no escopo. Não há varredura de `/var/www/psglpi` ou `/var/www/hecate` neste plano de validação; os alvos iniciais serão fixtures descartáveis.

Execução concluída em `2026-10-23T21:08:37-03:00`. [Aceites, verificações e limites](EXECUCAO-SEC-01-02-03-2026-10-23.md). SEC-04 permanece como próxima etapa.

## Ordem das entregas

| Ordem | Pacote | Entrega verificável | Condição para avançar |
| --- | --- | --- | --- |
| 0 | Baseline de retomada | Inventário das alterações locais, testes e estado da EAP | Correções existentes preservadas e regressão conhecida registrada. |
| 1 | SEC-01 | Contrato de execução, exemplos e matriz de resultados | Quatro aceites documentados, sem ambiguidades de escopo ou sucesso. |
| 2 | SEC-02 | Manifesto de ferramentas, instalador e diagnóstico | Instalação limpa e repetida verificadas, versões/integridade e preflight demonstrados. |
| 3 | SEC-03 | Contratos reais, adaptadores e evidências protegidas | Cinco aceites com artefatos rastreáveis e testes positivos/negativos/operacionais. |
| 4 | Revisão de passagem | Checklist final e EAP atualizada | Todos os requisitos de passagem abaixo atendidos. |

As datas reais de início/fim de cada tarefa serão registradas ao executar. Este plano não estima horários de entrega nem preenche retroativamente os inícios ausentes da revisão anterior. Cada entrega será revista antes de iniciar sua dependente; falha de aceite mantém o checkbox aberto.

## 0. Preservar e conferir a retomada

- [x] Conferir branch, remoto, HEAD e diff; preservar as correções locais de parsers, runner, testes e documentação.
- [x] Registrar a regressão atual, incluindo o aviso preexistente de `mkdir` no teste Yii2; não confundir aviso conhecido com validação das tarefas faltantes.
- [x] Inventariar `.tools/` sem assumir sua integridade. Reaproveitar artefatos apenas após comparação com versões/hashes confiáveis; não executar binário desconhecido como primeira forma de validação.
- [x] Conferir a documentação histórica e manter distintas as entregas descartadas, a revisão atual e a nova implementação.

Arquivos existentes centrais: `src/ProjectContext.php`, `PipelinePlan.php`, `PipelineRunner.php`, `ToolResolver.php`, `ToolResult.php`, `SecurityReport.php`, scripts de instalação e testes de segurança.

## 1. Fechar SEC-01 — contratos

Criar `docs/SECURITY-EXECUTION-CONTRACT.md` e alinhar os guias ativos à distinção entre capacidades atuais e futuras. SEC-01 formaliza o contrato; a execução integrada DAST continua atribuída a SEC-05/06.

- [x] **SEC-01.01:** definir os escopos suportados, inclusive auditoria web completa. Nesse modo, ZAP/Nuclei são obrigatórios e Semgrep é preservado. Definir razões verificáveis de não aplicabilidade e a consequência da indisponibilidade de cada fonte.
- [x] **SEC-01.02:** especificar contexto único: projeto/revisão, URL implantada, vínculo código/deploy declarado ou desconhecido, vhost/base path, ativo permitido/excluído, perfil, papel autenticado e identidade da execução. Usar referências a credenciais, sem colocar seus valores no contexto serializado. Distinguir plugin de aplicação hospedeira.
- [x] **SEC-01.03:** especificar descoberta/passivo/ativo e limites de taxa, concorrência, tempo e orçamento; política de redirects, destinos e alteração de estado. Definir cancelamento e limites que os motores realmente conseguem cumprir; capacidade não comprovada permanece indisponível para aquele perfil.
- [x] **SEC-01.04:** definir contrato de resultado com operação, cobertura, findings e gate separados; códigos nativos preservados, desconhecidos explícitos, referências de evidência e IDs estáveis. Especificar evolução compatível do schema 3, sem migrar o CLI implicitamente nesta etapa.

Verificação: exemplos completos para aplicação web, plugin com hospedeiro, projeto sem URL, ferramenta obrigatória ausente, sessão inválida e vínculo deploy desconhecido. Conferir que zero findings não implica cobertura completa e que nenhum exemplo produz sucesso completo quando falta uma fonte obrigatória. Não criar testes que apenas repitam texto documental; testar validação executável quando um contrato passar a ter implementação.

**Saída do pacote:** os quatro itens da EAP validados com links aos contratos e exemplos revisados.

## 2. Fechar SEC-02 — ambiente nativo reproduzível

Arquivos propostos: `security/web-tools.lock.json`, `scripts/web-security-tools.py`, `docs/WEB-SECURITY-TOOLS.md` e testes correspondentes. Integrar comandos de instalação/diagnóstico ao Make, aproveitando `ToolResolver` e o instalador Semgrep existentes sem criar políticas de resolução divergentes.

- [x] **SEC-02.01:** selecionar e fixar ZAP, Java compatível e add-ons necessários. Registrar origem e integridade dos downloads e artefatos instalados. Validar instalação externa ao consumidor e substituir as orientações legadas conflitantes.
- [x] **SEC-02.02:** fixar binário Nuclei e revisão de templates, com hashes verificáveis e atualização explícita. Desabilitar atualização automática durante auditoria e documentar a seleção inicial de protocolos/templates; nenhuma execução ampla por padrão.
- [x] **SEC-02.03:** implementar diagnóstico comum para Composer, acesso OSV, Semgrep, Psalm, ZAP e Nuclei, além dos runtimes e navegador necessários. Informar caminho resolvido, versão, integridade, compatibilidade, causa da falha e efeito sobre a capacidade exigida pelo perfil.

O manifesto deve registrar versões exatas selecionadas mediante consulta às fontes oficiais durante a implementação, URLs e hashes; para templates, também a revisão. OSV é serviço: registrar contrato/API, disponibilidade e data da verificação, sem inventar versão de binário local.

Usar Playwright com Chromium para os fluxos de automação e registrar suas versões. Playwright não substitui automaticamente um requisito WebDriver/Selenium de um add-on ZAP: escolher o mecanismo compatível, explicitar dependências e não instalar Firefox por padrão. Validar separadamente PHP/extensões e demais dependências obrigatórias do Psalm; instalar os componentes necessários ao escopo nativo.

Instalação: downloads temporários, verificação antes de execução, instalação atômica e diagnóstico de falhas sem sobrescrever uma instalação válida. Divergência de versão/hash deve ser erro explícito. Mudanças de pacotes do sistema serão diagnosticadas e tratadas conforme a necessidade real, sem executar instruções de reparo indiscriminadamente.

Verificação obrigatória:

- [x] Instalar em diretório novo, separado de `.tools/` preexistente, e repetir para comprovar idempotência.
- [x] Simular download interrompido, hash divergente, binário não executável, versão incompatível e requisito ausente; impedir probe de artefato sem integridade estabelecida.
- [x] Conferir resolução coerente entre CLI e diagnóstico, inclusive ferramenta do consumidor que tem precedência sobre a privada.
- [x] Fazer preflight real, com resultado individual por fonte; a falha de um requisito não oculta os demais diagnósticos.
- [x] Confirmar que nenhum comando de instalação/diagnóstico altera o consumidor ou inicia varredura web.

**Saída do pacote:** manifesto e instalação reproduzíveis, diagnóstico testado e três itens SEC-02 validados. Binários continuam ignorados pelo Git; instruções, manifesto, testes e evidências sanitizadas são versionáveis.

## 3. Fechar SEC-03 — saídas, adaptadores e evidências

Arquivos propostos: `docs/SECURITY-OUTPUT-CONTRACTS.md`, fixtures em `tests/fixtures/security-outputs/`, coletor controlado, parsers DAST e componentes de sanitização/armazenamento. Aproveitar as correções SCA/SAST atuais.

Ordem interna: levantar os contratos oficiais, preparar proteção para as capturas, coletar artefatos reais e concluir a validação dos adaptadores. A infraestrutura de proteção pode ser preparada antes da coleta; os aceites só serão encerrados quando suas dependências na EAP estiverem satisfeitas.

- [x] **SEC-03.01:** confirmar formatos e códigos nas versões fixadas. Matriz por fonte com entradas, stdout/stderr, retorno nativo, erros, cobertura disponível, limites e referência oficial. Cada fixture deve informar origem real ou sintética, versão, configuração sanitizada/hash, horário e transformações realizadas.
- [x] **SEC-03.02:** confrontar Composer/OSV com as capturas e concluir preservação de aliases, faixas, referências e proveniência. Demonstrar abandono separado de advisory; sem lock original, explicitar a origem e os limites do inventário instalado. Não gerar lock para simular cobertura do original.
- [x] **SEC-03.03:** confrontar Semgrep/Psalm com artefatos reais. Verificar localização/trace, diagnósticos, severidades nativas, retorno Psalm 2, regras puladas, JSON incompatível e erro operacional. Manter gate distinto de confirmação/explorabilidade.
- [x] **SEC-03.04:** implementar parsers isolados de JSON ZAP e JSONL Nuclei. Preservar instâncias, regra/template, matcher, alvo/método/parâmetro quando existentes e proveniência. Erro de processo fica separado dos eventos; vazio não comprova conclusão. Não habilitar ainda a orquestração pública DAST.
- [x] **SEC-03.05:** sanitizar dados antes de logs/relatórios e armazenamento compartilhável; proteger workspace/arquivos e gravação atômica; implementar referências e SHA-256 do artefato efetivo. Raw desabilitado por padrão, habilitação explícita por execução e retenção menor. Definir prazos, gatilhos e limpeza verificável, incluindo execução sem nova captura.

Capturas: usar diretórios temporários e aplicação HTTP em loopback com dados controlados. Capturar Composer, Semgrep, Psalm, Nuclei e relatório ZAP de execuções reais; no OSV, usar coordenadas públicas controladas e conservar proveniência/limites da consulta, além de transporte injetado para falhas. Fixtures sintéticas complementam casos de falha difíceis de reproduzir, com rótulo explícito; não substituem a captura real positiva da fonte.

Proteger os artefatos antes de persistir/publicar, retirar dumps e snippets quando necessário e revisar manualmente fixtures versionáveis. Sanitização por regex não garante anonimização de texto arbitrário. Verificar segredos em headers, cookies, URLs, mensagens e traces; não restringir a verificação ao corpo principal do finding.

**Saída do pacote:** cinco aceites SEC-03 com artefatos rastreáveis. O schema 3 continua consumível; MD/PDF são SEC-08, e agendamento sem repetição é SEC-04.

## Condições para iniciar a implementação SEC-04

- [x] SEC-01.01–04, SEC-02.01–03 e SEC-03.01–05 estão `VALIDATED`, com evidências e horários reais de entrega na EAP.
- [x] Todas as ferramentas do escopo inicial têm versão/identidade verificável e instalação reproduzida; saúde operacional e não aplicabilidade são explícitas.
- [x] Há exemplos reais sanitizados de todas as fontes e testes de erros, parcialidade e resultados vazios.
- [x] Regressões atuais continuam passando: `make syntax profile-test`; testes novos e probes reais necessários também passam.
- [x] Proteção, retenção e limpeza têm testes de segredos, permissões, hash, expiração e falhas de gravação.
- [x] Contexto/identidade de execução e dimensões de equivalência estão definidos para alimentar o catálogo e a chave da unidade de teste; não há promessa de deduplicar cegamente requisições internas dos motores.
- [x] Revisão final confronta código, documentação, manifesto e EAP. Pendências materiais impedem declarar a passagem aprovada.

Ao satisfazer o checklist, iniciar SEC-04.01 (catálogo de capacidades/equivalências), seguida de identidade das unidades, geração do plano, ledger/travas/retomada e provas de não repetição, conforme a EAP existente.
