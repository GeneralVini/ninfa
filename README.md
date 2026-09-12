# Ninfa

**Ninfa** é uma esteira reutilizável de qualidade, compliance de código e segurança para projetos PHP.

O objetivo é fornecer um baseline simples, aberto e replicável, sem acoplar a esteira a um framework específico e sem exigir Docker para execução local.

## Visão geral

```text
NINFA
├── Qualidade e compliance
│   ├── ECS
│   ├── Rector
│   ├── PHPStan
│   ├── Psalm
│   └── PHPUnit
├── Segurança
│   ├── Composer Audit
│   ├── Psalm Taint Analysis
│   └── Semgrep CE
└── DAST
    └── OWASP ZAP
```

A validação normal do projeto é centralizada em:

```bash
composer check
```

O DAST permanece separado porque depende de uma aplicação em execução e realiza testes ativos:

```bash
NINFA_ZAP_TARGET=http://127.0.0.1:8080 composer security:dast
```

## Pré-requisitos

Para implantação completa em Linux:

- PHP compatível com o projeto;
- Composer 2;
- Git;
- Python 3.10+ com suporte a `venv`;
- Java 17+;
- `curl`;
- `sha256sum`;
- Lefthook opcional para hooks locais.

## Para implantar o Ninfa em um projeto já pronto

O Ninfa foi projetado para ser incorporado sem substituir automaticamente o `README.md`, a documentação existente em `docs/`, o `composer.json` ou configurações próprias do projeto.

Clone o Ninfa em um diretório temporário:

```bash
git clone --depth 1 https://github.com/GeneralVini/ninfa.git /tmp/ninfa
```

Entre na raiz do projeto PHP que receberá a esteira:

```bash
cd /caminho/do/projeto
```

Execute o instalador estrutural:

```bash
php /tmp/ninfa/bin/ninfa-install.php .
```

O instalador cria os arquivos ausentes e preserva os existentes. Arquivos diferentes já presentes no projeto são marcados como `MANTIDO` para revisão manual.

Instale as dependências PHP usadas pela esteira:

```bash
composer require --dev \
    symplify/easy-coding-standard \
    rector/rector \
    phpstan/phpstan \
    vimeo/psalm \
    phpunit/phpunit
```

Mescle os scripts do Ninfa no `composer.json` existente:

```bash
php scripts/merge-composer.php composer.json composer.ninfa.example.json
```

Depois execute:

```bash
make setup
composer check
```

Revise as alterações e versione a integração:

```bash
git add .
git commit -m "chore: integra esteira Ninfa"
git push
```

### Sequência completa

```bash
git clone --depth 1 https://github.com/GeneralVini/ninfa.git /tmp/ninfa
cd /caminho/do/projeto
php /tmp/ninfa/bin/ninfa-install.php .
composer require --dev symplify/easy-coding-standard rector/rector phpstan/phpstan vimeo/psalm phpunit/phpunit
php scripts/merge-composer.php composer.json composer.ninfa.example.json
make setup
composer check
```

Após validar a integração, o clone temporário pode ser removido. O projeto consumidor passa a carregar os arquivos do Ninfa em seu próprio repositório.

## Em uma máquina nova, depois que o projeto já usa Ninfa

Depois que a integração já foi versionada no projeto consumidor, não é necessário clonar o repositório do Ninfa novamente.

Basta clonar o próprio projeto:

```bash
git clone URL_DO_PROJETO
cd NOME_DO_PROJETO
make setup
composer check
```

O `make setup` instala as dependências Composer, prepara as ferramentas locais de segurança e instala os hooks quando o Lefthook estiver disponível.

As ferramentas externas ficam isoladas no próprio projeto:

```text
.tools/semgrep/
.tools/zap/
```

Esses diretórios não devem ser versionados.

## O que o instalador incorpora

A instalação estrutural inclui, quando ainda não existem:

```text
Makefile
composer.ninfa.example.json
ecs.php
rector.php
phpstan.neon.dist
psalm.xml
phpunit.xml.dist
lefthook.yml
scripts/bootstrap.sh
scripts/install-security-tools.sh
scripts/merge-composer.php
scripts/semgrep-scan.sh
scripts/zap-scan.sh
security/semgrep.yml
.github/workflows/ninfa.yml
docs/NINFA.md
docs/README-NINFA.md
```

Configurações já existentes são preservadas. Isso é intencional: projetos maduros podem ter paths, níveis, bootstrap de testes e regras próprias que não devem ser substituídos silenciosamente.

## Ajustes após a instalação

Revise principalmente os caminhos analisados em:

- `ecs.php`;
- `rector.php`;
- `phpstan.neon.dist`;
- `psalm.xml`;
- `phpunit.xml.dist`;
- `security/semgrep.yml`.

