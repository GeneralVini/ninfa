<?php

declare(strict_types=1);

require_once __DIR__ . '/ProjectContext.php';

/**
 * Constrói o inventário local usado pelas fontes SCA e pelo relatório de segurança.
 *
 * O inventário combina fatos do ProjectContext, `composer.json`,
 * `composer.lock` ou `vendor/composer/installed.json`, além do runtime PHP e
 * das extensões carregadas. Para versões resolvidas de packages, o lock tem
 * precedência; `installed.json` é usado somente quando o lock não existe.
 *
 * Dependências declaradas são usadas para classificar packages como
 * direct/transitive e runtime/dev quando possível. A classe não consulta
 * serviços externos e não decide se uma versão é vulnerável.
 */
final class SecurityInventory implements JsonSerializable
{
    /** @param array<string,mixed> $data */
    private function __construct(private readonly array $data)
    {
    }

    public static function fromContext(ProjectContext $context): self
    {
        $composer = $context->composer();
        $directRuntime = self::packageRequirements($composer['require'] ?? []);
        $directDev = self::packageRequirements($composer['require-dev'] ?? []);
        $platform = self::platformRequirements($composer);

        [$packageSource, $packages, $lockMetadata, $installedMetadata] = self::packages(
            $context->root(),
            $directRuntime,
            $directDev,
        );

        $loadedExtensions = get_loaded_extensions();
        sort($loadedExtensions);
        $loaded = [];
        foreach ($loadedExtensions as $extension) {
            $version = phpversion($extension);
            $loaded[] = [
                'name' => $extension,
                'version' => is_string($version) ? $version : null,
            ];
        }

        $requiredExtensions = [];
        foreach ($platform['extensions'] as $requirement) {
            $name = substr($requirement['name'], 4);
            $version = phpversion($name);
            $requiredExtensions[] = [
                ...$requirement,
                'installed' => extension_loaded($name),
                'installed_version' => is_string($version) ? $version : null,
            ];
        }

        $host = null;
        if ($context->profile() === 'glpi-plugin') {
            $host = [
                'type' => 'glpi',
                'root' => $context->glpiRoot(),
                'version' => $context->glpiVersion(),
            ];
        }

        return new self([
            'profile' => $context->profile(),
            'paths' => $context->paths(),
            'php' => [
                'constraint' => $context->phpConstraint(),
                'runtime' => [
                    'version' => $context->runtimePhpVersion(),
                    'major_minor' => $context->phpVersion(),
                    'sapi' => PHP_SAPI,
                ],
                'extensions' => [
                    'required' => $requiredExtensions,
                    'loaded' => $loaded,
                ],
            ],
            'composer' => [
                'json_present' => is_file($context->root() . '/composer.json'),
                'package_source' => $packageSource,
                'lock' => $lockMetadata,
                'installed_json' => $installedMetadata,
                'direct_runtime' => array_keys($directRuntime),
                'direct_dev' => array_keys($directDev),
                'packages' => $packages,
            ],
            'host' => $host,
        ]);
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return $this->data;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    /** @return array<string,mixed>|null */
    public function package(string $name): ?array
    {
        $packages = $this->data['composer']['packages'] ?? [];
        if (!is_array($packages)) {
            return null;
        }

        foreach ($packages as $package) {
            if (is_array($package) && ($package['name'] ?? null) === $name) {
                return $package;
            }
        }

        return null;
    }

    public function packageSource(): string
    {
        $source = $this->data['composer']['package_source'] ?? 'none';
        return is_string($source) ? $source : 'none';
    }

    /** @param mixed $requirements
     *  @return array<string,string>
     */
    private static function packageRequirements(mixed $requirements): array
    {
        if (!is_array($requirements)) {
            return [];
        }

        $packages = [];
        foreach ($requirements as $name => $constraint) {
            if (!is_string($name) || !is_string($constraint)) {
                continue;
            }
            if ($name === 'php' || str_starts_with($name, 'ext-') || str_starts_with($name, 'lib-')) {
                continue;
            }
            $packages[$name] = $constraint;
        }
        ksort($packages);
        return $packages;
    }

    /** @param array<string,mixed> $composer
     *  @return array{php:?string,extensions:list<array{name:string,constraint:string,dev:bool}>}
     */
    private static function platformRequirements(array $composer): array
    {
        $php = null;
        $extensions = [];
        foreach ([['key' => 'require', 'dev' => false], ['key' => 'require-dev', 'dev' => true]] as $scope) {
            $requirements = $composer[$scope['key']] ?? [];
            if (!is_array($requirements)) {
                continue;
            }
            foreach ($requirements as $name => $constraint) {
                if (!is_string($name) || !is_string($constraint)) {
                    continue;
                }
                if ($name === 'php' && $scope['dev'] === false) {
                    $php = $constraint;
                    continue;
                }
                if (str_starts_with($name, 'ext-')) {
                    $extensions[] = [
                        'name' => $name,
                        'constraint' => $constraint,
                        'dev' => $scope['dev'],
                    ];
                }
            }
        }

        usort($extensions, static fn (array $a, array $b): int => [$a['name'], $a['dev']] <=> [$b['name'], $b['dev']]);
        return ['php' => $php, 'extensions' => $extensions];
    }

    /**
     * @param array<string,string> $directRuntime
     * @param array<string,string> $directDev
     * @return array{0:string,1:list<array<string,mixed>>,2:array<string,mixed>,3:array<string,mixed>}
     */
    private static function packages(string $root, array $directRuntime, array $directDev): array
    {
        $lockFile = $root . '/composer.lock';
        $installedFile = $root . '/vendor/composer/installed.json';
        $lockMetadata = ['present' => is_file($lockFile), 'content_hash' => null];
        $installedMetadata = ['present' => is_file($installedFile), 'used_as_fallback' => false];

        if (is_file($lockFile)) {
            $lock = self::readJson($lockFile, 'composer.lock');
            $lockMetadata['content_hash'] = is_string($lock['content-hash'] ?? null) ? $lock['content-hash'] : null;
            $packages = [
                ...self::normalizePackages($lock['packages'] ?? [], false, $directRuntime, $directDev),
                ...self::normalizePackages($lock['packages-dev'] ?? [], true, $directRuntime, $directDev),
            ];
            self::sortPackages($packages);
            return ['composer.lock', $packages, $lockMetadata, $installedMetadata];
        }

        if (is_file($installedFile)) {
            $installed = self::readJson($installedFile, 'vendor/composer/installed.json');
            $rawPackages = array_is_list($installed) ? $installed : ($installed['packages'] ?? []);
            $packages = self::normalizePackages($rawPackages, null, $directRuntime, $directDev);
            self::sortPackages($packages);
            $installedMetadata['used_as_fallback'] = true;
            return ['installed.json', $packages, $lockMetadata, $installedMetadata];
        }

        return ['none', [], $lockMetadata, $installedMetadata];
    }

    /**
     * @param mixed $rawPackages
     * @param bool|null $devScope
     * @param array<string,string> $directRuntime
     * @param array<string,string> $directDev
     * @return list<array<string,mixed>>
     */
    private static function normalizePackages(
        mixed $rawPackages,
        ?bool $devScope,
        array $directRuntime,
        array $directDev,
    ): array {
        if (!is_array($rawPackages)) {
            return [];
        }

        $packages = [];
        foreach ($rawPackages as $package) {
            if (!is_array($package)) {
                continue;
            }
            $name = $package['name'] ?? null;
            $version = $package['version'] ?? null;
            if (!is_string($name) || $name === '' || !is_string($version) || $version === '') {
                continue;
            }

            $runtimeDirect = array_key_exists($name, $directRuntime);
            $devDirect = array_key_exists($name, $directDev);
            $direct = $runtimeDirect || $devDirect;
            $scope = match (true) {
                $devScope === false => 'runtime',
                $devScope === true => 'dev',
                $runtimeDirect => 'runtime',
                $devDirect => 'dev',
                default => 'unknown',
            };

            $packages[] = [
                'name' => $name,
                'version' => $version,
                'pretty_version' => is_string($package['pretty_version'] ?? null) ? $package['pretty_version'] : null,
                'direct' => $direct,
                'relationship' => $direct ? 'direct' : 'transitive',
                'scope' => $scope,
                'type' => is_string($package['type'] ?? null) ? $package['type'] : null,
            ];
        }
        return $packages;
    }

    /** @param list<array<string,mixed>> $packages */
    private static function sortPackages(array &$packages): void
    {
        usort($packages, static fn (array $a, array $b): int => [$a['name'], $a['scope']] <=> [$b['name'], $b['scope']]);
    }

    /** @return array<string,mixed>|list<mixed> */
    private static function readJson(string $file, string $label): array
    {
        try {
            $decoded = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException($label . ' inválido: ' . $error->getMessage(), 0, $error);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException($label . ' inválido.');
        }
        return $decoded;
    }
}
