<?php

declare(strict_types=1);

require_once __DIR__ . '/ProjectContext.php';
require_once __DIR__ . '/FrontendDetector.php';

final class PipelinePlan
{
    public function __construct(private readonly FrontendDetector $frontendDetector = new FrontendDetector())
    {
    }

    /** @return list<array{id:string,mode:string,fixable:bool}> */
    public function check(ProjectContext $context): array
    {
        $this->assertSupported($context);

        $hooks = [
            ['id' => 'ecs', 'mode' => 'check', 'fixable' => true],
            ['id' => 'rector', 'mode' => 'dry-run', 'fixable' => true],
            ['id' => 'phpstan', 'mode' => 'check', 'fixable' => false],
            ['id' => 'psalm', 'mode' => 'check', 'fixable' => false],
        ];

        if ($this->frontendDetector->hasEslint($context->root())) {
            $hooks[] = ['id' => 'eslint', 'mode' => 'check', 'fixable' => true];
        }
        if ($this->frontendDetector->hasPrettier($context->root())) {
            $hooks[] = ['id' => 'prettier', 'mode' => 'check', 'fixable' => true];
        }

        $hooks[] = ['id' => 'test', 'mode' => 'check', 'fixable' => false];

        return $hooks;
    }

    /** @return list<array{id:string,mode:string,fixable:bool}> */
    public function fix(ProjectContext $context): array
    {
        return array_map(
            static fn (array $hook): array => [
                'id' => $hook['id'],
                'mode' => 'fix',
                'fixable' => true,
            ],
            array_values(array_filter(
                $this->check($context),
                static fn (array $hook): bool => $hook['fixable'],
            )),
        );
    }

    /** @return list<array{id:string,mode:string,optional:bool}> */
    public function security(ProjectContext $context): array
    {
        $this->assertSupported($context);

        return [
            ['id' => 'composer-audit', 'mode' => 'check', 'optional' => false],
            ['id' => 'psalm-taint', 'mode' => 'check', 'optional' => false],
            ['id' => 'semgrep', 'mode' => 'check', 'optional' => false],
            ['id' => 'dast', 'mode' => 'check', 'optional' => true],
        ];
    }

    /** @return list<string> */
    public function lefthookFixHooks(ProjectContext $context): array
    {
        return array_map(
            static fn (array $hook): string => $hook['id'],
            $this->fix($context),
        );
    }

    private function assertSupported(ProjectContext $context): void
    {
        if (!in_array($context->profile(), ['glpi-plugin', 'yii2', 'yii3', 'php-generic'], true)) {
            throw new LogicException('Profile sem pipeline Ninfa: ' . $context->profile());
        }
    }
}
