# Identidade de unidades de teste — SEC-04.02

`SecurityTestIdentity` implementa o formato `sec-test-v1:<sha256>` para identificar uma unidade de cobertura vinculada ao [catálogo de capacidades](SECURITY-CAPABILITY-CATALOG.md). A chave distingue contextos e técnicas; não é uma decisão de agendamento, equivalência entre motores ou validade de resultado.

O descritor é serializado como JSON com mapas ordenados recursivamente por chave, listas com ordem preservada, tipos preservados e sem separadores concatenados manualmente. Um contexto completo produz a mesma chave em execuções distintas: `run_id`, horário, mensagens e severidade não entram no hash. Campos desconhecidos ou adicionais são rejeitados; a versão do formato está no descritor e no prefixo da chave.

## Contrato de entrada

`SecurityTestIdentity::create($catalog, $capabilityId, $input)` exige uma capacidade revisada e estes grupos completos. Campos opcionais precisam aparecer explicitamente com `null`.

| Grupo | Campos | Conteúdo e responsabilidade do adapter |
| --- | --- | --- |
| Raiz | `run_id` | Identificador aleatório de 32 caracteres hexadecimais minúsculos. Serve para rastrear contexto incompleto; não define equivalência. |
| `context` | `project_digest`, `scope_digest`, `authorization_digest` | SHA-256 do identificador do projeto, escopo efetivo e política de autorização. Escopo inclui origens, exclusões, redirects e limites aplicáveis. |
| `context` | `code_digest` | SHA-256 do snapshot relevante de código/inventário e dependências. Inclui estado dirty; uma revisão Git sozinha não comprova o conteúdo. `null` bloqueia comparação. |
| `context` | `deployment_digest`, `deployment_link` | Snapshot do deploy e vínculo `verified`, `declared`, `unknown` ou `not-applicable`. Para web, somente vínculo verificado com snapshot conhecido permite comparação. A evidência desse vínculo deve ser conferida e retida pelo adapter. |
| `context` | `host_digest`, `profile` | Profile `php-generic`, `yii2`, `yii3` ou `glpi-plugin`. Plugin exige snapshot do hospedeiro, incluindo revisão, mount path e atribuição de rotas. |
| `context` | `role_digest`, `session_context_digest` | Fingerprints de papel/permissões efetivas e contexto lógico da sessão, sem valores de credenciais. Para público sem sessão, usar um descritor explícito de acesso público/stateless; `null` significa desconhecido para web. |
| `asset` | `kind`, `digest` | Tipo de ativo e SHA-256 do seu descritor normalizado sem segredos. Tipos aceitos dependem do motor, conforme tabela abaixo. |
| `request` | `method`, `parameter_location`, `parameter_digest` | Método HTTP exato; localização `query`, `body`, `header`, `cookie`, `path` ou `multipart`; fingerprint do nome/identificador do parâmetro, incluindo índice quando necessário. Localização e fingerprint são ambos conhecidos ou ambos `null`. |
| `bindings` | `engine_version`, `engine_digest`, `runtime_digest` | Versão efetiva, hash do motor/add-ons e fingerprint do runtime/plataforma. Versões dos motores presentes no catálogo precisam coincidir com as revisadas. Composer e cliente OSV registram sua versão efetiva. |
| `bindings` | `configuration_digest`, `conditions_digest` | Hash da configuração efetiva e dos fatos de aplicabilidade/condições. Incluir profiles, thresholds, strength, APIs/modelos, exclusões, política e limites que influenciem a cobertura. |
| `bindings` | `rules_digest` | Fingerprint de capacidade, seletores, artefatos e versão do catálogo. `rulesDigest()` fornece o valor esperado para confronto após verificar os bytes efetivos. Valor divergente é rejeitado. |
| `bindings` | `data_digest` | Snapshot da base consultada ou respostas completas/paginadas e metadados de consulta. Obrigatório para comparar SCA; base live sem snapshot verificável usa `null`. Em outras fontes, pode ser `null` quando não há base externa aplicável. |
| `bindings` | `payload_family_digest` | Fingerprint da técnica/família de payload efetiva, incluindo opções relevantes. Ausência bloqueia comparação de injeção ativa e CVE específica. Pode ser `null` para análise sem payload ativo. |

Os digests são SHA-256 hexadecimais minúsculos de 64 caracteres. Não se deve colocar segredos nos descritores nem gerar fingerprints de senha, token ou valor de cookie: referências não secretas e metadados da sessão devem ser usados. A API não aceita URL, corpo, headers ou credenciais brutos. Ela valida formato e coerência; não consegue provar a origem de um digest fornecido pelo chamador.

