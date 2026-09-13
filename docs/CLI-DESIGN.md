# Ninfa CLI

O Ninfa recebe opcionalmente a raiz do projeto e detecta os profiles ativos `glpi-plugin`, `yii3` e `yii2`.

Laravel e Symfony permanecem em **stand by**. O profile `php-generic` está planejado para a próxima etapa, mas ainda não é aceito nesta branch.

## API pública

```bash
ninfa check [root]
ninfa fix [root]
ninfa security [root]
```

Quando `root` é omitido, o diretório atual é usado.

Também está disponível:

```bash
ninfa --help
```

Não existem comandos públicos separados para PHPStan, Psalm, Rector ou ECS. Essas ferramentas são detalhes internos dos pipelines.

`check` executa qualidade e testes. `security` permanece separado. `fix` aplica os hooks corrigíveis e executa um único `check` ao final.

Em projetos com JavaScript/TypeScript detectado, ESLint e Prettier entram no mesmo pipeline e respeitam a ordem definida pelo plano.

No profile `glpi-plugin`, PHPStan e Psalm usam nível 8 e o host precisa ser GLPI 11 com versão identificável.

Configurações e hooks são gerados em workspace externo; o CLI não exige boilerplate Ninfa dentro do consumidor.
