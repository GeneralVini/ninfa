<?php

declare(strict_types=1);

final class SemanticHints
{
    private const MAX_FILE_BYTES = 131072;
    private const MAX_FILES = 32;

    /** @var list<string> */
    private array $files = [];

    /** @var list<string> */
    private array $symbols = [];

    /** @var array<string, int> */
    private array $signals = ['glpi-plugin' => 0, 'yii2' => 0, 'yii3' => 0];

    public static function fromProject(string $root): self
    {
        $self = new self();
        $candidates = [
            $root . '/README.md',
            $root . '/AGENTS.md',
            $root . '/CONTRIBUTING.md',
            $root . '/ARCHITECTURE.md',
        ];

        foreach (glob($root . '/docs/*.md') ?: [] as $file) {
            $candidates[] = $file;
        }

        foreach (array_values(array_unique($candidates)) as $file) {
            if (count($self->files) >= self::MAX_FILES || !is_file($file)) {
                continue;
            }

            $size = filesize($file);
            if (!is_int($size) || $size > self::MAX_FILE_BYTES) {
                continue;
            }

            $content = file_get_contents($file);
            if (!is_string($content)) {
                continue;
            }

            $real = realpath($file) ?: $file;
            $self->files[] = str_starts_with($real, $root . DIRECTORY_SEPARATOR)
                ? substr($real, strlen($root) + 1)
                : $real;
            $self->consume($content);
        }

        $self->symbols = array_values(array_unique($self->symbols));
        sort($self->symbols);

        return $self;
    }

    /** @return list<string> */
    public function files(): array
    {
        return $this->files;
    }

    /** @return list<string> */
    public function symbols(): array
    {
        return $this->symbols;
    }

    /** @return array<string, int> */
    public function profileSignals(): array
    {
        return $this->signals;
    }

    private function consume(string $content): void
    {
        $lower = strtolower($content);
        $this->signals['glpi-plugin'] += substr_count($lower, 'glpi');
        $this->signals['yii2'] += substr_count($lower, 'yii2') + substr_count($lower, 'yii 2');
        $this->signals['yii3'] += substr_count($lower, 'yii3') + substr_count($lower, 'yii 3');

        foreach (explode('`', $content) as $index => $chunk) {
            if ($index % 2 === 0) {
                continue;
            }

            $candidate = trim($chunk);
            if ($candidate === '' || strlen($candidate) > 160 || str_contains($candidate, ' ')) {
                continue;
            }

            $this->symbols[] = rtrim($candidate, '()');
        }
    }
}
