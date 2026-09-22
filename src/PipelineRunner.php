<?php

declare(strict_types=1);

require_once __DIR__ . '/ProcessRunner.php';
require_once __DIR__ . '/PipelinePlan.php';
require_once __DIR__ . '/ExternalConfigGenerator.php';
require_once __DIR__ . '/ToolResolver.php';
require_once __DIR__ . '/RunResult.php';
require_once __DIR__ . '/SecurityInventory.php';
require_once __DIR__ . '/ComposerAuditParser.php';
require_once __DIR__ . '/OsvClient.php';
require_once __DIR__ . '/SecurityReport.php';
require_once __DIR__ . '/PsalmTaintParser.php';
require_once __DIR__ . '/SemgrepParser.php';
require_once __DIR__ . '/SecurityContract.php';

/**
 * Orquestra as operações `check`, `fix` e `security` sobre um ProjectContext.
 *
 * O runner gera configurações externas, obtém o PipelinePlan, resolve e
 * executa as etapas sem fail-fast e converte cada resultado em ToolResult. O
 * primeiro exit code não zero observado é preservado como exit code final.
 *
 * Em `security`, cria e grava SecurityInventory antes dos scanners, executa
 * OSV pelo cliente interno e, ao final, grava `security-report.json`. Composer
 * Audit já é capturado em JSON; Psalm Taint e Semgrep ainda seguem o caminho
 * de processo não estruturado nesta etapa do projeto.
 *
 * A variável NINFA_DAST apenas gera aviso: esta classe não executa ZAP. O
 * runner também não aplica o recheck posterior ao `fix`; essa política fica em
 * RecheckingPipelineRunner.
 */
final class PipelineRunner
{
    /** Evita repetir a legenda visual quando `fix` é seguido por recheck na mesma execução PHP. */
    private static bool $legendShown = false;

    /**
     * Recebe as dependências de execução para permitir substituição determinística em testes.
     *
     * @param ProcessRunner $processRunner Executor de processos externos.
     * @param PipelinePlan $plan Planejador declarativo das etapas.
     * @param ExternalConfigGenerator $configGenerator Gerador de configs externas.
     * @param ToolResolver $toolResolver Resolvedor de executáveis.
     * @param OsvClient $osvClient Cliente SCA interno que não usa processo externo.
     */
    public function __construct(
        private readonly ProcessRunner $processRunner = new ProcessRunner(),
        private readonly PipelinePlan $plan = new PipelinePlan(),
        private readonly ExternalConfigGenerator $configGenerator = new ExternalConfigGenerator(),
        private readonly ToolResolver $toolResolver = new ToolResolver(),
        private readonly OsvClient $osvClient = new OsvClient(),
    ) {
    }

    /**
     * Executa uma operação e devolve somente seu exit code consolidado.
     *
     * O método é a interface simples usada pelo CLI/RecheckingPipelineRunner;
     * todos os detalhes estruturados permanecem disponíveis em `runResult()`.
     *
     * @param string $operation Operação pública `check`, `fix` ou `security`.
     * @param ProjectContext $context Contexto validado do consumidor.
     * @return int Primeiro exit code não zero observado, ou 0.
     */
    public function run(string $operation, ProjectContext $context): int
    {
        return $this->runResult($operation, $context)->finalExitCode;
    }

