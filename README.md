# Ninfa

**Ninfa** é uma esteira externa de qualidade e segurança para projetos PHP. O estado atual é **MVP experimental / 0.1.0-alpha**, destinado a testes controlados em projetos reais.

## Comandos públicos

```bash
bin/ninfa check /caminho/do/projeto
bin/ninfa fix /caminho/do/projeto
bin/ninfa security /caminho/do/projeto
```

`check` executa qualidade e análise estática. `fix` aplica correções automáticas e em seguida executa `check` novamente. `security` executa verificações de segurança separadamente.

## Profiles ativos

O foco do MVP é exclusivamente:

- `glpi-plugin` — GLPI 11;
- `yii3`;
- `yii2`.

Laravel e Symfony ficam em **stand by**. Não há detecção, fallback nem pipeline ativo para esses frameworks nesta fase. Projetos não reconhecidos ou ambíguos falham explicitamente.

## Arquitetura externa

O Ninfa não deve ser copiado para dentro do projeto consumidor. As configurações transitórias são geradas em um workspace externo, por padrão:

```text
/tmp/ninfa/<hash-do-projeto>/
  phpstan.neon
  psalm.xml
  rector.php
  ecs.php
  lefthook.yml
  semantic-index.json
  glpi-bootstrap.php   # quando aplicável
```

O `composer.json` do consumidor não é alterado pelo Ninfa.

## Contexto e semântica

A evidência técnica principal vem de `composer.json` e do filesystem. Para enriquecer a semântica de símbolos e convenções, o Ninfa também lê, quando presentes:

- `README.md`;
- `AGENTS.md`;
- `CONTRIBUTING.md`;
- `ARCHITECTURE.md`;
- `docs/*.md`.

Esses documentos alimentam `semantic-index.json`, mas não substituem sinais técnicos do código e das dependências.

## Pipeline de qualidade

`check` inclui:

```text
ECS
Rector --dry-run
PHPStan
Psalm
ESLint           # quando houver contexto JS/TS
Prettier --check # quando houver contexto JS/TS
PHPUnit          # quando disponível
```

`fix` executa os hooks corrigíveis:

```text
ECS --fix
Rector
ESLint --fix      # quando aplicável
Prettier --write  # quando aplicável
```

Depois dos fixers, o Ninfa executa `check` novamente.

Ferramentas são resolvidas preferencialmente no projeto, depois no ambiente do próprio Ninfa e por fim no `PATH`. Para ferramentas Node, `node_modules/.bin` tem prioridade.

## Segurança

`security` executa:

```text
Composer Audit
Psalm Taint Analysis
Semgrep
```

O DAST com OWASP ZAP é opt-in. Para habilitá-lo:

```bash
NINFA_DAST=1 \
NINFA_ZAP_TARGET=http://127.0.0.1:8080 \
bin/ninfa security /caminho/do/projeto
```

O wrapper padrão do ZAP aceita apenas `localhost` ou `127.0.0.1`.

## GLPI 11

No profile `glpi-plugin`, o host GLPI é localizado por `NINFA_GLPI_ROOT` ou pela estrutura `<glpi>/plugins/<plugin>`. Apenas GLPI 11 é aceito.

PHPStan e Psalm usam nível **8** nesse profile. Quando disponível, `glpi-project/phpstan-glpi` é carregado do plugin ou do host. O core GLPI fornece contexto de símbolos, mas não é tratado como código alvo do plugin.

Consulte [docs/GLPI_PLUGIN.md](docs/GLPI_PLUGIN.md).

## Lefthook

O configurador gera `lefthook.yml` no workspace externo. A política atual é:

```text
pre-commit -> ninfa fix
pre-push   -> ninfa check
```

Nenhum `lefthook.yml` precisa ser criado no projeto consumidor.

## Preparação do workspace

O instalador legado foi reduzido a uma fachada externa e não copia infraestrutura para o consumidor:

```bash
php /caminho/do/ninfa/bin/ninfa-install.php /caminho/do/projeto
```

Também é possível executar diretamente o configurador do Ninfa para inspecionar o profile, workspace e arquivos gerados:

```bash
php /caminho/do/ninfa/scripts/ninfa-configure.php /caminho/do/projeto
```

## CI do próprio Ninfa

O repositório possui `.github/workflows/profile-test.yml`, que executa validação de sintaxe PHP e `make profile-test`. O workflow está verde no MVP atual.

## Critério do MVP

O próximo estágio é validar o Ninfa em projetos reais representativos: um plugin GLPI 11, um Yii2 e um Yii3. O objetivo é executar `check`, `fix` e `security` sem adicionar boilerplate ao consumidor e sem depender de supressões amplas para obter resultado verde.

Laravel e Symfony só voltam ao roadmap depois que esses três profiles estiverem estabilizados em projetos reais.

## Documentação

- [CLI](docs/CLI-DESIGN.md)
- [Instalação](docs/INSTALACAO.md)
- [Integração](docs/INTEGRACAO.md)
- [Comandos](docs/COMANDOS.md)
- [Profile GLPI](docs/GLPI_PLUGIN.md)
- [Customização](docs/CUSTOMIZACAO.md)
- [Segurança](docs/SEGURANCA.md)