O baseline assume principalmente `src/` e `tests/`. Projetos Yii, Symfony, Laravel ou estruturas próprias podem exigir também `config/`, `app/`, `modules/`, `public/` ou outros diretórios.

## Comandos

| Comando | Finalidade |
| --- | --- |
| `make setup` | prepara dependências, ferramentas e hooks locais |
| `composer qa` | qualidade, análise estática e testes |
| `composer security` | dependências, taint analysis e Semgrep |
| `composer check` | executa `qa` + `security` |
| `composer fix` | aplica correções automáticas disponíveis |
| `composer security:dast` | executa OWASP ZAP contra aplicação local autorizada |

## Fluxo diário

Para correções automáticas antes do commit:

```bash
composer fix && git add . && git commit -m "feat: descrição da alteração" && git push
```

Antes de integrar ou entregar:

```bash
composer check
```

Para executar apenas segurança:

```bash
composer security
```

## DAST com OWASP ZAP

O DAST não faz parte de `composer check` porque exige uma aplicação em execução e realiza testes ativos.

Com a aplicação local iniciada:

```bash
NINFA_ZAP_TARGET=http://127.0.0.1:8080 composer security:dast
```

Por segurança, o wrapper padrão aceita apenas `localhost` e `127.0.0.1`. Ambientes remotos devem possuir procedimento próprio e autorização explícita.

## GitHub Actions

O instalador disponibiliza o workflow em:

```text
.github/workflows/ninfa.yml
```

A pipeline executa a validação de qualidade e segurança por meio de:

```bash
composer check
```

O OWASP ZAP permanece fora desse workflow padrão porque depende de um alvo em execução.

## Princípios

- execução nativa em Linux;
- sem Docker obrigatório;
- ferramentas PHP instaladas pelo Composer do projeto;
- Semgrep CE instalado em ambiente Python local do projeto;
- OWASP ZAP instalado localmente no projeto;
- versões das ferramentas externas fixadas pelo instalador;
- análise de dependências com `composer audit`;
- análise de fluxo de dados com Psalm Taint;
- regras Semgrep pequenas, explícitas e customizáveis;
- DAST separado da validação comum;
- nenhuma supressão ampla apenas para deixar a pipeline verde;
- integração incremental em projetos existentes.

## Estrutura do repositório Ninfa

```text
.
├── README.md
├── Makefile
├── composer.ninfa.example.json
├── ecs.php
├── rector.php
├── phpstan.neon.dist
├── psalm.xml
├── phpunit.xml.dist
├── lefthook.yml
├── bin/
│   └── ninfa-install.php
├── security/
│   └── semgrep.yml
├── scripts/
│   ├── bootstrap.sh
│   ├── install-security-tools.sh
│   ├── merge-composer.php
│   ├── semgrep-scan.sh
│   └── zap-scan.sh
├── templates/
│   └── github-actions/
│       └── qa-security.yml
└── docs/
    ├── INSTALACAO.md
    ├── INTEGRACAO.md
    ├── COMANDOS.md
    ├── CUSTOMIZACAO.md
    └── SEGURANCA.md
```

## README e documentação do projeto consumidor

O Ninfa não deve substituir o `README.md` do projeto consumidor.

O instalador disponibiliza `docs/README-NINFA.md` como referência para incorporar uma seção resumida ao README já existente e `docs/NINFA.md` como documentação técnica da esteira.

Se o projeto já possui documentação equivalente, prefira incorporar o conteúdo nela em vez de criar arquivos duplicados.

## Compatibilidade

O baseline foi pensado para PHP moderno e pode ser usado com Yii, Symfony, Laravel ou PHP sem framework. Cada projeto deve ajustar diretórios analisados, bootstrap de testes e regras específicas de segurança.

## Escopo das ferramentas

**ECS** verifica estilo e padrões de código. **Rector** verifica e automatiza refatorações. **PHPStan** e **Psalm** realizam análise estática. **PHPUnit** executa testes. **Composer Audit** identifica vulnerabilidades conhecidas em dependências. **Psalm Taint Analysis** rastreia dados potencialmente não confiáveis até sinks sensíveis. **Semgrep CE** aplica regras de segurança e padrões específicos do projeto. **OWASP ZAP** testa dinamicamente a aplicação em execução.

A combinação mínima de segurança priorizada é:

```text
Composer Audit + Psalm Taint Analysis
```

Semgrep CE e OWASP ZAP acrescentam camadas complementares.

## Documentação complementar

- [Instalação](docs/INSTALACAO.md)
- [Integração em projetos existentes](docs/INTEGRACAO.md)
- [Comandos](docs/COMANDOS.md)
- [Customização](docs/CUSTOMIZACAO.md)
- [Segurança](docs/SEGURANCA.md)

## Licença e uso

O Ninfa é um baseline técnico. Cada projeto consumidor continua responsável por revisar suas dependências, regras, riscos, falsos positivos e requisitos próprios de compliance e segurança.
