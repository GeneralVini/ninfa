# Segurança

O Ninfa mantém segurança separada do pipeline comum de qualidade.

## Preparação das ferramentas

No repositório do Ninfa, execute:

```bash
make security-tools
```

Esse target instala o Semgrep no ambiente gerenciado do próprio Ninfa, em `.tools/semgrep`, sem alterar o projeto consumidor.

O OWASP ZAP continua opcional. Para instalá-lo também:

```bash
NINFA_INSTALL_ZAP=1 make security-tools
```

O comando `make setup` executa `security-tools`, valida sintaxe e roda a suíte interna do Ninfa.

## Comando

```bash
ninfa security /caminho/do/projeto
```

O baseline considera:

```text
Composer Audit        # quando houver composer.lock
Psalm Taint Analysis
Semgrep
OWASP ZAP             # somente opt-in
```

## Composer Audit

Executa `composer audit --locked --no-interaction` somente quando o projeto possui `composer.lock`. Projetos PHP genéricos sem Composer não falham apenas pela ausência desse recurso.

A consulta de advisories depende de conectividade. Um timeout de rede deve ser tratado como condição de infraestrutura, não como achado de vulnerabilidade.

## Psalm Taint

Reutiliza a configuração Psalm gerada no workspace externo e executa análise de taint sobre os paths detectados.

## Semgrep

Usa as regras do próprio Ninfa em `/opt/ninfa/security/semgrep.yml` e analisa somente os paths detectados do projeto alvo. Diretórios como `vendor` e `runtime` são excluídos.

A resolução do binário segue a política geral do Ninfa e reconhece também o binário gerenciado em:

```text
/opt/ninfa/.tools/semgrep/bin/semgrep
```

Se a ferramenta não estiver disponível, o Ninfa falha de forma explícita com orientação de instalação, sem despejar warning bruto de `proc_open()`.

## DAST / OWASP ZAP

DAST é opcional. Para habilitar em alvo local autorizado:

```bash
NINFA_DAST=1 \
NINFA_ZAP_TARGET=http://127.0.0.1:8080 \
ninfa security /caminho/do/projeto
```

O wrapper padrão recusa destinos que não sejam `localhost` ou `127.0.0.1`. O relatório gerado pelo pipeline é direcionado ao workspace externo do projeto, não ao consumidor.

## Princípio

Achados devem ser corrigidos ou tratados por regra específica e revisável. O MVP não deve criar exclusões globais apenas para silenciar a pipeline.
