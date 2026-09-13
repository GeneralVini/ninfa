<?php

declare(strict_types=1);

final class FrontendCommandBuilder
{
    /** @return list<string>|null */
    public function build(string $id, string $mode, callable $tool): ?array
    {
        if ($id === 'eslint') {
            $command = [$tool('eslint'), '.'];
            if ($mode === 'fix') {
                $command[] = '--fix';
            }
            return $command;
        }

        if ($id === 'prettier') {
            return [$tool('prettier'), $mode === 'fix' ? '--write' : '--check', '.'];
        }

        return null;
    }
}
