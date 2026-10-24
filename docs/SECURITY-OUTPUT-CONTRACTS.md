# Contratos das saídas — SEC-03

As capturas em [tests/fixtures/security-outputs](../tests/fixtures/security-outputs) são reais, sanitizadas e acompanhadas de `*.capture.json`: versão, horário UTC real, retorno nativo (null para API sem processo por requisição), configuração/hash, transformações, limitações e SHA-256 dos bytes finais. Os testes de falhas são sintéticos e estão identificados nos arquivos de teste. O coletor `scripts/capture-security-contracts.py` usa somente loopback descartável e consulta pública OSV com coordenadas controladas; não analisa consumidores reais.

| Fonte fixada | Entrada/saída e retorno | Cobertura e limite |
| --- | --- | --- |
| Composer 2.9.5 | `audit --locked --format=json --no-plugins --no-scripts`; stdout objeto `advisories`, `abandoned`; stderr separado; 0 limpo, 1 advisory, 2 abandono, 3 ambos. Captura positiva 3. `abandoned` pode ter substituto string ou null; true legado tolerado, false rejeitado. | Inventário original lock versus installed.json preservado. Sem lock não representar resolução original completa. Advisory/abandono separados. JSON inválido ou código inesperado é falha operacional; findings válidos permanecem. |
| OSV HTTP v1 | POST querybatch ordenado por consulta, GET vulns/id e paginação por componente; API não tem versão de binário nem exit code. Captura batch+registro completo real, timestamps observados. | Faixas, aliases quando presentes, referências e proveniência mantidos; advisory sem aliases não recebe aliases inventados. Withdrawn omitido; ID divergente, cardinalidade, token repetido/tipo inválido e transporte falho rejeitados. Inventário limita consulta, não prova alcançabilidade. |
| Semgrep 1.177.0 | stdout JSON results/errors/paths/skipped_rules; stderr separado. Sem `--error`, matches podem retornar 0 (captura). Outros retornos permanecem nativos no runner. | Scanned ausente/inválido, errors e skipped_rules significam parcialidade. Exclusões deliberadas registradas; reason desconhecido continua parcial. Start/end, metadata, dataflow quando disponível. Severidade nativa não confirma exploração. |
| Psalm 6.20.1 | Lista JSON de issues; tipo, file_path, linhas/colunas, shortcode, taint_trace. Retorno 2 com issues é válido; captura real 2. Código 2 vazio ou outro não zero é falha. | Tainted* é fluxo; diagnóstico comum é categoria distinta. Trace pode faltar. JSON não comprova todos os caminhos de execução; confiança não significa confirmação. |
| ZAP 2.17.0 + reports 0.43.0 | Relatório traditional-json (`site[].alerts[].instances[]`) e envelope API alerts. Captura obtida de daemon real, uma URL, fila passiva drenada, sem spider/ativo. | Plugin/alertRef, risco/confiança nativos, URI/método/parâmetro e instâncias preservados. Parser retorna coverage unknown mesmo com relatório vazio. Sessão/jobs/crawl exigem observação independente na futura integração. API não tem exit code por relatório. |
| Nuclei 3.11.1 | stdout JSONL de matches, logs/stats separados. Captura com um template HTTP controlado: exit0 com match. Atualizações/OAST desabilitados e concorrência/taxa limitadas. | Template/path, matcher/extractor, alvo, classificação, referências e extrações disponíveis preservados. Request/response/curl/template-encoded removidos. Vazio não prova que templates foram executados. Match não é exploração confirmada. |

Os parsers DAST são isolados: não participam ainda do CLI público. Exceções de processo não viram eventos fictícios, nem são confundidas com zero findings. Capturas não demonstram cobertura de aplicações de produção ou capacidades de autenticação/ativo.

## Evidências e privacidade

`EvidenceSanitizer` atua recursivamente sobre JSON e nas mensagens antes do terminal/relatório. Remove headers, cookies, credenciais identificadas, snippets e payloads opacos; redige userinfo/query values e e-mails conhecidos. Texto arbitrário pode conter dados pessoais sem padrões reconhecíveis: revisão humana continua necessária antes de publicar. Não há promessa de anonimização universal por regex.

`EvidenceStore` usa diretório 0700 e arquivo 0600, rejeita ancestrais simbólicos, publica por rename de temporário no mesmo diretório e limpa temporários em erro. Referência relativa aleatória + SHA-256 correspondem aos bytes do envelope gravado; o envelope também registra digest do payload sanitizado. Raw está desabilitado por padrão, exige habilitação explícita na instância da execução e expira em 24 horas; sanitizado expira em 30 dias. Construtor limpa expirados. Limpeza sem nova captura: `php scripts/cleanup-security-evidence.php /diretorio/da/store`; agendar diariamente pelo operador. O comando só remove o namespace de envelopes da store, nunca arquivos arbitrários.

Inventário e relatório atuais usam gravação atômica privada e sanitização, preservando o schema 3; snapshots nomeados expiram em 30 dias, na abertura do workspace ou pelo mesmo comando de limpeza. Integração de referências por execução/manifestações completas no pipeline segue SEC-06; MD/PDF seguem SEC-08. Fixtures versionadas têm retenção de contrato e revisão de privacidade no controle de versão; não são raw operacional.

## Fontes oficiais confrontadas

- [Composer Auditor 2.9.5](https://github.com/composer/composer/blob/2.9.5/src/Composer/Advisory/Auditor.php).
- [OSV querybatch](https://google.github.io/osv.dev/post-v1-querybatch/) e [schema OSV](https://ossf.github.io/osv-schema/).
- [Semgrep JSON](https://docs.semgrep.dev/semgrep-appsec-platform/json-and-sarif) e [tipos de saída](https://github.com/semgrep/semgrep-interfaces/blob/main/semgrep_output_v1.atd).
- [Psalm IssueData 6.20.1](https://github.com/vimeo/psalm/blob/6.20.1/src/Psalm/Internal/Analyzer/IssueData.php) e [IssueBuffer](https://github.com/vimeo/psalm/blob/6.20.1/src/Psalm/IssueBuffer.php).
- [ZAP traditional JSON](https://www.zaproxy.org/docs/desktop/addons/report-generation/report-traditional-json/) e [API](https://www.zaproxy.org/docs/api/).
- [Nuclei ResultEvent 3.11.1](https://github.com/projectdiscovery/nuclei/blob/v3.11.1/pkg/output/output.go).
