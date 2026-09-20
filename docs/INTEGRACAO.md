# Integração do Ninfa

A integração do MVP é externa: o Ninfa recebe a raiz do projeto, detecta o profile e gera configurações transitórias fora do consumidor.

Enquanto o MVP permanecer na branch `feature/glpi-plugin-profile`, use essa branch explicitamente ao instalar o Ninfa. Depois do clone em `/opt/ninfa`, adicione o CLI ao `PATH`:

```bash
export PATH="/opt/ninfa/bin:$PATH"
```

## Fontes de contexto

A decisão técnica usa principalmente:

1. filesystem e arquivos do projeto;
2. `composer.json`, quando existir;
3. sinais específicos do profile.

Para semântica complementar, o Ninfa lê `README.md`, `AGENTS.md`, `CONTRIBUTING.md`, `ARCHITECTURE.md` e `docs/*.md`. A documentação enriquece símbolos e contexto, mas não substitui evidência técnica.

## Profiles ativos

```text
glpi-plugin
yii3
yii2
php-generic
```

Profiles especializados têm precedência. `php-generic` só é escolhido quando não há profile especializado e existe evidência real de código PHP. Projetos vazios ou sem sinais suficientes falham explicitamente.

Yii3 exige combinação de sinais de aplicação/runner e infraestrutura; uma dependência `yiisoft/*` isolada não é suficiente.

Laravel e Symfony permanecem em **stand by**.

## Workspace

O Ninfa cria um workspace externo determinístico, por padrão:

```text
/tmp/ninfa/<hash-do-projeto>/
```

Ali são gerados PHPStan, Psalm, ECS, Rector, Lefthook, índice semântico e bootstrap GLPI quando necessário. `NINFA_WORKSPACE_ROOT` não pode apontar para dentro do consumidor.

## Frontend

ESLint e Prettier são ativados somente quando há evidência JS/TS. A execução ocorre no mesmo pipeline e na ordem definida pelo plano.

## Fluxos

```bash
ninfa check [ROOT]
ninfa fix [ROOT]
ninfa security [ROOT]
```

`check` é qualidade. `security` é separado. `fix` aplica os fixers e executa um único `check` ao final.

## Segurança por capacidade

Composer Audit só é executado quando há `composer.lock`. Psalm Taint e Semgrep formam o baseline SAST ativo.

DAST não integra mais o `ninfa security`. A análise dinâmica é delegada à frente especializada externa; `NINFA_DAST=1` apenas gera aviso e não executa OWASP ZAP.

A prioridade de evolução é consolidar SAST por profile, findings estruturados e políticas auditáveis antes de ampliar scanners ou introduzir novas camadas de execução.

## Lefthook

O Lefthook é gerado externamente. A política é `pre-commit -> fix` e `pre-push -> check`. O consumidor não precisa receber um `lefthook.yml` do Ninfa.

## Critério de integração bem-sucedida

A integração está satisfatória quando os comandos públicos executam em um projeto suportado sem exigir cópia do Ninfa, alteração do `composer.json` ou boilerplate no consumidor.

A validação do MVP deve incluir pelo menos um projeto real de cada profile: GLPI Plugin 11, Yii3, Yii2 e PHP genérico.
