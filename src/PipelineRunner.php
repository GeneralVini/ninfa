<?php

declare(strict_types=1);

require_once __DIR__ . '/ProcessRunner.php';
require_once __DIR__ . '/PipelinePlan.php';
require_once __DIR__ . '/ExternalConfigGenerator.php';
require_once __DIR__ . '/ToolResolver.php';

final class PipelineRunner
{
    public function __construct(
        private readonly ProcessRunner $processRunner = new ProcessRunner(),
        private readonly PipelinePlan $plan = new PipelinePlan(),
        private readonly ExternalConfigGenerator $configGenerator = new ExternalConfigGenerator(),
        private readonly ToolResolver $toolResolver = new ToolResolver(),
    ) {
    }

    public function run(string $operation, ProjectContext $context): int
    {
        $configs = $this->configGenerator->generate($context);
        $hooks = match ($operation) {
            'check' => $this->plan->check($context),
            'fix' => $this->plan->fix($context),
            'security' => $this->plan->security($context),
            default => throw new InvalidArgumentException('Operacao invalida: ' . $operation),
        };

        foreach ($hooks as $hook) {
            if (($hook['optional'] ?? false) === true && !$this->optionalHookEnabled($hook['id'])) {
                continue;
            }

            $command = $this->commandFor($hook['id'], $hook['mode'], $context, $configs);
            if ($command === null) {
                continue;
            }

            echo '[NINFA] ' . $hook['id'] . ' (' . $hook['mode'] . ')' . PHP_EOL;
            $status = $this->processRunner->run($command, $context->root());
            if ($status !== 0) {
                return $status;
            }
        }

        return 0;
    }

    /** @param array<string,string> $configs
     *  @return list<string>|null
     */
    private function commandFor(string $id, string $mode, ProjectContext $context, array $configs): ?array
    {
        return match ($id) {
            'ecs' => [$this->tool('ecs', $context), 'check', '--config', $configs['ecs'], ...($mode === 'fix' ? ['--fix'] : [])],
            'rector' => [$this->tool('rector', $context), 'process', '--config', $configs['rector'], '--no-progress-bar', ...($mode === 'dry-run' ? ['--dry-run'] : [])],
            'phpstan' => [$this->tool('phpstan', $context), 'analyse', '--configuration', $configs['phpstan'], '--no-progress'],
            'psalm' => [$this->tool('psalm', $context), '--config=' . $configs['psalm'], '--no-progress'],
            'psalm-taint' => [$this->tool('psalm', $context), '--config=' . $configs['psalm'], '--taint-analysis', '--no-progress'],
            'test' => is_file($context->root() . '/vendor/bin/phpunit')
                ? [$this->tool('phpunit', $context)]
                : null,
            'composer-audit' => [$this->tool('composer', $context), 'audit', '--locked', '--no-interaction'],
            'semgrep' => $this->semgrepCommand($context),
            'dast' => ['bash', dirname(__DIR__) . '/scripts/zap-scan.sh'],
            default => null,
        };
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