    /**
     * Executa o plano completo e retorna resultados estruturados por etapa.
     *
     * A execução é deliberadamente não fail-fast: erros/falhas são registrados
     * e as etapas seguintes continuam quando possível. Em `security`, inventário
     * é criado antes dos scanners; OSV roda via cliente interno; o relatório é
     * escrito depois de todas as etapas. Erro na gravação do relatório vira um
     * ToolResult adicional e altera o exit code consolidado.
     *
     * @param string $operation Operação pública a executar.
     * @param ProjectContext $context Contexto imutável que delimita raiz/profile/workspace.
     * @return RunResult Snapshot final da execução, incluindo erros e findings observados.
     * @throws InvalidArgumentException Quando a operação não é suportada.
     */
    public function runResult(string $operation, ProjectContext $context): RunResult
    {
        $this->showLegend();

        // DAST é explicitamente delegado: variável legada gera aviso, nunca scan.
        if ($operation === 'security' && $this->dastRequested()) {
            fwrite(
                STDERR,
                CliStyle::warning(
                    '! DAST está desabilitado no Ninfa; a análise dinâmica é delegada à frente especializada externa.',
                ) . PHP_EOL,
            );
        }

        $securityInventory = null;
        if ($operation === 'security') {
            $securityInventory = SecurityInventory::fromContext($context);
            $inventoryFile = $this->writeSecurityInventory($context, $securityInventory);
            echo CliStyle::info('[NINFA] Inventário de segurança: ' . $inventoryFile) . PHP_EOL;
        }

        /** @var array<string,string> $configs Configurações externas geradas para esta execução. */
        $configs = $this->configGenerator->generate($context);
        /** @var list<array<string,mixed>> $hooks Plano em ordem de execução. */
        $hooks = match ($operation) {
            'check' => $this->plan->check($context),
            'fix' => $this->plan->fix($context),
            'security' => $this->plan->security($context),
            default => throw new InvalidArgumentException('Operacao invalida: ' . $operation),
        };

        /** @var list<ToolResult> $results Resultados acumulados sem fail-fast. */
        $results = [];
        foreach ($hooks as $hook) {
            $started = hrtime(true);

            // OSV é um adapter HTTP interno e portanto não passa por commandFor/ProcessRunner.
            if ($operation === 'security' && $hook['id'] === 'osv') {
                if (!$securityInventory instanceof SecurityInventory) {
                    $results[] = ToolResult::error('osv', 'Inventário de segurança ausente.', $this->elapsedMs($started));
                    continue;
                }

                $packages = $securityInventory->toArray()['composer']['packages'] ?? [];
                if (!is_array($packages) || $packages === []) {
                    echo CliStyle::warning('! osv: sem packages Composer resolvidos para consulta.') . PHP_EOL;
                    $results[] = ToolResult::notApplicable('osv', 'sem packages Composer resolvidos');
                    continue;
                }

                echo CliStyle::info('[NINFA] osv') . ' (' . $hook['mode'] . ')' . PHP_EOL;
                try {
                    [$status, $findings] = $this->runOsv($securityInventory);
                } catch (Throwable $error) {
                    fwrite(STDERR, CliStyle::error('✗ osv: ' . $error->getMessage()) . PHP_EOL);
                    $results[] = ToolResult::error('osv', $error->getMessage(), $this->elapsedMs($started));
                    continue;
                }

                $results[] = ToolResult::completed('osv', $status, $findings, $this->elapsedMs($started));
                continue;
            }

            // Falha ao resolver/montar comando é erro de etapa, não motivo para abortar o restante do plano.
            try {
                $command = $this->commandFor($hook['id'], $hook['mode'], $context, $configs);
            } catch (ToolUnavailableException $error) {
                fwrite(STDERR, CliStyle::warning('! ' . $hook['id'] . ': ' . $error->getMessage()) . PHP_EOL);
                $results[] = ToolResult::unavailable($hook['id'], $error->getMessage(), $this->elapsedMs($started));
                continue;
            } catch (Throwable $error) {
                fwrite(STDERR, CliStyle::error('✗ ' . $hook['id'] . ': ' . $error->getMessage()) . PHP_EOL);
                $results[] = ToolResult::error($hook['id'], $error->getMessage(), $this->elapsedMs($started));
                continue;
            }

            // null significa etapa conhecida mas não aplicável (por exemplo composer-audit sem lock/test sem runner).
            if ($command === null) {
                $results[] = ToolResult::notApplicable($hook['id'], 'nao aplicavel');
                continue;
            }

            echo CliStyle::info('[NINFA] ' . $hook['id']) . ' (' . $hook['mode'] . ')' . PHP_EOL;
            $structured = ($operation === 'check' && in_array($hook['id'], ['phpstan', 'psalm'], true))
                || ($operation === 'security' && in_array($hook['id'], ['composer-audit', 'psalm-taint', 'semgrep'], true));
            /** @var list<Finding> $findings Findings produzidos pela etapa atual quando estruturada. */
            $findings = [];

            try {
                $toolResult = null;
                if ($operation === 'security' && in_array($hook['id'], ['psalm-taint', 'semgrep'], true)) {
                    $toolResult = $this->runSast($hook['id'], $command, $context, $started);
                    $status = $toolResult->exitCode ?? 1;
                    $findings = $toolResult->findings;
                } elseif ($operation === 'security' && $hook['id'] === 'composer-audit') {
                    if (!$securityInventory instanceof SecurityInventory) {
                        throw new LogicException('Inventário de segurança ausente para Composer Audit.');
                    }
                    [$status, $findings] = $this->runComposerAudit($command, $context, $securityInventory);
                } elseif ($structured) {
                    [$status, $findings] = $this->runStaticAnalysis($hook['id'], $command, $context);
                } else {
                    $status = $hook['id'] === 'test'
                        ? $this->runTests($command, $context)
                        : $this->processRunner->run($command, $context->root());
                }
            } catch (Throwable $error) {
                fwrite(STDERR, CliStyle::error('✗ ' . $hook['id'] . ': ' . $error->getMessage()) . PHP_EOL);
                $results[] = ToolResult::error($hook['id'], $error->getMessage(), $this->elapsedMs($started));
                continue;
            }

            // Etapas estruturadas renderizam seu próprio diagnóstico com contagem de findings.
            if (!$structured) {
                echo $status === 0
                    ? CliStyle::success('✓ ' . $hook['id'] . ': concluido.') . PHP_EOL
                    : CliStyle::error('✗ ' . $hook['id'] . ': falhou (codigo ' . $status . ').') . PHP_EOL;
            }

            $results[] = $toolResult ?? ToolResult::completed(
                $hook['id'], $status, $findings, $this->elapsedMs($started),
            );
        }

        $runResult = new RunResult($operation, $results);

        // O relatório SCA é efeito final do security e sua falha precisa ficar explícita no próprio RunResult.
        if ($operation === 'security' && $securityInventory instanceof SecurityInventory) {
            try {
                $reportFile = $this->writeSecurityReport($context, $securityInventory, $runResult);
                echo CliStyle::info('[NINFA] Relatório de segurança: ' . $reportFile) . PHP_EOL;
            } catch (Throwable $error) {
                fwrite(STDERR, CliStyle::error('✗ security-report: ' . $error->getMessage()) . PHP_EOL);
                $results[] = ToolResult::error('security-report', $error->getMessage());
                $runResult = new RunResult($operation, $results);
            }
        }

        $this->renderSummary($operation, $results);

        return $runResult;
    }

