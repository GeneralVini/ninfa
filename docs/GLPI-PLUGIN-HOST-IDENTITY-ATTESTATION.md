# EAP-01.01 — Verificação da identidade de checkout e host GLPI (somente leitura)

Este protocolo **não** comprova, por si só, a identidade dos ambientes. Deve ser executado por operador autorizado, em máquina com acesso de leitura aos checkouts e hosts. Não executar scanners, PHP da aplicação, `composer install`, GLPI CLI, migrations ou testes destrutivos nos originais. Não anexar saídas que contenham paths internos/segredos ao PR público.

## Referências já confirmadas via GitHub

| Consumidor | Fonte canônica | SHA da referência | Ambiente documentado — ainda não observado |
| --- | --- | --- | --- |
| SIGAPS GLPI | `GeneralVini/sigaps_glpi` | `d039aec352b45a89117fa7bf526562bc1a4645bc` | `/var/www/psglpi/plugins/sigaps`, host GLPI 11.0.8 conforme documentação do SIGAPS |
| ÉGIDE GLPI | `GeneralVini/egide` | `0c9d43105cee533537dccc8b3f5bddbf7adca81c` | GLPI 11.0.8 descrito como referência do ÉGIDE; sem checkout/host local inequívoco confirmado |

Fontes no commit fixado: SIGAPS `README.md`, `docs/infra.md`, `composer.json`, `setup.php` e `hook.php`; ÉGIDE `README.md`, `docs/ambientes.md`, `composer.json`, `setup.php`, `hook.php`. A documentação de ambientes ÉGIDE aponta a impossibilidade de homologação isolada na máquina descrita; nunca usar produção como substituta.

## Protocolo por consumidor (executar manualmente sem escrever no original)

Para cada checkout **já identificado e autorizado**, registrar em armazenamento privado:

1. `git -C "$PLUGIN_ROOT" rev-parse --show-toplevel`, `git -C "$PLUGIN_ROOT" remote -v`, `git -C "$PLUGIN_ROOT" rev-parse HEAD` e `git -C "$PLUGIN_ROOT" status --porcelain`. Comparar remote **e identidade do repositório**, não somente nome da pasta. Se a origem não for um checkout Git, registrar `NO_GIT_CHECKOUT`, comprovar procedência por artefato/hash e não declarar `REMOTE_MATCH`.
2. Inspecionar os arquivos `composer.json`, `setup.php`, `hook.php` **apenas como texto**, conferindo `type=glpi-plugin`, nome técnico e PSR-4. Registrar hashes SHA-256 e relação com os blobs do SHA fixado (considerando diferenças esperadas de checkout).
3. Resolver o host correspondente: `realpath` da raiz do host; verificar existência de `bin/console`, `src/Glpi/Plugin/Hooks.php`, `inc` e `composer.json`. Obter versão do host **de arquivos de metadata**, sem executar `bin/console` nem carregar autoload. Distinguir versão declarada, instalada e efetivamente verificada.
4. Confirmar permissões/isolamento operacional e registrar a identidade de execução dos futuros scanners; testes só em cópia isolada com original/host inacessíveis para escrita. Hash posterior não substitui controle preventivo de filesystem.
5. Guardar `checked_at` (ISO 8601 com offset), `consumer_sha`, `remote`, `host_version`, `host_identity`, `workspace_access_policy`, `operator`, evidência privada e conclusão `MATCH/DIFFERENT/UNKNOWN`. Não publicar endereços/paths sensíveis no repositório público.

## Critério de conclusão

Marcar `EAP-01.01 [x]` somente quando **ambos** os consumidores tiverem identidade do checkout/procedência efetiva, vínculo inequívoco à fonte GitHub, host e versão **observados**, data/hora e evidência privada revisável. Se o SHA local divergir da referência, registrar diferença e atualizar a baseline com aprovação; não forçar igualdade.

**Estado desta verificação:** somente fontes GitHub e hosts **documentados**; checkouts locais e hosts não foram acessados deste ambiente. `EAP-01.01` permanece `IN_PROGRESS`, `[ ]`, sem data de entrega. Este documento não deve ser interpretado como atestado de campo.
