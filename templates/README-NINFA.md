## Qualidade e segurança — Ninfa

Este projeto utiliza o **Ninfa** como baseline de qualidade, compliance de código e segurança.

Validação completa:

```bash
composer check
```

Correções automáticas disponíveis:

```bash
composer fix
```

Validação somente de qualidade:

```bash
composer qa
```

Validação somente de segurança:

```bash
composer security
```

Com a aplicação local em execução, o DAST pode ser executado separadamente:

```bash
NINFA_ZAP_TARGET=http://127.0.0.1:8080 composer security:dast
```

A esteira utiliza ECS, Rector, PHPStan, Psalm, PHPUnit, Composer Audit, Psalm Taint Analysis, Semgrep CE e OWASP ZAP.

Consulte `docs/NINFA.md` para instalação, comandos, customizações e critérios adotados pelo projeto.