    /**
     * Renderiza o estado final de cada etapa sem recomputar findings/exit codes.
     *
     * @param string $operation Operação usada no título do resumo.
     * @param list<ToolResult> $results Resultados na mesma ordem do plano executado.
     */
    private function renderSummary(string $operation, array $results): void
    {
        echo PHP_EOL . CliStyle::info('[NINFA] Resumo (' . $operation . ')') . PHP_EOL;

        foreach ($results as $result) {
            $line = match ($result->state) {
                ToolResult::OK => CliStyle::success('✓ ' . $result->id . ': ok (codigo 0)'),
                ToolResult::FAILED => CliStyle::error(
                    '✗ ' . $result->id . ': failed (codigo ' . $result->exitCode . ')',
                ),
                ToolResult::ERROR => CliStyle::error(
                    '✗ ' . $result->id . ': error (codigo ' . $result->exitCode . ')',
                ),
                ToolResult::SKIPPED => CliStyle::warning(
                    '! ' . $result->id . ': skipped (' . $result->detail . ')',
                ),
                ToolResult::NOT_APPLICABLE => CliStyle::warning(
                    '! ' . $result->id . ': not_applicable (' . $result->detail . ')',
                ),
                ToolResult::UNAVAILABLE => CliStyle::warning(
                    '! ' . $result->id . ': unavailable (' . $result->detail . ')',
                ),
                ToolResult::PARTIAL => CliStyle::warning(
                    '! ' . $result->id . ': partial (' . $result->detail . ')',
                ),
                default => throw new LogicException('Estado de etapa invalido: ' . $result->state),
            };
            echo $line . PHP_EOL;
        }
    }

