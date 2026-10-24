# Contrato de execução de segurança — versão 1

SEC-01.01–04. Este documento especifica as próximas integrações. O CLI atual permanece no schema 3; ZAP/Nuclei ainda não participam do pipeline público. As APIs de catálogo, identidade, plano, ledger e explicação SEC-04 estão implementadas e testadas isoladamente. Adapters DAST: SEC-05; integração pública, coordenação e códigos finais: SEC-06.

## Escopo e fontes obrigatórias

| Escopo | Fontes exigidas | Não aplicabilidade verificável |
| --- | --- | --- |
| `web-full` | Composer, OSV, Semgrep, Psalm taint, ZAP e Nuclei | SCA apenas quando inventário demonstra ausência de dependências suportadas; Psalm apenas quando não existe PHP. ZAP e Nuclei são sempre obrigatórios neste escopo. |
| `code` | Semgrep; Psalm para PHP; Composer/OSV para inventário Composer | Sem URL, DAST não aplicável ao escopo code; isto nunca equivale a web-full. |
| `plugin` | Fontes de código aplicáveis ao profile `glpi-plugin` | DAST requer hospedeiro, implantação e rotas do plugin identificados; ausência deles permite apenas conclusão de código. |

Fonte obrigatória ausente, erro, sessão inválida ou cobertura desconhecida impede conclusão completa. Não aplicabilidade exige motivo, evidência e política versionada; ausência de executável, lock, URL ou findings não prova não aplicabilidade. Sem lock original, inventário instalado é parcial e não representa resolução de dependências reprodutível. Composer e OSV preservam proveniência própria mesmo para o mesmo advisory. Semgrep permanece fonte independente de Psalm.

## Contexto único e identidade

O contexto serializado contém `run_id` aleatório, `context_version`, raiz real e identificador do projeto, revisão e estado dirty, digest do inventário/código relevante, escopo, perfil, horário, papel, referências de credenciais e estado da sessão. Nunca contém valores de senha/token/cookie. Credenciais são resolvidas em memória e não entram em hashes compartilháveis.

Para web: URL canônica (scheme/host/porta/base path), virtual host, origens/paths permitidos e excluídos, redirects, revisão implantada e vínculo `declared|verified|unknown` entre código e deploy, com evidência do vínculo. Não inferir revisão implantada da revisão Git local. Plugin identifica revisão própria, hospedeiro e revisão deste, mount path e rotas atribuídas; resultados do hospedeiro não viram automaticamente findings do plugin.

Identidade de contexto não é identidade da execução: execuções distintas podem compartilhar contexto verificável. As unidades SEC-04 incluem contexto, ativo, método, parâmetro/localização, papel, técnica/família de payload, condições, regra/template e hashes de motor/configuração/dados. CWE, URL ou linha isoladamente não provam equivalência. Vínculo desconhecido impede reaproveitar evidência de deploy em outro run. Revisão dirty exige digest; sem digest, retomada é inválida. Não há deduplicação cega das requisições internas dos motores.

A [SEC-04.02](SECURITY-TEST-IDENTITY.md) implementa a identidade dessas unidades em `SecurityTestIdentity`, com descritor canônico versionado. Contextos incompletos recebem nonce e não permitem comparação para deduplicação, inclusive no mesmo run. Igualdade de identidade ainda exige cobertura e evidência válidas para qualquer reutilização, avaliadas pelo ledger SEC-04.04. O contexto completo e suas provas são responsabilidade dos adapters; a identidade SEC-04 consome fingerprints, sem descobrir raiz, deploy ou credenciais automaticamente.

## Perfis e limites

| Perfil | Operações | Limites iniciais máximos |
| --- | --- | --- |
| `discovery` | Navegação explicitamente permitida; Playwright/Chromium quando necessário | 1 navegador, 1 origem, 1 requisição/s, 100 URLs, 5 min |
| `passive` | Observar respostas obtidas por navegação autorizada; regras passivas | 2 requisições/s, concorrência 1, 500 requisições, 10 min |
| `active` | Apenas regras/templates selecionados, alvos e parâmetros explícitos | 2 requisições/s, concorrência 1, 1000 requisições, 15 min |

