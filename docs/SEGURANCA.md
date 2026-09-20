# Segurança

O Ninfa mantém segurança separada do pipeline comum de qualidade. O escopo ativo é SCA + SAST; DAST foi retirado do pipeline público porque a análise dinâmica é atendida por uma frente institucional especializada.

## Preparação das ferramentas

No repositório do Ninfa, execute:

```bash
make security-tools
```

Esse target instala o Semgrep no ambiente gerenciado do próprio Ninfa, em `.tools/semgrep`, sem alterar o projeto consumidor.

OWASP ZAP não é mais instalado pelo fluxo oficial. Se `NINFA_INSTALL_ZAP=1` for informado, o instalador emite aviso e ignora a solicitação.

O comando `make setup` executa `security-tools`, valida sintaxe e roda a suíte interna do Ninfa.

## Comando

```bash
ninfa security /caminho/do/projeto
```

O baseline ativo considera:

```text
Composer Audit        # quando houver composer.lock
Psalm Taint Analysis
Semgrep
```

As etapas independentes continuam mesmo quando uma delas encontra um bloqueio ou falha. O resumo final distingue sucesso, falha, erro de execução e etapa ignorada; o exit code do comando preserva a primeira falha observada.

## Diretriz de maturidade

| Frente | Estado | Diretriz |
|---|---|---|
| SCA | baseline funcional | manter e estruturar melhor resultados e cobertura |
| SAST | MVP funcional / beta interna | prioridade de evolução |
| DAST | fora do pipeline | delegado; evolução congelada |

O foco do Ninfa é amadurecer SAST por profile, porque essa é a lacuna que o projeto pretende cobrir. Não é objetivo duplicar a operação de DAST já executada por outra frente.

## Composer Audit

Executa `composer audit --locked --no-interaction` somente quando o projeto possui `composer.lock`. Projetos PHP genéricos sem Composer não falham apenas pela ausência desse recurso.

A consulta de advisories depende de conectividade. Um timeout de rede deve ser tratado como condição de infraestrutura, não como achado de vulnerabilidade nem como evidência de ausência de vulnerabilidades.

## Psalm Taint

Reutiliza a configuração Psalm gerada no workspace externo e executa análise de taint sobre os paths detectados.

A evolução prevista é capturar o resultado em formato estruturado e normalizá-lo no mesmo modelo de `Finding` usado pelas demais análises do Ninfa.

## Semgrep

Usa as regras do próprio Ninfa em `/opt/ninfa/security/semgrep.yml` e analisa somente os paths detectados do projeto alvo. Diretórios como `vendor` e `runtime` são excluídos.

A resolução do binário segue a política geral do Ninfa e reconhece também o binário gerenciado em:

```text
/opt/ninfa/.tools/semgrep/bin/semgrep
```

Se a ferramenta não estiver disponível, o Ninfa falha de forma explícita com orientação de instalação, sem despejar warning bruto de `proc_open()`.

As regras atuais cobrem um baseline pequeno, incluindo execução direta de shell, `unserialize()` e alguns padrões de SQL concatenado. Controles positivos e negativos já comprovaram que essas regras carregam e bloqueiam exemplos simples, mas isso não representa cobertura SAST abrangente.

## Prioridades SAST

A evolução deve concentrar-se em:

1. normalizar Semgrep e Psalm Taint em `Finding`;
2. distinguir finding, erro de ferramenta, indisponibilidade e não aplicabilidade;
3. executar fixtures reais positivas e negativas no CI;
4. registrar cobertura efetiva de paths/arquivos;
5. evoluir regras específicas por profile (`glpi-plugin`, Yii e PHP genérico);
6. preservar regra, severidade, arquivo, linha, mensagem e proveniência;
7. aplicar quality gates somente depois que o modelo de resultado estiver estável.

Não é prioridade adicionar novos scanners antes de tornar confiáveis e auditáveis os resultados das ferramentas já adotadas.

## DAST / OWASP ZAP

DAST está desabilitado no pipeline público do Ninfa.

`ninfa security` não executa OWASP ZAP. Se `NINFA_DAST=1` for definido, o CLI apenas emite aviso de que a análise dinâmica foi delegada e continua com SCA/SAST.

O arquivo `scripts/zap-scan.sh` permanece no repositório como artefato congelado para referência ou eventual uso manual controlado. Ele não faz parte da interface pública, não é instalado por `make security-tools` e não integra o roadmap ativo.

## Interpretação do resultado

Um exit code 0 em `ninfa security` significa somente que as fontes SCA/SAST consultadas não produziram bloqueios no escopo executado. Não significa “sistema seguro”, “sem vulnerabilidades”, “cobertura completa” ou aprovação por todos os controles de segurança.

## Princípio

Achados devem ser corrigidos ou tratados por regra específica e revisável. O MVP não deve criar exclusões globais apenas para silenciar a pipeline.