    /**
     * Traduz um hook do plano para o comando externo concreto da ferramenta.
     *
     * null representa etapa não aplicável. A função apenas monta argumentos e
     * resolve binários; não inicia processos. Composer Audit é endurecido com
     * `--no-plugins --no-scripts --no-interaction` e JSON estruturado.
     *
     * @param string $id Identificador da etapa no plano.
     * @param string $mode Modo declarativo (`check`, `fix` ou `dry-run`).
     * @param ProjectContext $context Contexto usado para paths/binários/profile.
     * @param array<string,string> $configs Arquivos de configuração externos gerados.
     * @return list<string>|null Executável + argumentos, ou null quando a etapa não se aplica.
     */
    private function commandFor(string $id, string $mode, ProjectContext $context, array $configs): ?array
    {
        return match ($id) {
            'ecs' => [$this->tool('ecs', $context), 'check', '--config', $configs['ecs'], ...($mode === 'fix' ? ['--fix'] : [])],
            'rector' => [$this->tool('rector', $context), 'process', '--config', $configs['rector'], '--no-progress-bar', ...($mode === 'dry-run' ? ['--dry-run'] : [])],
            'phpstan' => [$this->tool('phpstan', $context), 'analyse', '--configuration', $configs['phpstan'], '--error-format=json', '--no-progress'],
            'psalm' => [$this->tool('psalm', $context), '--config=' . $configs['psalm'], '--output-format=json', '--no-progress'],
            'psalm-taint' => [$this->tool('psalm', $context), '--config=' . $configs['psalm'], '--taint-analysis', '--output-format=json', '--no-progress'],
            'eslint' => [$this->tool('eslint', $context), '.', ...($mode === 'fix' ? ['--fix'] : [])],
            'prettier' => [$this->tool('prettier', $context), $mode === 'fix' ? '--write' : '--check', '.'],
            'test' => $this->testCommand($context),
            'composer-audit' => is_file($context->root() . '/composer.lock')
                ? [
                    $this->tool('composer', $context),
                    '--no-plugins',
                    '--no-scripts',
                    '--no-interaction',
                    'audit',
                    '--locked',
                    '--format=json',
                ]
                : null,
            'semgrep' => $this->semgrepCommand($context),
            default => null,
        };
    }

    /**
     * Executa PHPStan/Psalm em modo capturado e normaliza findings estruturados.
     *
     * JSON inválido não é tratado como ausência de findings: imprime evidência
     * bruta e converte sucesso aparente em código 1. Quando existem findings e
     * a ferramenta retornou 0, o código também vira 1 para preservar bloqueio.
     *
     * @param string $tool `phpstan` ou `psalm`.
     * @param list<string> $command Comando já resolvido para execução capturada.
     * @param ProjectContext $context Contexto que fornece cwd e raiz para relativização.
     * @return array{0:int,1:list<Finding>} Status normalizado e findings.
     */
    private function runStaticAnalysis(string $tool, array $command, ProjectContext $context): array
    {
        $result = $this->processRunner->runCaptured($command, $context->root());

        try {
            /** @var list<Finding> $findings */
            $findings = $tool === 'phpstan'
                ? FindingRenderer::phpStan($result->stdout, $context->root())
                : FindingRenderer::psalm($result->stdout, $context->root());
        } catch (JsonException $error) {
            fwrite(STDERR, CliStyle::error('[ERRO] Saida estruturada invalida de ' . $tool . ': ' . $error->getMessage()) . PHP_EOL);
            if (trim($result->stdout) !== '') {
                fwrite(STDERR, $result->stdout . PHP_EOL);
            }
            if (trim($result->stderr) !== '') {
                fwrite(STDERR, $result->stderr . PHP_EOL);
            }

            return [$result->exitCode === 0 ? 1 : $result->exitCode, []];
        }

        // Exit não zero sem finding é tratado como falha da ferramenta; não como finding inventado.
        if ($findings === []) {
            if ($result->exitCode !== 0) {
                if (trim($result->stderr) !== '') {
                    fwrite(STDERR, $result->stderr . PHP_EOL);
                }
                if (trim($result->stdout) !== '') {
                    fwrite(STDERR, $result->stdout . PHP_EOL);
                }
                fwrite(STDERR, CliStyle::error('✗ ' . $tool . ': terminou com codigo ' . $result->exitCode . '.') . PHP_EOL);
            } else {
                echo CliStyle::success('✓ ' . $tool . ': nenhum achado.') . PHP_EOL;
            }

            return [$result->exitCode, []];
        }

        FindingRenderer::render($findings, false);
        echo CliStyle::error('✗ ' . $tool . ': ' . count($findings) . ' achado(s) bloqueante(s).') . PHP_EOL;

        return [$result->exitCode === 0 ? 1 : $result->exitCode, $findings];
    }

