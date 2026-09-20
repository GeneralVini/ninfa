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
        if ($realRoot === false || !is_dir($realRoot)) {
            throw new RuntimeException('Informe a raiz de um projeto PHP válido.');
        }

        return new self($realRoot);
    }

    private function __construct(private readonly string $root)
    {
        $this->composer = $this->loadComposer();
        $this->profile = (new ProfileDetector())->detect($root, $this->composer);
        $this->phpVersion = $this->detectPhpVersion();
        $this->paths = $this->detectPaths();
        $this->workspace = Workspace::forProject($root);

        if ($this->profile === 'glpi-plugin') {
            $this->resolveGlpiHost();
        }
    }

    public function root(): string { return $this->root; }
    public function profile(): string { return $this->profile; }
    public function phpVersion(): string { return $this->phpVersion; }
    /** @return list<string> */
    public function paths(): array { return $this->paths; }
    public function workspace(): Workspace { return $this->workspace; }
    public function phpStanLevel(): int|string { return $this->profile === 'glpi-plugin' ? 8 : 'max'; }
    public function psalmLevel(): int { return $this->profile === 'glpi-plugin' ? 8 : 1; }
    public function glpiRoot(): ?string { return $this->glpiRoot; }
    public function glpiVersion(): ?string { return $this->glpiVersion; }
    public function hasComposerScript(string $name): bool
    {
        $script = $this->composer['scripts'][$name] ?? null;

        return (is_string($script) && trim($script) !== '') || (is_array($script) && $script !== []);
    }

    /** @return array<string, mixed> */
    private function loadComposer(): array
    {
        $file = $this->root . '/composer.json';
        if (!is_file($file)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException('composer.json inválido.');
        }
        return $decoded;
    }

    private function detectPhpVersion(): string
    {
        $constraint = $this->composer['require']['php'] ?? null;
        if (is_string($constraint) && preg_match('/(\d+\.\d+)/', $constraint, $matches) === 1) {
            return $matches[1];
        }
        return PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    }

    /** @return list<string> */
    private function detectPaths(): array
    {
        $candidates = match ($this->profile) {
            'glpi-plugin' => ['setup.php', 'hook.php', 'src', 'inc', 'front', 'ajax', 'tests'],
            'yii2' => ['common', 'frontend', 'backend', 'console', 'src', 'app', 'modules', 'commands', 'tests'],
            'yii3' => ['src', 'app', 'config', 'public', 'modules', 'console', 'commands', 'tests'],
            'php-generic' => ['src', 'app', 'lib', 'include', 'includes', 'public', 'bin', 'modules', 'tests'],
            default => [],
        };

        $paths = [];
        foreach ($candidates as $candidate) {
            if (is_dir($this->root . '/' . $candidate) || is_file($this->root . '/' . $candidate)) {
                $paths[] = $candidate;
            }
        }

        if ($this->profile === 'php-generic' && $paths === []) {
            foreach (glob($this->root . '/*.php') ?: [] as $file) {
                $paths[] = basename($file);
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
        if (is_string($configured) && $configured !== '') { $candidates[] = $configured; }
        if (basename(dirname($this->root)) === 'plugins') { $candidates[] = dirname(dirname($this->root)); }

        foreach ($candidates as $candidate) {
            $root = realpath($candidate);
            if ($root === false || !is_file($root . '/src/autoload/constants.php')) { continue; }
            $constants = (string) file_get_contents($root . '/src/autoload/constants.php');
            if (preg_match("/define\\('GLPI_VERSION',\\s*'([^']+)'\\);/", $constants, $matches) !== 1) {
                throw new RuntimeException('Host GLPI localizado, mas não foi possível determinar a versão.');
            }
            $version = $matches[1];
            if (!str_starts_with($version, '11.')) {
                throw new RuntimeException('O profile glpi-plugin suporta somente GLPI 11. Detectado: ' . $version);
            }
            $this->glpiRoot = $root;
            $this->glpiVersion = $version;
            return;
        }

        throw new RuntimeException('Plugin GLPI detectado, mas host GLPI 11 não localizado. Defina NINFA_GLPI_ROOT.');
    }
}
