# Integração do Ninfa

A integração do MVP é externa: o Ninfa recebe a raiz do projeto, detecta o profile e gera configurações transitórias fora do consumidor.

## Fontes de contexto

A decisão técnica usa principalmente:

1. `composer.json`;
2. filesystem e arquivos do projeto;
3. sinais específicos do profile.

Para semântica complementar, o Ninfa lê `README.md`, `AGENTS.md`, `CONTRIBUTING.md`, `ARCHITECTURE.md` e `docs/*.md`. A documentação enriquece símbolos e contexto, mas não substitui evidência técnica.

## Profiles ativos

Somente estes profiles são aceitos no MVP:

```text
glpi-plugin
yii3
yii2
```

Yii3 exige uma combinação de sinais do ecossistema de aplicação/runner e infraestrutura; uma dependência `yiisoft/*` isolada não é suficiente. Projetos não reconhecidos falham explicitamente.

Laravel e Symfony estão em **stand by**. Não devem ser tratados como fallback, nem receber detecção heurística parcial enquanto `glpi-plugin`, Yii3 e Yii2 não estiverem estabilizados em projetos reais.

## Workspace

O Ninfa cria um workspace externo determinístico, por padrão:

```text
/tmp/ninfa/<hash-do-projeto>/
```

Ali são gerados PHPStan, Psalm, ECS, Rector, Lefthook, índice semântico e bootstrap GLPI quando necessário.

Nada disso precisa ser versionado no consumidor.

## Frontend

ESLint e Prettier são ativados somente quando há evidência JS/TS real, como `package.json` com tooling/frontend ou arquivos JavaScript/TypeScript detectáveis. A simples existência de diretórios genéricos não deve ativar o pipeline Node.

## Fluxos

```bash
bin/ninfa check ROOT
bin/ninfa fix ROOT
bin/ninfa security ROOT
```

`check` é qualidade. `security` é separado. `fix` aplica os fixers e reexecuta `check` ao final.

## Lefthook

O Lefthook é gerado externamente. A política é `pre-commit -> fix` e `pre-push -> check`. O consumidor não precisa receber um `lefthook.yml` do Ninfa.

## Critério de integração bem-sucedida

Para o MVP, a integração está satisfatória quando os três comandos públicos executam num projeto suportado sem exigir cópia do Ninfa, alteração do `composer.json` ou supressões amplas apenas para obter resultado verde.

A validação inicial deve priorizar um projeto real de cada profile ativo: `glpi-plugin`, Yii3 e Yii2.