    /**
     * Executa Composer Audit estruturado e converte seu JSON em findings SCA/policy.
     *
     * JSON inválido ou saída não normalizável é erro de execução. Exit não zero
     * sem finding também vira RuntimeException para não produzir falsa impressão
     * de “sem vulnerabilidades”. Findings válidos preservam o exit code da fonte,
     * usando 1 quando a ferramenta retornar 0 apesar de findings.
     *
     * @param list<string> $command Comando defensivo de Composer Audit.
     * @param ProjectContext $context Contexto que fornece cwd.
     * @param SecurityInventory $inventory Inventário usado pelo parser para correlacionar componentes.
     * @return array{0:int,1:list<Finding>} Status e findings normalizados.
     * @throws RuntimeException Quando a saída/execução não pode ser interpretada com segurança.
     */
    private function runComposerAudit(
        array $command,
        ProjectContext $context,
        SecurityInventory $inventory,
    ): array {
        $result = $this->processRunner->runCaptured($command, $context->root());

        try {
            /** @var list<Finding> $findings */
            $findings = ComposerAuditParser::parse($result->stdout, $inventory);
        } catch (JsonException|UnexpectedValueException $error) {
            if (trim($result->stderr) !== '') {
                fwrite(STDERR, $result->stderr . PHP_EOL);
            }
            if (trim($result->stdout) !== '') {
                fwrite(STDERR, $result->stdout . PHP_EOL);
            }
            throw new RuntimeException(
                'Composer Audit não retornou JSON estruturado válido: ' . $error->getMessage(),
                0,
                $error,
            );
        }

        if ($findings === []) {
            if ($result->exitCode !== 0) {
                if (trim($result->stderr) !== '') {
                    fwrite(STDERR, $result->stderr . PHP_EOL);
                }
                throw new RuntimeException(
                    'Composer Audit terminou com código ' . $result->exitCode
                    . ' sem finding normalizado; trate como falha de infraestrutura ou política não suportada.',
                );
            }

            echo CliStyle::success('✓ composer-audit: nenhum advisory ou policy finding.') . PHP_EOL;
            return [0, []];
        }

        FindingRenderer::render($findings, true);
        echo CliStyle::error(
            '✗ composer-audit: ' . count($findings) . ' finding(s) SCA/policy.',
        ) . PHP_EOL;

        return [$result->exitCode === 0 ? 1 : $result->exitCode, $findings];
    }

    /**
     * Executa um scanner SAST estruturado e preserva findings e cobertura observada.
     *
     * @param string $tool `psalm-taint` ou `semgrep`.
     * @param list<string> $command Comando com saída JSON já configurada.
     * @param ProjectContext $context Contexto que fornece cwd, paths e raiz.
     * @param int $started Marca monotônica usada para duração.
     * @return ToolResult Resultado completo do scanner.
     */
    private function runSast(string $tool, array $command, ProjectContext $context, int $started): ToolResult
    {
        $process = $this->processRunner->runCaptured($command, $context->root());
        try {
            if ($tool === 'psalm-taint') {
                $findings = PsalmTaintParser::parse($process->stdout, $context->root());
                $coverage = [
                    'status' => 'declared',
                    'requested_paths' => $context->paths(),
                    'scanned_files' => null,
                ];
                /** @var list<mixed> $errors Psalm não fornece coleção separada de erros neste formato. */
                $errors = [];
            } else {
                $parsed = SemgrepParser::parse($process->stdout, $context->root());
                $findings = $parsed['findings'];
                $coverage = $parsed['coverage'];
                $errors = $parsed['errors'];
            }
        } catch (JsonException|UnexpectedValueException $error) {
            if (trim($process->stderr) !== '') {
                fwrite(STDERR, $process->stderr . PHP_EOL);
            }
            throw new RuntimeException($tool . ' não retornou JSON SAST válido: ' . $error->getMessage(), 0, $error);
        }

        if ($errors !== [] || ($coverage['status'] ?? null) === 'partial') {
            FindingRenderer::render($findings, false);
            return ToolResult::partial(
                $tool,
                'cobertura parcial ou erros reportados pelo scanner',
                $findings,
                $coverage,
                $this->elapsedMs($started),
            );
        }
        if ($findings === [] && $process->exitCode !== 0) {
            throw new RuntimeException($tool . ' terminou com código ' . $process->exitCode . ' sem finding estruturado.');
        }
        if ($findings === []) {
            echo CliStyle::success('✓ ' . $tool . ': nenhum finding SAST.') . PHP_EOL;
        } else {
            FindingRenderer::render($findings, false);
            echo CliStyle::error('✗ ' . $tool . ': ' . count($findings) . ' finding(s) SAST.') . PHP_EOL;
        }
        return ToolResult::completed(
            $tool,
            $findings === [] ? 0 : ($process->exitCode === 0 ? 1 : $process->exitCode),
            $findings,
            $this->elapsedMs($started),
            $coverage,
        );
    }

