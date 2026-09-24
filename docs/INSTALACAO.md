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

## Plataformas Linux suportadas no setup

Os scripts internos de preparação e diagnóstico reconhecem duas famílias Linux:

- Debian-like: Debian, Ubuntu e derivados;
- RHEL-like: Oracle Linux, RHEL, Rocky Linux, AlmaLinux, CentOS e Fedora.

A detecção usa `/etc/os-release` e, como fallback, a presença de `apt-get` ou `dnf`. Quando um pré-requisito do sistema estiver ausente, o Ninfa informa o comando correspondente à família detectada e encerra; ele não executa `apt-get`, `dnf` ou instalação de pacotes do sistema automaticamente.

Exemplos de equivalência:

```text
Debian/Ubuntu                 Oracle Linux/RHEL-like
php-cli                       php-cli
git                           git
python3 + python3-venv        python3 + python3-pip
apt-get                       dnf
```

O requisito efetivo para o Semgrep é Python 3.10+ com `python3 -m venv` funcional. Os nomes dos pacotes servem como orientação operacional e podem variar entre versões da distribuição.

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

Para validar o ambiente do próprio Ninfa:

```bash
bash /opt/ninfa/scripts/check-environment.sh
```

Esse script verifica PHP 8.2+, Git, Python, arquivos obrigatórios e permissões dos entrypoints, emitindo instruções de reparo compatíveis com Debian-like ou RHEL/Oracle-like.

## Dependências

O Ninfa resolve ferramentas preferencialmente no projeto consumidor, depois no ambiente do próprio Ninfa e por fim no `PATH`. Nenhuma dependência deve ser instalada silenciosamente no consumidor.

Projetos `php-generic` podem ter `composer.json` ou ser aplicações PHP simples sem Composer. Ferramentas dependentes de Composer só são executadas quando o contexto necessário existe.

O Semgrep CE gerenciado pelo próprio Ninfa é preparado por `scripts/install-security-tools.sh` em `.tools/semgrep`, sem Docker e sem instalação global. O script também detecta a família Linux para orientar a correção de pré-requisitos ausentes, mas não instala pacotes do sistema automaticamente.

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

O `make setup` executa o diagnóstico do ambiente, prepara as ferramentas de segurança gerenciadas pelo Ninfa e roda as validações internas. Projetos consumidores não precisam do `Makefile`.

Para validar apenas a sintaxe dos scripts shell alterados:

```bash
bash -n scripts/check-environment.sh
bash -n scripts/install-security-tools.sh
```

## Estado atual

Os profiles ativos são `glpi-plugin`, `yii3`, `yii2` e `php-generic`.
