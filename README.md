# Ninfa

**Ninfa** é uma esteira externa de qualidade e segurança para projetos PHP. O estado atual é **MVP experimental / 0.1.0-alpha**, destinado a testes controlados em projetos reais.

## Foco atual

O MVP está focado exclusivamente em três profiles:

- **Yii2** — detectado por `yiisoft/yii2`;
- **Yii3** — detectado por combinação de pacotes de aplicação/runner e infraestrutura Yii;
- **GLPI Plugin 11** — profile `glpi-plugin`, com contexto do host GLPI 11 e PHPStan/Psalm em nível 8.

Laravel e Symfony ficam em **stand by**. Não há detecção, fallback nem pipeline ativo para esses frameworks nesta fase.

## Execução rápida

Enquanto o MVP estiver na branch `feature/glpi-plugin-profile`, clone o Ninfa fora do projeto consumidor:

```bash
git clone --branch feature/glpi-plugin-profile --single-branch \
  https://github.com/GeneralVini/ninfa.git /opt/ninfa
```

Adicione o CLI ao `PATH` da sessão:

```bash
export PATH="/opt/ninfa/bin:$PATH"
```

Para tornar isso permanente, adicione a mesma linha ao arquivo de inicialização do seu shell, por exemplo `~/.bashrc` ou `~/.zshrc`.

Depois execute diretamente; não existe uma etapa obrigatória de instalação ou preparação do workspace:

```bash
# qualidade e testes
ninfa check /path/to/project

# aplica correções automáticas e depois reexecuta check
ninfa fix /path/to/project

# segurança
ninfa security /path/to/project
```

Cada comando detecta o profile e gera/regenera automaticamente o workspace externo correspondente em `/tmp/ninfa/<hash-do-projeto>`.

### Yii2

Um projeto com `yiisoft/yii2` é reconhecido como `yii2` automaticamente:

```bash
ninfa check /path/to/yii2-app
```

### Yii3

Yii3 também é detectado automaticamente, mas não por qualquer pacote `yiisoft/*`. O detector exige sinais consistentes de aplicação/runner e infraestrutura Yii para evitar falsos positivos:

```bash
ninfa check /path/to/yii3-app
```

### GLPI Plugin 11

Quando o plugin está dentro de `<glpi>/plugins/<plugin>`, o host pode ser descoberto automaticamente:

```bash
ninfa check /opt/glpi/plugins/myplugin
```

Quando o plugin está fora da árvore do GLPI, informe o host explicitamente:

```bash
NINFA_GLPI_ROOT=/opt/glpi \
ninfa check /path/to/myplugin
```

Somente GLPI 11 é aceito neste MVP.

## Comandos públicos

```bash
ninfa check /caminho/do/projeto
ninfa fix /caminho/do/projeto
ninfa security /caminho/do/projeto
```

`check` executa qualidade e análise estática. `fix` aplica correções automáticas e em seguida executa `check` novamente. `security` executa verificações de segurança separadamente.

Projetos não reconhecidos ou ambíguos falham explicitamente.

## Arquitetura externa

O Ninfa não deve ser copiado para dentro do projeto consumidor. Os três locais principais são:

```text
/opt/ninfa/                   código do Ninfa
/caminho/do/projeto/          projeto consumidor analisado
/tmp/ninfa/<hash-do-projeto>/ workspace externo gerado
```

No workspace são gerados, conforme necessário:

```text
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
ninfa security /caminho/do/projeto
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

## Diagnóstico e preparação manual

Normalmente não é necessário chamar o instalador ou configurador diretamente. Eles permanecem disponíveis para diagnóstico, inspeção do profile e geração manual do workspace:

```bash
php /opt/ninfa/bin/ninfa-install.php /caminho/do/projeto
php /opt/ninfa/scripts/ninfa-configure.php /caminho/do/projeto
```

## CI do próprio Ninfa

O repositório possui `.github/workflows/profile-test.yml`, que executa validação de sintaxe PHP e `make profile-test`. O workflow está verde no MVP atual.

## Critério do MVP

O próximo estágio é validar o Ninfa em projetos reais representativos: um **Yii2**, um **Yii3** e um **plugin GLPI 11**. O objetivo é executar `check`, `fix` e `security` sem adicionar boilerplate ao consumidor e sem depender de supressões amplas para obter resultado verde.

Laravel e Symfony só voltam ao roadmap depois que esses três profiles estiverem estabilizados em projetos reais.

## Documentação

- [CLI](docs/CLI-DESIGN.md)
- [Instalação](docs/INSTALACAO.md)
- [Integração](docs/INTEGRACAO.md)
- [Comandos](docs/COMANDOS.md)
- [Profile GLPI](docs/GLPI_PLUGIN.md)
- [Customização](docs/CUSTOMIZACAO.md)
- [Segurança](docs/SEGURANCA.md)
