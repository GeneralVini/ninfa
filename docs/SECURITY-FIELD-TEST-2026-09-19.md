# Teste de campo de seguranca

Data: 2026-09-19

Branch do Ninfa: `feature/glpi-plugin-profile`

Versao inicial testada: `8fba47b10cfaf392706771f286b7b481d38ee363`

Projetos consumidores:

- GLPI Plugin 11: `/var/www/psglpi/plugins/sigaps`
- Yii3: `/var/www/hecate`

Os projetos consumidores ja continham alteracoes locais. O teste nao corrigiu nem
removeu essas alteracoes. DAST nao foi habilitado. Os dados brutos estao em
`/home/dexter/Documentos/ninfa-test-20260919-152437`.

## Ambiente

| Item | Valor |
|---|---|
| PHP CLI | 8.5.4 |
| Composer | 2.9.5 |
| Semgrep | 1.177.0 |
| Psalm SigaPS | 6.17.1 |
| Psalm Hecate | 6.17.0 |
| GLPI detectado | 11.0.8 |

## Resultado executivo

Os dois projetos terminaram `ninfa security` com codigo 0. Isso confirma que o
baseline atual foi executado sem findings, dentro dos paths e das tres regras
configuradas. Nao confirma ausencia de vulnerabilidades no projeto, host,
runtime ou dependencias nativas.

| Evidencia | SigaPS | Hecate |
|---|---:|---:|
| Profile | glpi-plugin | yii3 |
| Pacotes Composer runtime | 0 | 69 |
| Pacotes Composer dev | 78 | 68 |
| Drift lock versus installed.json | 0 | 0 |
| Composer Audit | 0 advisories; uma repeticao falhou por DNS | 0 advisories |
| OSV manual | 0 pacotes afetados em 78 | 0 pacotes afetados em 137 |
| Psalm Taint | 0 findings | 0 findings |
| Semgrep | 0 findings em 76 arquivos | 0 findings em 69 arquivos |
| `ninfa security` | exit 0 | exit 0 |
| `ninfa check` | exit 0 | exit 1, 41 findings PHPStan |

A consulta OSV foi um teste complementar e nao faz parte do comando atual do
Ninfa. Um controle positivo com `yiisoft/yii2` 2.0.0 retornou dez IDs, provando
que o cliente e a fonte estavam respondendo. Esses IDs precisam ser consolidados
por aliases antes de serem contados como vulnerabilidades unicas.

## Metricas

| Execucao | Tempo | RSS maximo |
|---|---:|---:|
| SigaPS security, primeira execucao | 54.25 s | 1,093,820 KiB |
| SigaPS security, repeticao | 14.62 s | 742,444 KiB |
| SigaPS Psalm Taint isolado | 11.21 s | 738,096 KiB |
| SigaPS Semgrep isolado | 4.31 s | 158,484 KiB |
| SigaPS OSV manual | 5.90 s | nao medido |
| SigaPS check | 56.35 s | 687,448 KiB |
| Hecate security, primeira execucao | 13.61 s | 272,776 KiB |
| Hecate security, repeticao | 7.91 s | 287,040 KiB |
| Hecate Psalm Taint isolado | 3.30 s | 287,328 KiB |
| Hecate Semgrep isolado | 3.80 s | 147,284 KiB |
| Hecate OSV manual | 0.74 s | nao medido |
| Hecate check ate PHPStan | 13.62 s | 184,588 KiB |

As diferencas entre primeira execucao e repeticao mostram efeito relevante de
cache. Metricas futuras precisam registrar estado de cache, versao das
ferramentas e hashes do inventario para permitir comparacao.

## Controles

Uma fixture externa, que nao foi colocada nos consumidores, continha chamadas a
`shell_exec`, `unserialize` e query SQL concatenada. O Semgrep encontrou os tres
padroes e retornou exit 1. Uma fixture sem esses padroes nao gerou finding.

O teste comprova que as regras carregam e bloqueiam exemplos simples. Nao mede
recall sobre variantes sintaticas, aliases, wrappers, fluxo interprocedural ou
codigo dinamico.

## Qualidade dos consumidores

O SigaPS apresentou ECS, Rector, PHPStan, Psalm, ESLint e Prettier sem achados.
O PHPUnit informou `No tests executed!`, mas o Ninfa marcou o hook como concluido.
O projeto usa `composer test` para executar `php tests/workflow_test.php`; o
runner atual escolhe diretamente `vendor/bin/phpunit` e nao executa esse teste.

O Hecate chegou ao PHPStan e parou com 41 findings:

| Regra | Quantidade |
|---|---:|
| `argument.type` | 15 |
| `cast.int` | 13 |
| `cast.string` | 11 |
| `staticMethod.alreadyNarrowedType` | 1 |
| `greaterOrEqual.alwaysTrue` | 1 |

Os arquivos com mais findings foram `PrinterListQuery.php` (8),
`LocationListQuery.php` (7), `config/common/params.php` (5) e
`InventoryMetricsQuery.php` (4). Como o pipeline e fail-fast, Psalm, ESLint,
Prettier e PHPUnit nao foram executados nessa rodada. Esses 41 findings pertencem
ao consumidor; a interrupcao das etapas restantes e comportamento do Ninfa.

## Cobertura observada

