# Instalação do Ninfa

## Adoção inicial

Clone o repositório Ninfa em um diretório auxiliar e execute `bin/ninfa-install.php`, informando a raiz do projeto PHP que receberá a esteira.

O instalador exige um `composer.json`, cria apenas arquivos ausentes e preserva configurações existentes. Arquivos preservados são sinalizados para revisão manual.

Depois da cópia estrutural, instale no projeto consumidor as dependências de desenvolvimento do ECS, Rector, PHPStan, Psalm e PHPUnit. Em seguida, use `scripts/merge-composer.php` com `composer.ninfa.example.json` para acrescentar os scripts Ninfa que ainda não existem no `composer.json`.

Finalize executando `make setup` e depois `composer check`.

## Máquina nova

Depois que o Ninfa já estiver integrado e versionado no projeto consumidor, basta clonar o próprio projeto, entrar na raiz e executar `make setup`. Não é necessário clonar o Ninfa novamente, porque scripts, configurações e workflow já fazem parte do repositório consumidor.

## Documentação existente

O Ninfa não substitui `README.md` nem documentos existentes em `docs/`. O instalador copia referências próprias para que o conteúdo necessário seja incorporado à documentação já utilizada pelo projeto.

## Segurança dinâmica

O DAST permanece separado do check comum e deve ser executado somente contra aplicação local autorizada. O wrapper padrão aceita localhost e 127.0.0.1.
