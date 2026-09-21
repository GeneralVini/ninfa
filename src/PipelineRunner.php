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

final class PipelineRunner
{
    private static bool $legendShown = false;

    public function __construct(
        private readonly ProcessRunner $processRunner = new ProcessRunner(),
        private readonly PipelinePlan $plan = new PipelinePlan(),
        private readonly ExternalConfigGenerator $configGenerator = new ExternalConfigGenerator(),
        private readonly ToolResolver $toolResolver = new ToolResolver(),
        private readonly OsvClient $osvClient = new OsvClient(),
    ) {
    }

    public function run(string $operation, ProjectContext $context): int
    {
        return $this->runResult($operation, $context)->finalExitCode;
    }

    public function runResult(string $operation, ProjectContext $context): RunResult
    {
        $this->showLegend();
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

        $configs = $this->configGenerator->generate($context);
        $hooks = match ($operation) {
            'check' => $this->plan->check($context),
            'fix' => $this->plan->fix($context),
            'security' => $this->plan->security($context),
            default => throw new InvalidArgumentException('Operacao invalida: ' . $operation),
        };

        $results = [];
        $firstFailure = 0;

        foreach ($hooks as $hook) {
            $started = hrtime(true);

            if ($operation === 'security' && $hook['id'] === 'osv') {
                if (!$securityInventory instanceof SecurityInventory) {
                    $results[] = ToolResult::error('osv', 'Inventário de segurança ausente.', $this->elapsedMs($started));
                    $firstFailure = $firstFailure === 0 ? 1 : $firstFailure;
                    continue;
                }

                $packages = $securityInventory->toArray()['composer']['packages'] ?? [];
                if (!is_array($packages) || $packages === []) {
                    echo CliStyle::warning('! osv: sem packages Composer resolvidos para consulta.') . PHP_EOL;
                    $results[] = ToolResult::skipped('osv', 'sem packages Composer resolvidos');
                    continue;
                }

                echo CliStyle::info('[NINFA] osv') . ' (' . $hook['mode'] . ')' . PHP_EOL;
                try {
                    [$status, $findings] = $this->runOsv($securityInventory);
                } catch (Throwable $error) {
                    fwrite(STDERR, CliStyle::error('✗ osv: ' . $error->getMessage()) . PHP_EOL);
                    $results[] = ToolResult::error('osv', $error->getMessage(), $this->elapsedMs($started));
                    $firstFailure = $firstFailure === 0 ? 1 : $firstFailure;
                    continue;
                }

                $results[] = ToolResult::completed('osv', $status, $findings, $this->elapsedMs($started));
                $firstFailure = $status !== 0 && $firstFailure === 0 ? $status : $firstFailure;
                continue;
            }

            try {
                $command = $this->commandFor($hook['id'], $hook['mode'], $context, $configs);
            } catch (Throwable $error) {
                fwrite(STDERR, CliStyle::error('✗ ' . $hook['id'] . ': ' . $error->getMessage()) . PHP_EOL);
                $results[] = ToolResult::error($hook['id'], $error->getMessage(), $this->elapsedMs($started));
                $firstFailure = $firstFailure === 0 ? 1 : $firstFailure;
                continue;
            }

            if ($command === null) {
                $results[] = ToolResult::skipped($hook['id'], 'nao aplicavel');
                continue;
            }

            echo CliStyle::info('[NINFA] ' . $hook['id']) . ' (' . $hook['mode'] . ')' . PHP_EOL;
            $structured = ($operation === 'check' && in_array($hook['id'], ['phpstan', 'psalm'], true))
                || ($operation === 'security' && $hook['id'] === 'composer-audit');
            $findings = [];
            try {
                if ($operation === 'security' && $hook['id'] === 'composer-audit') {
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
                $firstFailure = $firstFailure === 0 ? 1 : $firstFailure;
                continue;
            }

            if (!$structured) {
                echo $status === 0
                    ? CliStyle::success('✓ ' . $hook['id'] . ': concluido.') . PHP_EOL
                    : CliStyle::error('✗ ' . $hook['id'] . ': falhou (codigo ' . $status . ').') . PHP_EOL;
            }

            $results[] = ToolResult::completed(
                $hook['id'],
                $status,
                $findings,
                $this->elapsedMs($started),
            );
            $firstFailure = $status !== 0 && $firstFailure === 0 ? $status : $firstFailure;
        }

        $runResult = new RunResult($operation, $results, $firstFailure);
        if ($operation === 'security' && $securityInventory instanceof SecurityInventory) {
            try {
                $reportFile = $this->writeSecurityReport($context, $securityInventory, $runResult);
                echo CliStyle::info('[NINFA] Relatório de segurança: ' . $reportFile) . PHP_EOL;
            } catch (Throwable $error) {
                fwrite(STDERR, CliStyle::error('✗ security-report: ' . $error->getMessage()) . PHP_EOL);
                $results[] = ToolResult::error('security-report', $error->getMessage());
                $firstFailure = $firstFailure === 0 ? 1 : $firstFailure;
                $runResult = new RunResult($operation, $results, $firstFailure);
            }
        }

        $this->renderSummary($operation, $results);

        return $runResult;
    }

    /** @param list<ToolResult> $results */
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
                default => throw new LogicException('Estado de etapa invalido: ' . $result->state),
            };
            echo $line . PHP_EOL;
        }
    }

    /** @param array<string,string> $configs
     *  @return list<string>|null
     */
    private function commandFor(string $id, string $mode, ProjectContext $context, array $configs): ?array
    {
        return match ($id) {
            'ecs' => [$this->tool('ecs', $context), 'check', '--config', $configs['ecs'], ...($mode === 'fix' ? ['--fix'] : [])],
            'rector' => [$this->tool('rector', $context), 'process', '--config', $configs['rector'], '--no-progress-bar', ...($mode === 'dry-run' ? ['--dry-run'] : [])],
            'phpstan' => [$this->tool('phpstan', $context), 'analyse', '--configuration', $configs['phpstan'], '--error-format=json', '--no-progress'],
            'psalm' => [$this->tool('psalm', $context), '--config=' . $configs['psalm'], '--output-format=json', '--no-progress'],
            'psalm-taint' => [$this->tool('psalm', $context), '--config=' . $configs['psalm'], '--taint-analysis', '--no-progress'],
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

    /** @param list<string> $command
     *  @return array{0:int,1:list<Finding>}
     */
    private function runStaticAnalysis(string $tool, array $command, ProjectContext $context): array
    {
        $result = $this->processRunner->runCaptured($command, $context->root());

        try {
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

    /** @param list<string> $command
     *  @return array{0:int,1:list<Finding>}
     */
    private function runComposerAudit(
        array $command,
        ProjectContext $context,
        SecurityInventory $inventory,
    ): array {
        $result = $this->processRunner->runCaptured($command, $context->root());

        try {
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

    /** @return array{0:int,1:list<Finding>} */
    private function runOsv(SecurityInventory $inventory): array
    {
        $findings = $this->osvClient->scan($inventory);
        if ($findings === []) {
            echo CliStyle::success('✓ osv: nenhuma vulnerabilidade conhecida para o inventário resolvido.') . PHP_EOL;
            return [0, []];
        }

        FindingRenderer::render($findings, true);
        echo CliStyle::error('✗ osv: ' . count($findings) . ' finding(s) SCA.') . PHP_EOL;
        return [1, $findings];
    }

    /** @param list<string> $command */
    private function runTests(array $command, ProjectContext $context): int
    {
        $result = $this->processRunner->runCaptured($command, $context->root());
        if ($result->stdout !== '') {
            echo $result->stdout;
        }
        if ($result->stderr !== '') {
            fwrite(STDERR, $result->stderr);
        }

        if (
            $result->exitCode === 0
            && preg_match('/\b(?:no tests executed|no tests found)\b/i', $result->stdout . "\n" . $result->stderr) === 1
        ) {
            fwrite(STDERR, CliStyle::warning('! test: nenhuma verificacao foi executada.') . PHP_EOL);

            return 1;
        }

        return $result->exitCode;
    }

    /** @return list<string>|null */
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

    private function showLegend(): void
    {
        if (self::$legendShown) {
            return;
        }

        self::$legendShown = true;
        echo '[NINFA] Legenda: ' . CliStyle::legend() . PHP_EOL;
    }

    private function dastRequested(): bool
    {
        return in_array(strtolower((string) getenv('NINFA_DAST')), ['1', 'true', 'yes', 'on'], true);
    }

    private function tool(string $name, ProjectContext $context): string
    {
        return $this->toolResolver->resolve($name, $context->root());
    }

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

    private function elapsedMs(int $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }

    /** @return list<string> */
    private function semgrepCommand(ProjectContext $context): array
    {
        $binary = getenv('NINFA_SEMGREP_BIN');
        if (!is_string($binary) || $binary === '') {
            $binary = $this->tool('semgrep', $context);
        }

        $command = [
            $binary,
            '--config', dirname(__DIR__) . '/security/semgrep.yml',
            '--error',
            '--metrics=off',
            '--exclude', 'vendor',
            '--exclude', 'runtime',
        ];

        foreach ($context->paths() as $path) {
            $command[] = $context->root() . '/' . $path;
        }

        return $command;
    }
}
