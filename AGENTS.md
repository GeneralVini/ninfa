@RTK.md

# Premissas de manutenção do Ninfa

A documentação interna é parte do código e deve ser atualizada no mesmo commit da implementação.

## PHP

- Toda classe de produção precisa de PHPDoc com responsabilidade e limites.
- Todo método ou função nomeada de produção, inclusive privados, precisa de PHPDoc descritivo.
- `@param`, `@return` e `@var` devem preservar informação que o type hint nativo não expressa.
- Arrays conhecidos não podem ficar semanticamente opacos: use `list<T>`, `array<K,V>` ou array shapes quando o formato for conhecido.
- Acumuladores, payloads, respostas JSON, mapas e filas devem receber `@var` quando isso preservar tipo ou semântica relevante.
- Blocos de controle relevantes devem explicar a decisão, precedência, fallback, política de erro ou invariante que protegem; não apenas narrar a sintaxe.

## Shell

Shell não possui PHPDoc/JSDoc nativo, portanto o Ninfa usa comentários estruturados e verificáveis:

- cabeçalho com propósito, entradas, efeitos externos e condições de falha;
- `# @var NOME tipo — descrição` para variáveis de script relevantes;
- `# @function nome — contrato/efeitos` para funções nomeadas;
- comentário de intenção imediatamente antes de `if`, `case`, `for`, `while`, `until` e outros blocos semânticos relevantes;
- comentários devem explicar o motivo/invariante, não traduzir a condição.

## JavaScript

Quando houver JavaScript próprio do Ninfa, módulos e funções nomeadas devem usar JSDoc. Tipos compostos devem usar typedefs/shapes em vez de `Object` genérico quando o formato for conhecido.

## Regra de mudança

Arquivo novo ou alterado não deve aumentar a dívida documental. Antes de concluir uma mudança, execute `make syntax` e `make profile-test`; os guards de documentação fazem parte da suíte.