    /**
     * Executa a consulta OSV a partir do inventário já resolvido.
     *
     * O cliente é responsável por rede/normalização. Este método apenas renderiza
     * o resultado e converte presença de findings em exit code 1.
     *
     * @param SecurityInventory $inventory Inventário Composer que será consultado no OSV.
     * @return array{0:int,1:list<Finding>} 0 sem findings ou 1 acompanhado dos findings encontrados.
     */
    private function runOsv(SecurityInventory $inventory): array
    {
        /** @var list<Finding> $findings */
        $findings = $this->osvClient->scan($inventory);
        if ($findings === []) {
            echo CliStyle::success('✓ osv: nenhuma vulnerabilidade conhecida para o inventário resolvido.') . PHP_EOL;
            return [0, []];
        }

        FindingRenderer::render($findings, true);
        echo CliStyle::error('✗ osv: ' . count($findings) . ' finding(s) SCA.') . PHP_EOL;
        return [1, $findings];
    }

    /**
     * Executa a suíte de testes capturando saída para detectar falso sucesso sem testes.
     *
     * stdout/stderr são reproduzidos para o usuário. Mesmo com exit code 0,
     * mensagens “no tests executed/found” são convertidas em falha 1 porque uma
     * etapa vazia não comprova qualidade.
     *
     * @param list<string> $command Comando de teste resolvido.
     * @param ProjectContext $context Contexto que fornece o cwd do consumidor.
     * @return int Exit code do teste, ou 1 para suíte vazia detectada.
     */
    private function runTests(array $command, ProjectContext $context): int
    {
        $result = $this->processRunner->runCaptured($command, $context->root());
        if ($result->stdout !== '') {
            echo $result->stdout;
        }
        if ($result->stderr !== '') {
            fwrite(STDERR, $result->stderr);
        }

        // Exit 0 acompanhado de “no tests” é cobertura ausente, não sucesso confiável.
        if (
            $result->exitCode === 0
            && preg_match('/\b(?:no tests executed|no tests found)\b/i', $result->stdout . "\n" . $result->stderr) === 1
        ) {
            fwrite(STDERR, CliStyle::warning('! test: nenhuma verificacao foi executada.') . PHP_EOL);

            return 1;
        }

        return $result->exitCode;
    }

    /**
     * Resolve o comando de testes preferindo script Composer explícito a PHPUnit local.
     *
     * O script Composer roda com plugins desabilitados e sem interação. Na ausência
     * de script, `vendor/bin/phpunit` só é usado quando o arquivo existe; caso
     * contrário a etapa retorna null e será marcada como não aplicável.
     *
     * @param ProjectContext $context Contexto do consumidor.
     * @return list<string>|null Comando de teste ou null quando não há suíte detectável.
     */
    private function testCommand(ProjectContext $context): ?array
    {
        if ($context->hasComposerScript('test')) {
            return [
                $this->tool('composer', $context),
                '--no-plugins',
                '--no-interaction',
                'run-script',
                'test',
            ];
        }

        return is_file($context->root() . '/vendor/bin/phpunit')
            ? [$this->tool('phpunit', $context), '--fail-on-empty-test-suite']
            : null;
    }

    /**
     * Exibe a legenda de cores/símbolos apenas uma vez por processo PHP.
     */
    private function showLegend(): void
    {
        if (self::$legendShown) {
            return;
        }

        self::$legendShown = true;
        echo '[NINFA] Legenda: ' . CliStyle::legend() . PHP_EOL;
    }

