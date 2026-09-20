# Saída do CLI

O padrão visual do Ninfa usa quatro estados:

- `✓` verde: sucesso.
- `✗` vermelho: bloqueio ou erro.
- `!` amarelo: atenção.
- `i` ciano: informação.

Arquivos, regras e mensagens permanecem sem cor própria para reduzir ruído visual. A legenda curta aparece uma vez por execução do pipeline.

Ao final, o pipeline apresenta um resumo consolidado de todas as etapas planejadas:

```text
[NINFA] Resumo (security)
✗ composer-audit: failed (codigo 1)
✓ psalm-taint: ok (codigo 0)
✓ semgrep: ok (codigo 0)
```

Uma falha nao impede etapas independentes de executar. O comando retorna o
primeiro codigo diferente de zero para preservar compatibilidade. Etapas nao
aplicaveis aparecem como `skipped`.

DAST não aparece no resumo do `security`, pois está desabilitado e delegado à
frente especializada externa. Se `NINFA_DAST=1` for informado, o aviso é emitido
em `stderr`, sem execução do OWASP ZAP.

O hook `test` prioriza o script `test` declarado no `composer.json`. Quando ele
nao existe, usa PHPUnit com `--fail-on-empty-test-suite`. Uma ferramenta que
retorne sucesso informando que nenhum teste foi executado e convertida em falha.