São limites do contrato, não capacidades já comprovadas do CLI. Contar orçamento antes de enviar; retries também consomem orçamento. Um adaptador só fica disponível no perfil após demonstrar cumprimento dos limites, inclusive processos filhos, tráfego do browser, redirects e chamadas auxiliares. Flags que limitam apenas parte do tráfego não bastam. Se o motor não expõe controle suficiente, exigir intermediário fiscalizador validado ou recusar esse perfil. Não prometer que timeout encerra todos os filhos sem prova.

Redirect só dentro de origens/paths permitidos, no máximo 3 saltos; cada destino é validado antes do envio. Bloquear e registrar saída de escopo, incluindo mudança de host/porta, base path e resolução DNS não autorizada. Destinos auxiliares externos (OAST, atualizações, telemetria) desabilitados. Estado alterado exige perfil active e autorização declarada para a operação; GET pode alterar estado e precisa de classificação também. Login tem permissão própria, sem inferir autorização de logout, criação ou exclusão. No cancelamento/limite: parar agendamento, encerrar grupo de subprocessos, aguardar prazo curto, terminar remanescentes e persistir manifesto parcial. Nunca promover parcial a completo.

## Resultado, cobertura e gate

Separar `operation` (`completed|error|unavailable|cancelled|timeout|not_applicable`), `coverage` (`complete|partial|unknown|not_applicable`, motivo e medidas), findings e `gate` (`pass|block|indeterminate`). Guardar código nativo, stderr sanitizado e falha de parser separadamente. Código zero e zero findings não demonstram cobertura completa; retorno Psalm 2 pode conter findings válidos. Código desconhecido é erro operacional com findings válidos preservados e cobertura desconhecida.

Cada finding mantém origem, ID nativo, regra/template e revisão, localização/instâncias, severidade/confiança nativas, `confirmation=unverified` salvo prova específica, limitações e referências de evidência com digest dos bytes efetivamente armazenados. Identidade canônica é hash de campos normalizados versionados; não inclui mensagens voláteis, valores de segredo ou ordem de apresentação. Correlação preserva IDs e evidências das fontes. Confiança da ferramenta não é confirmação de exploração. Abandono de pacote é política distinta de advisory.

O schema 3 existente não ganha semântica diferente silenciosamente. Campos adicionais opcionais podem preservar compatibilidade; mudança de estados/códigos/campos obrigatórios exige nova versão explícita, adaptador de leitura e testes de consumidores. O contrato futuro não muda o código de saída atual do CLI. Gate de vulnerabilidades e conclusão operacional são dimensões distintas: pode haver achado bloqueante com ferramenta saudável, ou zero achados com conclusão indeterminada.

## Exemplos de decisão

| Contexto | Operação/cobertura | Conclusão |
| --- | --- | --- |
| Aplicação PHP, web-full passivo, seis fontes, sessão válida, deploy verificado, medidas completas | Cada fonte completed/complete; achado alto de Semgrep | Auditoria completa; gate block pela política, sem confirmar exploração. |
| Plugin PHP montado em `/plugins/demo`, hospedeiro e revisões declarados, rotas permitidas | Código completo; DAST parcial por rota não observada | Parcial; findings de host separados; reteste da rota necessário. |
| Biblioteca PHP sem URL, escopo code, lock original e fontes de código completas | DAST not_applicable com motivo de escopo | Código completo; nenhuma alegação web-full. |
| Aplicação web-full com Nuclei ausente | Nuclei unavailable/unknown, demais fontes independentes continuam | Incompleta, gate indeterminate mesmo sem findings. |
| Web autenticado papel operador, verificação de login falhou | Etapas autenticadas error/partial, resultados anônimos identificados | Não concluiu auditoria do papel operador. |
| URL disponível e revisão local conhecida, deploy desconhecido | Vínculo unknown; evidência web e código separadas | Sem correlação temporal garantida; sem reúso entre execuções. |

Os exemplos são contratuais, não capturas de execução. Critérios de cobertura por motor dependem dos contratos de saída SEC-03 e integração SEC-05/06. A [revisão cruzada SEC-04](REVISAO-SEC-04-2026-10-24.md) registra correspondência, correções e limites de cada dependência.
