<?php

declare(strict_types=1);

require_once __DIR__ . '/ProcessRunner.php';
require_once __DIR__ . '/PipelinePlan.php';
require_once __DIR__ . '/ExternalConfigGenerator.php';

final class PipelineRunner
{
    public function __construct(
        private readonly ProcessRunner $processRunner = new ProcessRunner(),
        private readonly PipelinePlan $plan = new PipelinePlan(),
        private readonly ExternalConfigGenerator $configGenerator = new ExternalConfigGenerator(),
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
            if (($hook['optional'] ?? false) === true) {
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
        $vendor = $context->root() . '/vendor/bin/';

        return match ($id) {
            'ecs' => [$vendor . 'ecs', 'check', '--config', $configs['ecs'], ...($mode === 'fix' ? ['--fix'] : [])],
            'rector' => [$vendor . 'rector', 'process', '--config', $configs['rector'], '--no-progress-bar', ...($mode === 'dry-run' ? ['--dry-run'] : [])],
            'phpstan' => [$vendor . 'phpstan', 'analyse', '--configuration', $configs['phpstan'], '--no-progress'],
            'psalm' => [$vendor . 'psalm', '--config=' . $configs['psalm'], '--no-progress'],
            'psalm-taint' => [$vendor . 'psalm', '--config=' . $configs['psalm'], '--taint-analysis', '--no-progress'],
            'test' => is_file($vendor . 'phpunit') ? [$vendor . 'phpunit'] : null,
            'composer-audit' => ['composer', 'audit', '--locked', '--no-interaction'],
            'semgrep' => ['bash', dirname(__DIR__) . '/scripts/semgrep-scan.sh'],
            default => null,
        };
    }
}
