# Ninfa CLI

O Ninfa recebe a raiz do projeto e detecta somente os profiles `glpi-plugin`, `yii3` e `yii2`.

## API pública

```bash
ninfa check ROOT
ninfa fix ROOT
ninfa security ROOT
```

Não existem comandos públicos separados para PHPStan, Psalm, Rector ou ECS. Essas ferramentas são detalhes internos dos pipelines.

`check` executa qualidade e testes. `security` permanece separado. `fix` aplica os hooks corrigíveis e revalida o projeto com `check` ao final.

Em projetos com JavaScript/TypeScript, ESLint e Prettier são adicionados automaticamente.

No profile `glpi-plugin`, PHPStan e Psalm usam nível 8 e o host deve ser GLPI 11.

Configurações e hooks são gerados em workspace externo; o CLI não exige boilerplate Ninfa dentro do consumidor.
