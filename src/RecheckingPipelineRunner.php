<?php

declare(strict_types=1);

require_once __DIR__ . '/PipelineRunner.php';

final class RecheckingPipelineRunner
{
    public function __construct(
        private readonly PipelineRunner $runner = new PipelineRunner(),
    ) {
    }

    public function run(string $operation, ProjectContext $context): int
    {
        $status = $this->runner->run($operation, $context);
        if ($status !== 0 || $operation !== 'fix') {
            return $status;
        }

        $status = $this->runner->run('check', $context);
        if ($status !== 0) {
            echo '[NINFA] Permanecem achados sem correção automática.' . PHP_EOL;
            echo '[NINFA] Execute para orientação auditável: ninfa assist ' . $context->root() . PHP_EOL;
        }

        return $status;
    }
}
