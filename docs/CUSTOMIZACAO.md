# Customização

O MVP privilegia convenção e detecção automática. Customizações devem ser explícitas e não devem transformar o Ninfa em boilerplate dentro do consumidor.

## Paths

Os paths são detectados por profile e usados nas configurações externas de ECS, Rector, PHPStan e Psalm. Estruturas não reconhecidas devem falhar de forma visível em vez de receber paths inventados.

## Níveis de análise

No profile `glpi-plugin`, PHPStan e Psalm usam nível 8. Para Yii2/Yii3, os níveis seguem a política atual do `ProjectContext`.

Não use `ignoreErrors` ou baselines amplos apenas para obter uma execução verde.

## Frontend

ESLint e Prettier são ativados somente com evidência JS/TS. Os binários Node são procurados primeiro em `node_modules/.bin`, depois no ambiente do Ninfa e no `PATH`.

## Ferramentas

O resolver prioriza ferramentas locais do projeto, depois ferramentas instaladas junto ao Ninfa e por fim o `PATH`. Isso permite testar o MVP sem obrigar um único modelo de instalação.

## Semântica documental

`README.md`, `AGENTS.md`, `CONTRIBUTING.md`, `ARCHITECTURE.md` e `docs/*.md` podem melhorar a descoberta semântica de símbolos e convenções. Essa informação é complementar e não substitui `composer.json`, código ou estrutura real.

## GLPI

Use `NINFA_GLPI_ROOT` quando o plugin não estiver sob `<glpi>/plugins`. Apenas GLPI 11 é aceito pelo profile atual.

## DAST

DAST é opt-in via `NINFA_DAST=1` e exige `NINFA_ZAP_TARGET` local autorizado. Ambientes não locais precisam de política própria e não são habilitados pelo wrapper padrão do MVP.