`project_digest`, `asset.digest` e outros fingerprints são produzidos pelo adapter futuro. O adapter precisa definir e versionar sua normalização dentro do descritor de origem antes de calcular o digest. Não há canonicalização automática de URLs ou paths nesta classe. Para HTTP, preservar scheme, virtual host, porta, base path, bytes relevantes de path/query e seleção da instância; não ordenar parâmetros repetidos ou decodificar caminhos presumindo equivalência. Para cookies, identificar nome, domínio e path, sem valor. Métodos HTTP preservam maiúsculas/minúsculas: `GET` e `get` não são unidos. O corpo/variante de entrada que afeta um teste deve estar representado no fingerprint do ativo ou da configuração sem armazenar dados sensíveis.

## Granularidade por motor

| Fonte | `asset.kind` | Unidade de cobertura e limite de execução |
| --- | --- | --- |
| Semgrep | `codebase` | Regra no snapshot do conjunto analisado. A mesma posição/CWE em outra regra não produz equivalência. Execuções podem compartilhar parsing/análise interna. |
| Psalm | `codebase` | Capacidade/tipo de issue no programa configurado. Vários tipos compartilham uma passagem indivisível; a identidade não autoriza executar Psalm uma vez por tipo. |
| Composer / OSV | `component-inventory` | Operação no inventário exato e snapshot da base. Batching/paginação são internos; fontes distintas mantêm identidades distintas. |
| ZAP passivo | `http-response` | Regra sobre resposta observada e sessão/escopo. O digest do ativo identifica a resposta/variante relevante sem segredos. Regras de cookies exigem identidade do cookie em `request`, com localização `cookie`. |
| ZAP ativo | `http-target` | Regra, método, localização e parâmetro/vetor no contexto autorizado. Vetor desconhecido impede comparação. Requisições internas não são deduplicadas por esta chave. |
| Nuclei | `http-target` | Template completo e seu contexto. O bundle de headers não se divide em 12 agendamentos; caminhos e matchers internos continuam ligados ao template e seu hash. |

Para código/inventário, método e parâmetro devem ser `null`; deploy, papel e sessão web são não aplicáveis e não podem ser fornecidos. `host_digest` é necessário para `glpi-plugin`. O snapshot em `code_digest` pode representar o inventário no caso SCA. Para web, método desconhecido impede comparação. Parâmetro pode ser não aplicável em templates e regras de headers; cookies e injeção ativa têm exigências adicionais.

## Contexto desconhecido e comparação

Faltando um fato necessário, a criação ainda permite representar a unidade, mas retorna `verifiable: false`, lista as limitações e acrescenta `run_id` e um nonce aleatório ao descritor. Duas criações incompletas nunca compartilham a chave, mesmo no mesmo run. `sameVerifiableUnit()` devolve `false` para qualquer unidade incompleta, inclusive comparada consigo mesma. Isso evita tratar ausência de informação como evidência para deduplicação.

Com todos os fatos necessários, `verifiable: true` significa que os campos necessários foram fornecidos e passaram pelas validações locais. O adapter continua responsável por comprovar snapshot, sessão, versão, configuração e bytes de regras efetivos. Copiar `rulesDigest()` sem verificar os artefatos não comprova esses fatos.

`sameVerifiableUnit()` compara somente a identidade. Mesmo com retorno `true`, o ledger SEC-04.04 exige execução concluída, cobertura válida, evidência íntegra, sessão válida, retenção/prazo e política de reutilização antes de omitir qualquer teste. Não existem equivalências entre motores aprovadas no catálogo v1; seus IDs e proprietários permanecem distintos nas chaves.

## Validação e sequência

Responsável técnico: Codex. Início: `2026-10-24T13:20:30-03:00`. Fim e evidências na [EAP](EAP-SECURITY-ORCHESTRATION.md) e no [registro de validação](evidence/sec04-02-validation-2026-10-24.json).

`tests/security-test-identity.php` integra `make profile-test`: testa estabilidade entre runs e reordenação de campos; separação por contexto, ativo, papel, sessão, método, parâmetro e hashes; rejeição de contratos inválidos; seis fontes; limites de Psalm/Nuclei; snapshots SCA desconhecidos; host GLPI; isolamento de fatos incompletos e campos brutos de segredo rejeitados. Uma mudança no formato canônico requer nova versão e invalidação/migração explícita das chaves persistidas.

A [SEC-04.03](SECURITY-SCAN-PLAN.md) consome catálogo e identidades para gerar o plano e agrupar execuções compartilhadas. O [ledger SEC-04.04](SECURITY-EXECUTION-LEDGER.md) implementa concorrência, retomada, retries e validade da cobertura. A implementação atual não altera o pipeline público nem executa scans.
