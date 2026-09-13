<?php

declare(strict_types=1);

require_once __DIR__ . '/ProfileDetector.php';
require_once __DIR__ . '/Workspace.php';

final class ProjectContext
{
    /** @var array<string, mixed> */
    private array $composer;
    /** @var list<string> */
    private array $paths;
    private string $profile;
    private string $phpVersion;
    private ?string $glpiRoot = null;
    private ?string $glpiVersion = null;
    private Workspace $workspace;

    public static function fromRoot(string $root): self
    {
        $realRoot = realpath($root);
        if ($realRoot === false || !is_file($realRoot . '/composer.json')) {
            throw new RuntimeException('Informe a raiz de um projeto PHP com composer.json.');
        }

        return new self($realRoot);
    }

    private function __construct(private readonly string $root)
    {
        $decoded = json_decode(
            (string) file_get_contents($root . '/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        if (!is_array($decoded)) {
            throw new RuntimeException('composer.json inválido.');
        }

        $this->composer = $decoded;
        $this->profile = (new ProfileDetector())->detect($root, $decoded);
        $this->phpVersion = $this->detectPhpVersion();
        $this->paths = $this->detectPaths();
        $this->workspace = Workspace::forProject($root);

        if ($this->profile === 'glpi-plugin') {
            $this->resolveGlpiHost();
        }
    }

    public function root(): string
    {
        return $this->root;
    }

    public function profile(): string
    {
        return $this->profile;
    }

    public function phpVersion(): string
    {
        return $this->phpVersion;
    }

    /** @return list<string> */
    public function paths(): array
    {
        return $this->paths;
    }

    public function workspace(): Workspace
    {
        return $this->workspace;
    }

    public function phpStanLevel(): int|string
    {
        return $this->profile === 'glpi-plugin' ? 8 : 'max';
    }

    public function psalmLevel(): int
    {
        return $this->profile === 'glpi-plugin' ? 8 : 1;
    }

    public function hasJavaScript(): bool
    {
        if (is_file($this->root . '/package.json')) {
            return true;
        }

        foreach (['js', 'ts', 'assets', 'resources', 'frontend', 'web'] as $directory) {
            if (is_dir($this->root . '/' . $directory)) {
                return true;
            }
        }

        foreach (['*.js', '*.mjs', '*.cjs', '*.ts', '*.tsx', '*.jsx'] as $pattern) {
            if ((glob($this->root . '/' . $pattern) ?: []) !== []) {
                return true;
            }
        }

        return false;
    }

    public function glpiRoot(): ?string
    {
        return $this->glpiRoot;
    }

    public function glpiVersion(): ?string
    {
        return $this->glpiVersion;
    }

    private function detectPhpVersion(): string
    {
        $constraint = (string) ($this->composer['require']['php'] ?? '>=8.2');
        return preg_match('/(\d+\.\d+)/', $constraint, $matches) === 1
            ? $matches[1]
            : '8.2';
    }

    /** @return list<string> */
    private function detectPaths(): array
    {
        $candidates = $this->profile === 'glpi-plugin'
            ? ['src', 'inc', 'front', 'ajax', 'tests']
            : ['src', 'app', 'config', 'modules', 'console', 'commands', 'tests'];

        $paths = [];
        foreach ($candidates as $candidate) {
            if (is_dir($this->root . '/' . $candidate)) {
                $paths[] = $candidate;
            }
        }

        if ($paths === []) {
            throw new RuntimeException('Nenhum caminho analisável detectado para ' . $this->profile . '.');
        }

        return $paths;
    }

    private function resolveGlpiHost(): void
    {
        $candidates = [];
        $configured = getenv('NINFA_GLPI_ROOT');
        if (is_string($configured) && $configured !== '') {
            $candidates[] = $configured;
        }
        if (basename(dirname($this->root)) === 'plugins') {
            $candidates[] = dirname(dirname($this->root));
        }

        foreach ($candidates as $candidate) {
            $root = realpath($candidate);
            if ($root === false || !is_file($root . '/src/autoload/constants.php')) {
                continue;
            }

            $this->glpiRoot = $root;
            $constants = (string) file_get_contents($root . '/src/autoload/constants.php');
            if (preg_match("/define\\('GLPI_VERSION',\\s*'([^']+)'\\);/", $constants, $matches) === 1) {
                $this->glpiVersion = $matches[1];
            }

            if ($this->glpiVersion !== null && !str_starts_with($this->glpiVersion, '11.')) {
                throw new RuntimeException(
                    'O profile glpi-plugin suporta somente GLPI 11. Detectado: ' . $this->glpiVersion,
                );
            }

            return;
        }

        throw new RuntimeException(
            'Plugin GLPI detectado, mas host GLPI 11 não localizado. Defina NINFA_GLPI_ROOT.',
        );
    }
}