    /**
     * Interpreta a variável legada NINFA_DAST exclusivamente para emitir aviso.
     *
     * @return bool True para valores booleanos textuais que solicitavam DAST historicamente.
     */
    private function dastRequested(): bool
    {
        return in_array(strtolower((string) getenv('NINFA_DAST')), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Resolve um executável pelo ToolResolver usando a raiz do contexto.
     *
     * @param string $name Nome lógico/binário da ferramenta.
     * @param ProjectContext $context Contexto que fornece a raiz do consumidor.
     * @return string Caminho executável resolvido.
     */
    private function tool(string $name, ProjectContext $context): string
    {
        return $this->toolResolver->resolve($name, $context->root());
    }

    /**
     * Serializa SecurityInventory no workspace antes da execução dos scanners.
     *
     * @param ProjectContext $context Contexto que fornece o workspace externo.
     * @param SecurityInventory $inventory Inventário já materializado.
     * @return string Caminho absoluto de `security-inventory.json`.
     * @throws JsonException Quando a serialização falha.
     * @throws RuntimeException Quando o arquivo não pode ser gravado.
     */
    private function writeSecurityInventory(ProjectContext $context, SecurityInventory $inventory): string
    {
        $file = $context->workspace()->file('security-inventory.json');
        $written = file_put_contents(
            $file,
            json_encode(
                $inventory,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ) . PHP_EOL,
        );
        if ($written === false) {
            throw new RuntimeException('Não foi possível gravar o inventário de segurança.');
        }
        return $file;
    }

    /**
     * Serializa o SecurityReport canônico depois da execução das etapas security.
     *
     * @param ProjectContext $context Contexto que fornece o workspace externo.
     * @param SecurityInventory $inventory Inventário utilizado pelas fontes SCA.
     * @param RunResult $runResult Resultado consolidado que será incorporado ao relatório.
     * @return string Caminho absoluto de `security-report.json`.
     * @throws JsonException Quando a serialização falha.
     * @throws RuntimeException Quando o arquivo não pode ser gravado.
     */
    private function writeSecurityReport(
        ProjectContext $context,
        SecurityInventory $inventory,
        RunResult $runResult,
    ): string {
        $file = $context->workspace()->file('security-report.json');
        $written = file_put_contents(
            $file,
            json_encode(
                new SecurityReport($inventory, $runResult),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ) . PHP_EOL,
        );
        if ($written === false) {
            throw new RuntimeException('Não foi possível gravar o relatório de segurança.');
        }
        return $file;
    }

    /**
     * Converte um timestamp de `hrtime(true)` em duração inteira de milissegundos.
     *
     * @param int $started Valor monotônico capturado antes da etapa.
     * @return int Duração arredondada em milissegundos.
     */
    private function elapsedMs(int $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }

    /**
     * Monta o comando Semgrep com regra Ninfa, métricas desligadas e paths do contexto.
     *
     * `NINFA_SEMGREP_BIN` tem precedência quando aponta texto não vazio; caso
     * contrário ToolResolver encontra o executável. `vendor` e `runtime` são
     * excluídos, e cada path detectado vira alvo absoluto explícito.
     *
     * @param ProjectContext $context Contexto que fornece raiz e paths analisáveis.
     * @return list<string> Comando completo do Semgrep sem shell intermediário.
     */
    private function semgrepCommand(ProjectContext $context): array
    {
        $binary = getenv('NINFA_SEMGREP_BIN');
        if (!is_string($binary) || $binary === '') {
            $binary = $this->tool('semgrep', $context);
        }

        /** @var list<string> $command Argumentos Semgrep em ordem de execução. */
        $command = [
            $binary,
            '--config', dirname(__DIR__) . '/security/semgrep/common.yml',
            '--error',
            '--json',
            '--metrics=off',
            '--exclude', 'vendor',
            '--exclude', 'runtime',
        ];

        foreach ($context->paths() as $path) {
            $command[] = $context->root() . '/' . $path;
        }

        $contract = SecurityContract::forProfile($context->profile());
        foreach ($contract->semgrepConfigs as $config) {
            if ($config === 'security/semgrep/common.yml') {
                continue;
            }
            $command[] = '--config';
            $command[] = dirname(__DIR__) . '/' . $config;
        }

        return $command;
    }
}
