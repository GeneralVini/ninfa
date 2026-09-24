# Cores do CLI

O Ninfa usa uma única política visual para todos os comandos, inclusive `security`:

- `✓` verde: sucesso ou cobertura íntegra;
- `✗` vermelho: bloqueio, vulnerabilidade/finding bloqueante ou erro do mecanismo;
- `!` amarelo: atenção, hotspot, cobertura parcial ou correção manual;
- `i` ciano: informação de fluxo, profile, cobertura e contexto.

A cor reforça símbolos e rótulos; o significado nunca depende apenas da cor. Arquivos, regras e mensagens ficam neutros para evitar uma saída excessivamente colorida.

A legenda curta aparece uma vez por execução do pipeline. O modo padrão permanece:

```bash
NINFA_COLOR=auto
```

Também são aceitos:

```bash
NINFA_COLOR=always
NINFA_COLOR=never
```

`auto` habilita ANSI somente quando stdout é TTY. Quando `NO_COLOR` estiver definida, as cores são desativadas independentemente de `NINFA_COLOR`.

Não existem variáveis de cor específicas para Semgrep, SAST ou outro scanner. Toda saída humana reutiliza `CliStyle` e a política acima.

No resumo de segurança, a semântica esperada é:

```text
✓ APROVADO
! APROVADO COM HOTSPOTS
✗ REPROVADO
✗ ERRO / COBERTURA PARCIAL
```

`WARNING` do Semgrep é hotspot para revisão e não bloqueia sozinho o gate. `ERROR`, falha do motor ou cobertura inesperadamente parcial permanecem bloqueantes.
