<?php

declare(strict_types=1);

final class Workspace
{
    public static function forProject(string $projectRoot): self
    {
        $realProjectRoot = realpath($projectRoot) ?: $projectRoot;
        $base = getenv('NINFA_WORKSPACE_ROOT');
        if (!is_string($base) || $base === '') {
            $base = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'ninfa';
        }

        if (!str_starts_with($base, DIRECTORY_SEPARATOR)) {
            $cwd = getcwd();
            if ($cwd === false) {
                throw new RuntimeException('Não foi possível resolver o diretório atual.');
            }
            $base = $cwd . DIRECTORY_SEPARATOR . $base;
        }

        $project = rtrim($realProjectRoot, DIRECTORY_SEPARATOR);
        $base = rtrim($base, DIRECTORY_SEPARATOR);
        if ($base === $project || str_starts_with($base, $project . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('NINFA_WORKSPACE_ROOT não pode ficar dentro do projeto consumidor.');
        }

        $id = substr(hash('sha256', $realProjectRoot), 0, 16);
        return new self($base . DIRECTORY_SEPARATOR . $id);
    }

    public function __construct(private readonly string $path)
    {
        if (!is_dir($this->path) && !mkdir($this->path, 0775, true) && !is_dir($this->path)) {
            throw new RuntimeException('Não foi possível criar workspace Ninfa: ' . $this->path);
        }
    }

    public function path(): string
    {
        return $this->path;
    }

    public function file(string $name): string
    {
        return $this->path . DIRECTORY_SEPARATOR . ltrim($name, DIRECTORY_SEPARATOR);
    }
}
