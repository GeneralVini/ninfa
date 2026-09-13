# Ninfa

**Ninfa** é uma esteira externa de qualidade e segurança para projetos PHP. O estado atual é **MVP experimental / 0.1.0-alpha**, destinado a testes controlados em projetos reais.

## Foco atual

O MVP possui três profiles ativos:

- **Yii2** — detectado por `yiisoft/yii2`;
- **Yii3** — detectado por combinação de pacotes de aplicação/runner e infraestrutura Yii;
- **GLPI Plugin 11** — profile `glpi-plugin`, com contexto do host GLPI 11 e PHPStan/Psalm em nível 8.

Laravel e Symfony permanecem em **stand by**. O próximo profile planejado é **PHP genérico**, mas ele ainda não faz parte desta branch.

## Execução rápida

Enquanto o MVP estiver na branch `feature/glpi-plugin-profile`, clone o Ninfa fora do projeto consumidor:

```bash
git clone --branch feature/glpi-plugin-profile --single-branch \
  https://github.com/GeneralVini/ninfa.git /opt/ninfa
```

Adicione o CLI ao `PATH`:

```bash
export PATH="/opt/ninfa/bin:$PATH"
```

Depois execute diretamente. Não existe etapa obrigatória de instalação ou preparação do projeto consumidor:

```bash
ninfa check /path/to/project
ninfa fix /path/to/project
ninfa security /path/to/project
```

Quando o caminho é omitido, o Ninfa usa o diretório atual.

## Profiles

### Yii2

```bash
ninfa check /path/to/yii2-app
```

Além de layouts simples, o contexto considera estruturas Yii2 com diretórios como `common`, `frontend`, `backend` e `console` quando existentes.

### Yii3

```bash
ninfa check /path/to/yii3-app
```

Uma dependência `yiisoft/*` isolada não é suficiente para classificar um projeto como Yii3.

### GLPI Plugin 11

Quando o plugin está dentro de `<glpi>/plugins/<plugin>`, o host pode ser descoberto automaticamente:

```bash
ninfa check /opt/glpi/plugins/myplugin
```

Quando estiver fora da árvore do GLPI:

```bash
NINFA_GLPI_ROOT=/opt/glpi \
ninfa check /path/to/myplugin
```

Somente GLPI 11 é aceito e a versão do host precisa ser identificável.

## Comandos públicos

```bash
ninfa check [root]
ninfa fix [root]
ninfa security [root]
```

`check` executa qualidade, análise estática e testes disponíveis. `fix` aplica correções automáticas e executa **um único `check`** ao final. `security` permanece separado.

## Arquitetura externa

O Ninfa não é copiado para dentro do projeto consumidor:

```text
/opt/ninfa/                   código do Ninfa
/caminho/do/projeto/          projeto consumidor analisado
/tmp/ninfa/<hash-do-projeto>/ workspace externo gerado
```

O workspace é descartável e pode ser redefinido por `NINFA_WORKSPACE_ROOT`, mas não pode ficar dentro do projeto consumidor.

As configurações geradas ficam no workspace externo, por exemplo:

```text
phpstan.neon
psalm.xml
rector.php
ecs.php
lefthook.yml
semantic-index.json
glpi-bootstrap.php   # quando aplicável
```

O `composer.json` do consumidor não é alterado pelo fluxo público do Ninfa.

## Pipeline de qualidade

`check` inclui, conforme o contexto do projeto:

```text
ECS
Rector --dry-run
PHPStan
Psalm
ESLint           # quando houver contexto JS/TS
Prettier --check # quando houver contexto JS/TS
PHPUnit          # quando disponível
```

`fix` executa os hooks corrigíveis e depois revalida o projeto uma única vez.

## Segurança

`security` usa o baseline atual de segurança do Ninfa. O DAST com OWASP ZAP é opt-in:

```bash
NINFA_DAST=1 \
NINFA_ZAP_TARGET=http://127.0.0.1:8080 \
ninfa security /caminho/do/projeto
```

O wrapper padrão aceita somente `localhost` ou `127.0.0.1`.

## Diagnóstico manual

Normalmente não é necessário preparar o workspace manualmente. Para diagnóstico e inspeção do contexto:

```bash
php /opt/ninfa/scripts/ninfa-configure.php /caminho/do/projeto
```

Esse script também trabalha de forma externa e não deve criar boilerplate no consumidor.

## Desenvolvimento do próprio Ninfa

O `Makefile` existe somente para o desenvolvimento e validação do repositório Ninfa:

```bash
make syntax
make profile-test
make setup
```

Esses targets não são requisitos para projetos consumidores.

## Evolução prevista

Depois de estabilizar Yii2, Yii3 e GLPI Plugin 11, o próximo passo é adicionar o profile **PHP genérico**. Em etapa posterior, o core poderá alimentar uma interface web/dashboard para histórico, findings e acompanhamento das execuções. Essa interface não faz parte do MVP atual.

## Documentação

- [CLI](docs/CLI-DESIGN.md)
- [Instalação](docs/INSTALACAO.md)
- [Integração](docs/INTEGRACAO.md)
- [Comandos](docs/COMANDOS.md)
- [Profile GLPI](docs/GLPI_PLUGIN.md)
- [Customização](docs/CUSTOMIZACAO.md)
- [Segurança](docs/SEGURANCA.md)