O profile GLPI selecionou `src`, `front`, `ajax` e `tests`. `hook.php` e
`setup.php`, dois entrypoints relevantes do plugin, ficaram fora. O profile Yii3
selecionou `src`, `config` e `tests`; `public/index.php` ficou fora.

O Semgrep respeitou arquivos rastreados pelo Git e ignorou dois arquivos PHP de
teste em cada consumidor. Essa politica nao e apresentada no resumo do Ninfa.

O lock do SigaPS descreve somente ferramentas de desenvolvimento. A auditoria
do plugin nao incluiu o inventario Composer do host GLPI. Isso pode ser correto
como escopo padrao, mas precisa aparecer como `host: not_scanned`, e nao ficar
implicito em um resultado verde.

Psalm recebeu `phpVersion=8.2`, obtido do primeiro numero encontrado na constraint
do Composer, enquanto o processo executou em PHP 8.5.4. Para a constraint do
Hecate `8.2 - 8.5`, isso representa o limite minimo, nao o runtime real. Os dois
valores devem ser registrados separadamente.

O bootstrap gerado para GLPI inclui o `vendor/autoload.php` do host. Portanto, a
implementacao atual nao satisfaz plenamente o objetivo de nunca executar codigo
do consumidor/host durante a analise.

## Esperado versus implementado

| Capacidade esperada | Estado observado |
|---|---|
| Profiles GLPI Plugin 11 e Yii3 | Implementado e detectado corretamente |
| Composer Audit, Psalm Taint e Semgrep | Implementado |
| Continuar etapas independentes apos finding | Nao implementado |
| Resultado estruturado de todas as etapas | Parcial; security usa saida textual |
| OSV integrado | Nao implementado; apenas teste manual |
| Deduplicacao CVE/GHSA/PKSA | Nao implementado |
| EPSS, KEV, CVSS e prioridade | Nao implementado |
| Cobertura e estados unknown/not_scanned | Nao implementado |
| Inventario lock versus instalado | Nao apresentado pelo CLI |
| Relatorio JSON canonico | Nao implementado para security |
| Metricas por etapa | Nao implementado |
| Isolamento por execucao | Nao implementado; workspace por projeto |
| Consumidor estritamente sem execucao | Nao atendido no bootstrap GLPI |
| Teste real do consumidor | Incorreto quando o projeto usa script customizado |

## Correcoes priorizadas

### P0 - resultado confiavel

1. Executar todas as etapas independentes e consolidar seus estados, sem retornar
   no primeiro exit diferente de zero.
2. Tratar `No tests executed` como `empty`/atencao ou falha configuravel; respeitar
   o script Composer do consumidor quando ele for a fonte declarada do teste.
3. Incluir entrypoints de profile: `hook.php` e `setup.php` no GLPI; `public` no
   Yii3. Expor arquivos ignorados e razao.
4. Diferenciar finding, erro de infraestrutura, timeout, etapa ausente e cobertura
   parcial no exit code e no relatorio.

### P1 - arquitetura de seguranca

1. Criar inventario estruturado com lock, instalacao observada, escopo runtime/dev,
   profile, host e runtime PHP.
2. Integrar OSV por `querybatch`, detalhes, cache, paginacao, aliases, withdrawn e
   privacidade para pacotes privados.
3. Produzir JSON canonico e renderizar terminal a partir dele, incluindo tempos,
   memoria quando disponivel, versoes e cobertura.
4. Tornar Composer Audit seguro por construcao com plugins e scripts desabilitados.
5. Substituir o carregamento do autoloader GLPI por stubs/indice sem execucao, ou
   declarar e isolar explicitamente essa excecao.

### P2 - priorizacao e operacao

1. Adicionar EPSS e KEV apenas para CVEs aplicaveis, preservando proveniencia e
   idade dos snapshots.
2. Separar severity, exploitability, exposure e prioridade; nao criar CVSS para
   findings SAST.
3. Isolar workspaces por `run_id`, com ponteiro atomico para a ultima execucao.
4. Definir budgets, timeout, retry e cache; o timeout DNS observado no Packagist
   deve aparecer como infraestrutura indisponivel, nunca como zero advisories.

## Criterio do resultado atual

Classificacao: **baseline executado, cobertura limitada, nenhum finding nas
fontes consultadas**.

Nao classificar como `secure`, `sem vulnerabilidades` ou `aprovado pelo escopo
alvo`. O comando atual atende ao baseline MVP documentado, mas ainda nao atende
ao modelo consolidado de deteccao, contexto e prioridade proposto para o Ninfa.

## Reteste do runner consolidado

Apos a implementacao da continuidade por etapa, o `ninfa check /var/www/hecate`
foi repetido. PHPStan falhou com 41 findings; Psalm ainda executou e apresentou
90 findings; PHPUnit executou depois e passou com 2 testes e 5 assertions.

O resumo final registrou:

```text
✓ ecs: ok (codigo 0)
✓ rector: ok (codigo 0)
✗ phpstan: failed (codigo 1)
✗ psalm: failed (codigo 2)
✓ test: ok (codigo 0)
```

O processo retornou codigo 1, preservando a primeira falha. Um teste isolado
tambem comprovou continuidade quando uma ferramenta nao pode ser resolvida e a
representacao de etapas opcionais desabilitadas ou nao aplicaveis como `skipped`.
