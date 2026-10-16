# Corpus de homologação GLPI 11: SIGAPS e ÉGIDE

## SIGAPS GLPI — fonte confirmada para o corpus (2026-10-15)

**Fonte principal de código:** [`GeneralVini/sigaps_glpi`](https://github.com/GeneralVini/sigaps_glpi), repositório privado, `main@d039aec352b45a89117fa7bf526562bc1a4645bc`. Inspeção read-only de metadados confirmou `type=glpi-plugin`, PSR-4 `GlpiPlugin\\Sigaps\\`, `setup.php`, `hook.php`, declaração GLPI 11 e dependências de análise PHPStan. Exemplo de corpus pertinente: `Hooks::*` e referências `::class` nos registros GLPI; não publicar fonte privada como fixture. `GeneralVini/sigaps` não é a base GLPI autorizada.

A origem de código SIGAPS agora está determinada. Ainda faltam prova da identidade/commit do checkout local e do host correspondente, cópia com isolamento efetivo, regressões de campo e reconciliação de ocorrências legadas. A ausência de GitHub Issues na consulta não implica ausência de issues anteriores. ÉGIDE segue [`GeneralVini/egide`](https://github.com/GeneralVini/egide) em trilha independente. Nenhum field test foi executado.


O plano vinculante de trabalho em duas trilhas, preservação de todas as issues antigas do SIGAPS e critérios de não descarte está em [GLPI-PLUGIN-DUAL-CORPUS-PLAN.md](GLPI-PLUGIN-DUAL-CORPUS-PLAN.md). **Não substituir a fila SIGAPS por findings do ÉGIDE.**

## Objetivo

Usar **dois projetos consumidores independentes** para calibrar o profile `glpi-plugin` do NINFA antes de declarar fechado o gate GLPI-6. A análise deve identificar acertos, falsos positivos, falsos negativos verificáveis e casos `unknown`, sem alterar o código original nem copiar código privado para o repositório NINFA.

**O repositório privado do ÉGIDE foi confirmado:** [GeneralVini/egide](https://github.com/GeneralVini/egide), branch `main` (inspeção estática read-only em 2026-10-15). Foram verificados `setup.php`, `hook.php`, `composer.json` com `type: glpi-plugin`, layout `src/`, autoload PSR-4 e declaração de plataforma GLPI 11. **Não foi confirmada uma instalação local nem o host GLPI correspondente**; análise por GitHub não equivale a executar `ninfa check/assist/security` no projeto real.

## Corpus e autorização

| Projeto | Origem | Estado | Restrição de escrita |
| --- | --- | --- | --- |
| SIGAPS | `/var/www/psglpi/plugins/sigaps` | Conhecido por contexto; não montado aqui | **Somente leitura absoluta** |
| ÉGIDE | [Repositório privado `GeneralVini/egide`](https://github.com/GeneralVini/egide), branch `main` | Estrutura de plugin GLPI 11 confirmada por leitura estática; host/cópia de campo pendentes | **Somente leitura absoluta** |
| Fixtures `sigaps`/`egide` | `tests/glpi-field-test.sh` | Sintéticas, sem código dos consumidores | Mutação permitida somente na fixture descartável |

Antes de executar um estudo real, confirmar: checkout local do plugin, nome técnico preservado (o registry observado utiliza `egide`; não usar o nome visual acentuado como basename), host GLPI 11 associado e isolamento sem escrita no original/host. O repositório é **privado**: não publicar achados com trechos proprietários, paths internos ou detalhes de implementação. Não pressupor que esteja na mesma instalação do SIGAPS.

Antes de coletar novos relatórios, consultar o [histórico SIGAPS reconstruído no GitHub](GLPI-PLUGIN-SIGAPS-HISTORICAL-EVIDENCE.md). A contagem dos achados anteriores permanece desconhecida, não zero; três P1 recuperados referem-se à infraestrutura NINFA, não devem ser contabilizados automaticamente como bugs do consumidor.

## Protocolo de field test

1. Validar por inspeção a raiz do consumidor. O harness `scripts/glpi-field-test.sh` cria uma cópia em `/tmp/ninfa-glpi-field-test.*`, preservando o nome técnico e rejeitando symlinks. **Não apontar `ninfa fix` nem scanners de terceiros diretamente para o original.**
2. Garantir isolamento real de escrita: credenciais que não possam modificar originais, e preferencialmente host/original montados read-only. Hash após a execução detecta mudanças, mas **não impede** escrita transitória/externa.
3. Executar separadamente `assist`, `check` e `security`, conforme disponibilidade do ambiente e autorização. Qualquer teste destrutivo só na cópia, jamais no original.
4. Manter `NINFA_WORKSPACE_ROOT`, `TMPDIR` e logs/caches dentro do sandbox externo. Não rodar instaladores, migrations, GLPI CLI de install/uninstall nem Composer update sobre os consumidores ou um banco de produção.
5. Guardar relatórios em workspace privado separado por projeto, versão e data. **Não anexar arquivos de código-fonte do SIGAPS ou ÉGIDE ao PR**. Publicar somente achados sanitizados ou fixtures sintéticas autorizadas.
6. Fazer triagem de cada finding com `rule_id`, arquivo/linha sanitizados, framework/version, evidência, outcome `TP/FP/TN/FN/UNKNOWN`, razão da decisão e ação (`fix-rule`, `defer`, `accept`). Medir separadamente cobertura, precisão e taxas de inconclusivos, não otimizar apenas por número bruto de alertas.

### Exemplos locais

SIGAPS (caminho conhecido):

```bash
cd /opt/ninfa
bash scripts/glpi-field-test.sh /var/www/psglpi/plugins/sigaps assist
```

ÉGIDE (repositório confirmado; **checkout local e host ainda precisam ser verificados**):

```bash
cd /opt/ninfa
EGIDE_ROOT="/caminho/local/confirmado/egide"  # não presume instalação em /var/www
bash scripts/glpi-field-test.sh "$EGIDE_ROOT" assist
```

Não executar os comandos acima neste ambiente remoto: as árvores reais não estão disponíveis aqui.

## Matriz comparativa de evidências

| Tema | SIGAPS | ÉGIDE | Política |
| --- | --- | --- | --- |
| `setup.php`, `hook.php`, lifecycle/version | Field test pendente | Estrutura presente em `main`; verificação NINFA de campo pendente | Só ausência conclusiva vira `COR-001/002` |
| `Hooks::*` e `$PLUGIN_HOOKS` | Pendente | Uso observado por inspeção estática; resolução pelo host e scanner pendentes | Constantes do host; dinâmicos `unknown` |
| Funções/callables, classes, `Plugin::registerClass()` | Pendente | Imports com `::class`, callbacks simples/compostos e PSR-4 observados; testes NINFA reais pendentes | Inventário `true/null`, não presumir autoload inexistente |
| Permissionamento, `Session`, CSRF | Pendente | Pendente | Segurança exige prova de contexto/fluxo |
| DB, migrations, APIs GLPI 11 e deprecations | Pendente | Pendente | Evitar execução do consumidor; SAFE só equivalente e idempotente |
| Baseline Semgrep/taint e segurança | Pendente | Pendente | Separar hotspot de vulnerabilidade comprovada |
| Impacto no consumidor/host | Não executado aqui | Não executado aqui | Snapshot e isolamento obrigatório |

Um projeto não precisa exercitar todas as áreas: lacunas sem código representativo devem ficar `not observed` ou `unknown`, não ser contabilizadas como sucesso.

## Critério de fechamento do corpus

- [x] Identidade e repositório do ÉGIDE confirmados (`GeneralVini/egide`, privado, `main`); inspeção estática de layout concluída.
- [ ] Checkout local/host GLPI 11 e condições de isolamento do ÉGIDE confirmados para execução real.
- [ ] Resultados de `assist`, `check` e `security` coletados separadamente nos **dois** plugins quando os comandos forem pertinentes.
- [ ] Originais preservados, integridade pós-teste conferida, isolamento efetivo documentado.
- [ ] Findings GLPI nativos e overlays SAST revisados com evidência e triagem por projeto.
- [ ] Diferenças relevantes entre SIGAPS e ÉGIDE viraram testes **sintéticos**, não cópias de código privado.
- [ ] Casos `unknown` e lacunas de autoload/hook/ACL/migration explicitados.
- [ ] Relatório consolidado anexado ao PR #15 e gate GLPI-6 revisado.

**Status:** ÉGIDE real inspecionado **somente via leitura estática no GitHub**, com novo caso de uso que motivou fixture sintética de `Plugin::registerClass(Alias::class)`. **Nenhum comando NINFA foi executado no ÉGIDE real ou no SIGAPS real**; homologação local de ambos segue aberta.
