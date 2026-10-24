# Ferramentas de segurança nativas

O ambiente pertence ao Ninfa, fora do consumidor. Instalação explícita não executa auditoria:

```sh
cd /opt/ninfa
make web-security-tools
make web-security-doctor
make security-contract-test
```

Para validar uma instalação limpa, use outra raiz com sufixo `.tools`, coerente com ToolResolver:

```sh
python3 scripts/web-security-tools.py install --tools-dir /tmp/ninfa-clean/.tools
python3 scripts/web-security-tools.py doctor --tools-dir /tmp/ninfa-clean/.tools --project /caminho/do/consumidor --network
```

`--project` é sempre o caminho real do consumidor. A raiz de ferramentas é resolvida antes da verificação de isolamento: links simbólicos que terminam dentro do consumidor também são recusados. O diagnóstico só resolve ferramentas/consulta disponibilidade; não escreve no consumidor, não analisa código e não inicia scan web. `--network` consulta OSV com uma coordenada pública; sem essa opção, disponibilidade OSV é desconhecida. Falha de uma fonte não oculta as demais. Exit zero do doctor significa requisitos diagnosticados saudáveis, não auditoria completa; `full_web_ready=false` enquanto a coordenação DAST SEC-05/06 não estiver implementada.

## Pins, integridade e atualização

[web-tools.lock.json](../security/web-tools.lock.json) fixa ZAP 2.17.0, Nuclei 3.11.1, Psalm 6.20.1, Semgrep 1.177.0, Playwright 1.64.1 e revisão de templates `f8b8b8dcd70d93826c3767e1829ef8243af9f03b` (10.5.0). Downloads ZAP/Nuclei/Psalm têm SHA-256 corroborado pela API de releases oficiais. Templates são obtidos pelo commit exato via fetch raso e verificados arquivo a arquivo. ZAP inclui os add-ons da distribuição: todos os bytes estão fixados, incluindo reports 0.43.0; não instalar/atualizar add-ons no meio da auditoria.

Playwright usa [package-lock com SRI](../security/browser/package-lock.json), apenas Chromium 156.0.8078.4/revisão 1248 (browser e headless shell, ffmpeg 1013). Firefox não é instalado. Pacotes npm são instalados sem scripts e verificados antes de executar o instalador Chromium; payload do browser é conferido antes do probe. Playwright será o navegador dos fluxos controlados do Ninfa. Não substitui WebDriver/Selenium nos add-ons ZAP que exigem esse protocolo; esses recursos não estão habilitados/validados por esta instalação. O relatório ZAP passivo da fixture não requer navegador.

Semgrep permanece em venv privado; [semgrep-wheels.lock.json](../security/semgrep-wheels.lock.json) fixa as 67 distribuições transitivas com hashes de wheels publicados no PyPI. Sem builds de source, pacotes não fixados ou scripts do consumidor. A baseline de instalação é Linux x86_64/Python 3.14. Paths de launchers gerados são normalizados para permitir relocação; registros pip RECORD são comparados em ordem canônica, sem linhas REQUESTED nem hashes dos launchers gerados (estes são verificados separadamente). Payload não gerado permanece idêntico. Links de cache Chromium `.links` e bytecode Python são metadados gerados, excluídos da identidade do payload.

Os runtimes do host (incluindo Python 3.14.4 e npm) são dependências externas explicitamente diagnosticadas: Java 22.0.2 observado (ZAP exige >=17), PHP 8.5.4 observado (Psalm requer >=8.2 e ctype/dom/json/libxml/mbstring/SimpleXML/tokenizer), Node 24.16.0 observado (Playwright requer >=20) e Composer 2.9.5. Seus executáveis possuem SHA-256 da baseline; mudança exige revisão do manifesto antes de probes, sem instalar pacotes do sistema indiscriminadamente. Isso é um pin de ambiente deste host, não um pacote portátil desses runtimes. Falta de extensão PHP aparece no doctor como requisito de Psalm indisponível.

Atualização é manutenção explícita: revisar origem oficial, versão, hashes e compatibilidade; regenerar locks com evidência; instalar em nova raiz; repetir contratos/capturas/testes; revisar o diff antes de substituir ambiente válido. O instalador não sobrescreve instalação divergente. Downloads são temporários, verificados antes da extração; traversal/links de arquivo são rejeitados; trava serializa publicação e rename só ocorre após validação. Falha mantém a instalação anterior e remove staging.

## Resolução e diagnóstico

O bridge PHP usa o próprio `ToolResolver`: consumidor node_modules/.bin, consumidor vendor/bin, Ninfa .tools, dependências Ninfa e PATH. Um override do consumidor mantém a precedência, mas não é executado pelo doctor sem digest confiável no contrato; fica indisponível com causa explícita. Nunca contornar override silenciosamente para simular saúde. O probe ZAP usa perfil temporário exclusivo, removido ao terminar; uma instância já aberta no perfil padrão do usuário não bloqueia o diagnóstico. Campos individuais informam path, versão, integridade, causa e efeito na capacidade. Manifesto distingue pins de upstream e snapshots do host.

O instalador Semgrep legado (`make security-tools`) continua disponível para compatibilidade; o caminho reproduzível de SEC-02 é `make web-security-tools`. ZAP/Nuclei não são ativados por instalar. Na integração futura, Nuclei deve receber `-duc -ni`, config isolada e lista exata de templates HTTP selecionados; nenhum scan amplo, templates code/headless/DAST, OAST ou atualização automática por padrão. A fixture usa exclusivamente `contract.yaml` controlado, sem varrer os templates instalados.

Fontes: [ZAP/Java](https://www.zaproxy.org/download/), [Nuclei 3.11.1](https://github.com/projectdiscovery/nuclei/releases/tag/v3.11.1), [Psalm instalação](https://psalm.dev/docs/running_psalm/installation/), [Playwright browsers](https://playwright.dev/docs/browsers). As provas de instalação/diagnóstico desta rodada estão no registro de execução vinculado à EAP.
