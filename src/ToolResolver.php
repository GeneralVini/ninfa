<?php

declare(strict_types=1);

final class ToolResolver
{
    public function resolve(string $name, string $projectRoot): string
    {
        foreach ([
            $projectRoot . '/vendor/bin/' . $name,
            dirname(__DIR__) . '/vendor/bin/' . $name,
        ] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return $name;
    }
}
