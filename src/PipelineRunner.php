<?php

declare(strict_types=1);

require_once __DIR__ . '/ProcessRunner.php';
require_once __DIR__ . '/PipelinePlan.php';
require_once __DIR__ . '/ExternalConfigGenerator.php';
require_once __DIR__ . '/ToolResolver.php';

final class PipelineRunner
{
    private static bool $legendShown = false;

    public function __construct(
        private readonly ProcessRunner $processRunner = new ProcessRunner(),
        private readonly PipelinePlan $plan = new PipelinePlan(),
        private readonly ExternalConfigGenerator $configGenerator = new ExternalConfigGenerator(),
        private readonly ToolResolver $toolResolver = new ToolResolver(),
    ) {
    }

    public function run(string $operation, ProjectContext $context): int
    {
        $this->showLegend();
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
            if (($hook['optional'] ?? false) === true && !$this->optionalHookEnabled($hook['id'])) {
                $results[] = [
                    'id' => $hook['id'],
                    'state' => 'skipped',
                    'exit_code' => null,
                    'detail' => 'opcional desabilitada',
                ];
                continue;
            }

            try {
                $command = $this->commandFor($hook['id'], $hook['mode'], $context, $configs);
            } catch (Throwable $error) {
                fwrite(STDERR, CliStyle::error('✗ ' . $hook['id'] . ': ' . $error->getMessage()) . PHP_EOL);
                $results[] = [
                    'id' => $hook['id'],
                    'state' => 'error',
                    'exit_code' => 1,
                    'detail' => $error->getMessage(),
                ];
                $firstFailure = $firstFailure === 0 ? 1 : $firstFailure;
                continue;
            }

            if ($command === null) {
                $results[] = [
                    'id' => $hook['id'],
                    'state' => 'skipped',
                    'exit_code' => null,
                    'detail' => 'nao aplicavel',
                ];
                continue;
            }

            echo CliStyle::info('[NINFA] ' . $hook['id']) . ' (' . $hook['mode'] . ')' . PHP_EOL;
            $structured = $operation === 'check' && in_array($hook['id'], ['phpstan', 'psalm'], true);
            try {
                $status = match (true) {
                    $structured => $this->runStaticAnalysis($hook['id'], $command, $context),
                    $hook['id'] === 'test' => $this->runTests($command, $context),
                    default => $this->processRunner->run($command, $context->root()),
                };
            } catch (Throwable $error) {
                fwrite(STDERR, CliStyle::error('✗ ' . $hook['id'] . ': ' . $error->getMessage()) . PHP_EOL);
                $results[] = [
                    'id' => $hook['id'],
                    'state' => 'error',
                    'exit_code' => 1,
                    'detail' => $error->getMessage(),
                ];
                $firstFailure = $firstFailure === 0 ? 1 : $firstFailure;
                continue;
            }

            if (!$structured) {
                echo $status === 0
                    ? CliStyle::success('✓ ' . $hook['id'] . ': concluido.') . PHP_EOL
                    : CliStyle::error('✗ ' . $hook['id'] . ': falhou (codigo ' . $status . ').') . PHP_EOL;
            }

            $results[] = [
                'id' => $hook['id'],
                'state' => $status === 0 ? 'ok' : 'failed',
                'exit_code' => $status,
                'detail' => null,
            ];
            $firstFailure = $status !== 0 && $firstFailure === 0 ? $status : $firstFailure;
        }

        $this->renderSummary($operation, $results);

        return $firstFailure;
    }

    /**
     * @param list<array{id:string,state:string,exit_code:int|null,detail:string|null}> $results
     */
    private function renderSummary(string $operation, array $results): void
    {
        echo PHP_EOL . CliStyle::info('[NINFA] Resumo (' . $operation . ')') . PHP_EOL;

        foreach ($results as $result) {
            $line = match ($result['state']) {
                'ok' => CliStyle::success('✓ ' . $result['id'] . ': ok (codigo 0)'),
                'failed' => CliStyle::error(
                    '✗ ' . $result['id'] . ': failed (codigo ' . $result['exit_code'] . ')',
                ),
                'error' => CliStyle::error(
                    '✗ ' . $result['id'] . ': error (codigo ' . $result['exit_code'] . ')',
                ),
                'skipped' => CliStyle::warning(
                    '! ' . $result['id'] . ': skipped (' . $result['detail'] . ')',
                ),
                default => throw new LogicException('Estado de etapa invalido: ' . $result['state']),
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
                ? [$this->tool('composer', $context), 'audit', '--locked', '--no-interaction']
                : null,
            'semgrep' => $this->semgrepCommand($context),
            'dast' => [
                'bash',
                dirname(__DIR__) . '/scripts/zap-scan.sh',
                $context->workspace()->file('security/zap-report.html'),
            ],
            default => null,
        };
    }

    /** @param list<string> $command */
    private function runStaticAnalysis(string $tool, array $command, ProjectContext $context): int
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

            return $result->exitCode === 0 ? 1 : $result->exitCode;
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

            return $result->exitCode;
        }

        FindingRenderer::render($findings, false);
        echo CliStyle::error('✗ ' . $tool . ': ' . count($findings) . ' achado(s) bloqueante(s).') . PHP_EOL;

        return $result->exitCode === 0 ? 1 : $result->exitCode;
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

    private function optionalHookEnabled(string $id): bool
    {
        if ($id !== 'dast') {
            return false;
        }

        return in_array(strtolower((string) getenv('NINFA_DAST')), ['1', 'true', 'yes', 'on'], true);
    }

    private function tool(string $name, ProjectContext $context): string
    {
        return $this->toolResolver->resolve($name, $context->root());
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
