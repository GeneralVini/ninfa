# Saída do CLI

O padrão visual do Ninfa usa quatro sinais semânticos:

- `✓` verde: sucesso ou condição íntegra;
- `✗` vermelho: bloqueio ou erro;
- `!` amarelo: atenção, hotspot ou cobertura parcial;
- `i` ciano: informação/contexto.

Arquivos, regras e mensagens permanecem sem cor própria para reduzir ruído. A legenda curta aparece uma vez por execução. A política global é `NINFA_COLOR=auto`, com `always`, `never` e precedência de `NO_COLOR` conforme [CORES.md](CORES.md).

Ao final, o pipeline apresenta resumo consolidado das etapas:

```text
[NINFA] Resumo (security)
✗ composer-audit: failed (codigo 1)
✓ osv: ok (codigo 0)
✓ psalm-taint: ok (codigo 0)
✓ semgrep: ok (codigo 0)
```

Durante a etapa Semgrep, o Ninfa também apresenta evidência operacional de cobertura:

```text
i Semgrep — arquivos analisados: 72
i Semgrep — exclusões por política: 1
✓ Semgrep — skips inesperados: 0
✓ Semgrep — erros do mecanismo: 0
```

Findings `ERROR` do Semgrep bloqueiam a etapa. Findings `WARNING` são renderizados como hotspots e não transformam sozinhos a etapa em falha. Cobertura parcial inesperada ou erro do mecanismo permanece bloqueante.

Uma falha não impede etapas independentes de executar. O comando retorna o primeiro código diferente de zero para preservar a ordem do pipeline. Estados conhecidos podem aparecer como `not_applicable`, `unavailable`, `partial` ou `skipped`, conforme o motivo real; eles não são colapsados em um único rótulo.

DAST não aparece como etapa do `security`, pois está delegado à frente especializada externa. Se `NINFA_DAST=1` for informado, o aviso é emitido em `stderr`, sem execução do OWASP ZAP.

O hook `test` prioriza o script `test` declarado no `composer.json`. Na ausência dele, usa PHPUnit com `--fail-on-empty-test-suite`. Uma ferramenta que retorne sucesso informando que nenhum teste foi executado é convertida em falha.
