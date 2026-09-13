# Instalação do Ninfa

O Ninfa é um orquestrador externo. O projeto consumidor não recebe cópias de `Makefile`, scripts, configs ou workflows do Ninfa.

## Uso inicial

Enquanto o MVP estiver na branch `feature/glpi-plugin-profile`, clone explicitamente essa branch fora do projeto consumidor:

```bash
git clone --branch feature/glpi-plugin-profile --single-branch \
  https://github.com/GeneralVini/ninfa.git /opt/ninfa
```

Adicione `/opt/ninfa/bin` ao `PATH`:

```bash
export PATH="/opt/ninfa/bin:$PATH"
```

Depois disso, já é possível executar diretamente:

```bash
ninfa check /caminho/do/projeto
ninfa fix /caminho/do/projeto
ninfa security /caminho/do/projeto
```

Não existe etapa obrigatória de instalação, merge de `composer.json` ou preparação do projeto consumidor.

## Diretórios

```text
/opt/ninfa/                  código do Ninfa
/caminho/do/projeto/         projeto consumidor analisado
/tmp/ninfa/<hash-do-projeto> workspace externo gerado
```

`NINFA_WORKSPACE_ROOT` pode alterar a raiz do workspace, mas o destino não pode ficar dentro do projeto consumidor.

## Diagnóstico manual

O workspace é preparado automaticamente pelos comandos públicos. Para inspecionar profile, paths e configurações geradas:

```bash
php /opt/ninfa/scripts/ninfa-configure.php /caminho/do/projeto
```

## Dependências

O Ninfa resolve ferramentas preferencialmente no projeto consumidor, depois no ambiente do próprio Ninfa e por fim no `PATH`. Nenhuma dependência deve ser instalada silenciosamente no consumidor.

## GLPI

Quando o plugin não estiver em `<glpi>/plugins/<plugin>`, informe:

```bash
export NINFA_GLPI_ROOT=/opt/glpi
ninfa check /caminho/do/plugin
```

Somente GLPI 11 identificável é aceito no profile atual.

## Desenvolvimento do próprio Ninfa

O `Makefile` é interno ao repositório:

```bash
make syntax
make profile-test
make setup
```

Projetos consumidores não precisam dele.

## Estado atual

Os profiles ativos são `glpi-plugin`, `yii3` e `yii2`. O profile PHP genérico será incorporado em etapa própria após estabilização desta base.
