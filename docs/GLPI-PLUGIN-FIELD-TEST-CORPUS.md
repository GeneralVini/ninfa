# Corpus de homologação GLPI 11: SIGAPS e ÉGIDE

## Objetivo

Usar **dois projetos consumidores independentes** para calibrar o profile `glpi-plugin` do NINFA antes de declarar fechado o gate GLPI-6. A análise deve identificar acertos, falsos positivos, falsos negativos verificáveis e casos `unknown`, sem alterar o código original nem copiar código privado para o repositório NINFA.

**O ÉGIDE foi solicitado como segundo caso de homologação. Ainda não foi localizado/validado como diretório ou repositório neste ambiente.** Seu caminho real e a instalação/versão GLPI correspondente precisam ser informados antes de um field test real. Não inferir que esteja na mesma instalação do SIGAPS nem deduzir um caminho a partir do nome.

## Corpus e autorização

| Projeto | Origem | Estado | Restrição de escrita |
| --- | --- | --- | --- |
| SIGAPS | `/var/www/psglpi/plugins/sigaps` | Conhecido por contexto; não montado aqui | **Somente leitura absoluta** |
| ÉGIDE | **A informar pelo responsável** (path local ou URL exata do repositório) | Localização e layout ainda não validados | **Somente leitura absoluta** |
| Fixtures `sigaps`/`egide` | `tests/glpi-field-test.sh` | Sintéticas, sem código dos consumidores | Mutação permitida somente na fixture descartável |

Antes de executar um estudo real, confirmar: diretório raiz do plugin, nome técnico real do diretório (não normalizar o nome visual ÉGIDE), host GLPI 11 associado, autorização de leitura, e se o ambiente permite executar scanners sem escrita no original/host.

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

ÉGIDE (**não substituir por caminho adivinhado**):

```bash
cd /opt/ninfa
EGIDE_ROOT="/caminho/real/do/plugin/egide"  # substitua após confirmar o diretório técnico
bash scripts/glpi-field-test.sh "$EGIDE_ROOT" assist
```

Não executar os comandos acima neste ambiente remoto: as árvores reais não estão disponíveis aqui.

## Matriz comparativa de evidências

| Tema | SIGAPS | ÉGIDE | Política |
| --- | --- | --- | --- |
| `setup.php`, `hook.php`, lifecycle/version | Field test pendente | Field test pendente | Só ausência conclusiva vira `COR-001/002` |
| `Hooks::*` e `$PLUGIN_HOOKS` | Pendente | Pendente | Constantes do host; dinâmicos `unknown` |
| Funções/callables, classes, `Plugin::registerClass()` | Pendente | Pendente | Inventário `true/null`, não presumir autoload inexistente |
| Permissionamento, `Session`, CSRF | Pendente | Pendente | Segurança exige prova de contexto/fluxo |
| DB, migrations, APIs GLPI 11 e deprecations | Pendente | Pendente | Evitar execução do consumidor; SAFE só equivalente e idempotente |
| Baseline Semgrep/taint e segurança | Pendente | Pendente | Separar hotspot de vulnerabilidade comprovada |
| Impacto no consumidor/host | Não executado aqui | Não executado aqui | Snapshot e isolamento obrigatório |

Um projeto não precisa exercitar todas as áreas: lacunas sem código representativo devem ficar `not observed` ou `unknown`, não ser contabilizadas como sucesso.

## Critério de fechamento do corpus

- [ ] Identidade/caminho e host GLPI 11 do ÉGIDE confirmados, sem inferir nome técnico.
- [ ] Resultados de `assist`, `check` e `security` coletados separadamente nos **dois** plugins quando os comandos forem pertinentes.
- [ ] Originais preservados, integridade pós-teste conferida, isolamento efetivo documentado.
- [ ] Findings GLPI nativos e overlays SAST revisados com evidência e triagem por projeto.
- [ ] Diferenças relevantes entre SIGAPS e ÉGIDE viraram testes **sintéticos**, não cópias de código privado.
- [ ] Casos `unknown` e lacunas de autoload/hook/ACL/migration explicitados.
- [ ] Relatório consolidado anexado ao PR #15 e gate GLPI-6 revisado.

**Status:** ÉGIDE adicionado ao plano e às fixtures sintéticas; **nenhuma análise do ÉGIDE real ou do SIGAPS real foi realizada nesta sessão**.
