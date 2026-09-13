# Instalação do Ninfa

## Adoção inicial

Clone o repositório Ninfa em um diretório auxiliar e execute `bin/ninfa-install.php`, informando a raiz do projeto PHP que receberá a esteira.

O instalador exige um `composer.json` e usa `composer.json`, filesystem, `README.md` e `docs/*.md` para identificar o contexto do projeto.

Com os caminhos detectados, o Ninfa gera automaticamente, quando ainda não existirem:

```text
ecs.php
rector.php
phpstan.neon.dist
psalm.xml
phpunit.xml.dist
```

As configurações existentes são preservadas. O Semgrep usa os caminhos gravados em `.ninfa/paths.txt`.

Depois da instalação estrutural, instale no projeto consumidor as dependências de desenvolvimento do ECS, Rector, PHPStan, Psalm e PHPUnit. Em seguida, use `scripts/merge-composer.php` com `composer.ninfa.example.json` para acrescentar os scripts Ninfa que ainda não existem no `composer.json`.

Finalize com:

```bash
make install
make setup
composer check
```

`make install` instala primeiro as dependências Composer e então refaz a descoberta contextual. Essa ordem permite detectar extensões de análise estática instaladas no próprio projeto.

## Máquina nova

Depois que o Ninfa já estiver integrado e versionado no projeto consumidor, basta clonar o próprio projeto, entrar na raiz e executar:

```bash
make install
make setup
composer check
```

Não é necessário clonar o Ninfa novamente, porque scripts, configurações e workflow já fazem parte do repositório consumidor.

## Quando há revisão manual

A revisão manual fica restrita a divergências entre documentação e `composer.json`, estruturas não convencionais, configurações maduras já existentes ou diretórios especiais que devam ser excluídos da análise.

## Documentação existente

O Ninfa não substitui `README.md` nem documentos existentes em `docs/`. O instalador copia referências próprias para que o conteúdo necessário seja incorporado à documentação já utilizada pelo projeto.

## Segurança dinâmica

O DAST permanece separado do check comum e deve ser executado somente contra aplicação local autorizada. O wrapper padrão aceita localhost e 127.0.0.1.
