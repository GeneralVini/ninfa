# Integração do Ninfa

O Ninfa deve ser incorporado de forma incremental ao projeto existente.

## 1. Pré-requisitos

- PHP compatível com as dependências do projeto;
- Composer 2;
- Git;
- Python 3.10+ com suporte a `venv`;
- Java 17+;
- `curl`;
- `sha256sum`;
- Lefthook opcional para hooks locais.

## 2. Dependências PHP

No projeto consumidor, instale as ferramentas de desenvolvimento compatíveis com a versão de PHP adotada:

```bash
composer require --dev symplify/easy-coding-standard rector/rector phpstan/phpstan vimeo/psalm phpunit/phpunit
```

Não copie um `composer.json` completo do Ninfa. Incorpore apenas os scripts de `composer.ninfa.example.json` ao `composer.json` já existente.

## 3. Arquivos a incorporar

Copie ou adapte:

```text
Makefile
ecs.php
rector.php
phpstan.neon.dist
psalm.xml
phpunit.xml.dist
lefthook.yml
security/semgrep.yml
scripts/bootstrap.sh
scripts/install-security-tools.sh
scripts/semgrep-scan.sh
scripts/zap-scan.sh
```

Copie `templates/github-actions/qa-security.yml` para `.github/workflows/ninfa.yml`.

## 4. README e docs existentes

Não substitua o `README.md` do projeto. Use `templates/README-NINFA.md` como seção a incorporar.

Como os projetos normalmente já possuem `docs/`, copie `templates/docs/NINFA.md` para `docs/NINFA.md` e adapte apenas o que for específico do projeto.

Se já existir documentação equivalente, incorpore o conteúdo nela em vez de criar duplicação.

## 5. Ajustes obrigatórios

Revise os caminhos analisados em:

- `ecs.php`;
- `rector.php`;
- `phpstan.neon.dist`;
- `psalm.xml`;
- `phpunit.xml.dist`.

O template assume inicialmente `src/` e `tests/`. Frameworks podem exigir `config/`, `app/`, `public/`, `modules/` ou outros diretórios.

## 6. Validação

Execute:

```bash
make setup
```

Depois, o comando canônico de validação passa a ser:

```bash
composer check
```

O DAST é separado:

```bash
NINFA_ZAP_TARGET=http://127.0.0.1:8080 composer security:dast
```

## 7. Critério de adoção

A integração está concluída quando `composer check` executa com sucesso no ambiente local e no GitHub Actions sem supressões genéricas criadas apenas para contornar achados.
