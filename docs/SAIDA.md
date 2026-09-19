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
! dast: skipped (opcional desabilitada)
```

Uma falha nao impede etapas independentes de executar. O comando retorna o
primeiro codigo diferente de zero para preservar compatibilidade. Etapas
opcionais desabilitadas ou nao aplicaveis aparecem como `skipped`.
