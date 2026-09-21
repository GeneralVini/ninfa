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
    /**
     * Armazena o snapshot normalizado já materializado por `fromContext()`.
     *
     * @param array<string,mixed> $data Estrutura canônica do inventário desta execução.
     */
    private function __construct(private readonly array $data)
    {
    }

    /**
     * Constrói o inventário SCA a partir de fatos locais do projeto e do runtime.
     *
     * `composer.lock` prevalece sobre `installed.json` para versões resolvidas.
     * A constraint PHP declarada permanece separada do runtime real. Extensões
     * requeridas são correlacionadas ao estado carregado, sem tentar mapear
     * automaticamente extensões PHP para bibliotecas nativas/CVEs.
     *
     * @param ProjectContext $context Contexto validado do projeto consumidor.
     * @return self Inventário imutável pronto para scanners e relatório.
     * @throws RuntimeException Quando lock/installed JSON presente é inválido.
     */
    public static function fromContext(ProjectContext $context): self
    {
        $composer = $context->composer();
        /** @var array<string,string> $directRuntime Dependências runtime diretas declaradas. */
        $directRuntime = self::packageRequirements($composer['require'] ?? []);
        /** @var array<string,string> $directDev Dependências dev diretas declaradas. */
        $directDev = self::packageRequirements($composer['require-dev'] ?? []);
        /** @var array{php:?string,extensions:list<array{name:string,constraint:string,dev:bool}>} $platform */
        $platform = self::platformRequirements($composer);

        [$packageSource, $packages, $lockMetadata, $installedMetadata] = self::packages(
            $context->root(),
            $directRuntime,
            $directDev,
        );

        /** @var list<string> $loadedExtensions Extensões carregadas no runtime atual. */
        $loadedExtensions = get_loaded_extensions();
        sort($loadedExtensions);
        /** @var list<array{name:string,version:?string}> $loaded Extensões carregadas com versão observável. */
        $loaded = [];
        foreach ($loadedExtensions as $extension) {
            $version = phpversion($extension);
            $loaded[] = [
                'name' => $extension,
                'version' => is_string($version) ? $version : null,
            ];
        }

        /** @var list<array{name:string,constraint:string,dev:bool,installed:bool,installed_version:?string}> $requiredExtensions */
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

        // Contexto do host é separado do inventário do plugin e só existe no profile GLPI.
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

    /**
     * Serializa exatamente o snapshot normalizado do inventário.
     *
     * @return array<string,mixed> Estrutura canônica usada no relatório JSON.
     */
    public function jsonSerialize(): array
    {
        return $this->data;
    }

    /**
     * Expõe o inventário como array para adapters que precisam consultar campos específicos.
     *
     * @return array<string,mixed> Cópia por valor do array interno do inventário.
     */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * Localiza um package resolvido pelo nome Composer exato.
     *
     * @param string $name Nome `vendor/package` procurado.
     * @return array<string,mixed>|null Registro normalizado ou null quando ausente/inválido.
     */
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

    /**
     * Retorna a fonte usada para obter as versões resolvidas de packages.
     *
     * Valores atuais são `composer.lock`, `installed.json` ou `none`; qualquer
     * estado interno inesperado degrada para `none` em vez de inventar cobertura.
     */
    public function packageSource(): string
    {
        $source = $this->data['composer']['package_source'] ?? 'none';
        return is_string($source) ? $source : 'none';
    }

    /**
     * Filtra requisitos Composer para manter somente packages de aplicação/biblioteca.
     *
     * `php`, `ext-*` e `lib-*` pertencem ao inventário de plataforma e são
     * excluídos daqui. Apenas constraint textual é preservada.
     *
     * @param mixed $requirements Valor bruto de `require` ou `require-dev`.
     * @return array<string,string> Mapa ordenado `package => constraint`.
     */
    private static function packageRequirements(mixed $requirements): array
    {
        if (!is_array($requirements)) {
            return [];
        }

        /** @var array<string,string> $packages Dependências Composer não-plataforma. */
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

    /**
     * Extrai constraint PHP e extensões declaradas nos dois escopos Composer.
     *
     * A constraint PHP considerada é somente a de `require`, pois `require-dev`
     * não representa o contrato runtime da aplicação. Extensões preservam flag
     * `dev` para permitir interpretação posterior de cobertura.
     *
     * @param array<string,mixed> $composer composer.json decodificado.
     * @return array{php:?string,extensions:list<array{name:string,constraint:string,dev:bool}>} Plataforma declarada.
     */
    private static function platformRequirements(array $composer): array
    {
        $php = null;
        /** @var list<array{name:string,constraint:string,dev:bool}> $extensions */
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
     * Resolve packages instalados aplicando precedência lock > installed.json > nenhum.
     *
     * Metadata registra presença dos dois arquivos e se `installed.json` foi
     * efetivamente usado como fallback. O lock mantém separação natural entre
     * `packages` runtime e `packages-dev`; installed.json pode deixar escopo
     * transitive como `unknown` quando não há declaração direta suficiente.
     *
     * @param string $root Raiz do projeto consumidor.
     * @param array<string,string> $directRuntime Dependências runtime diretas declaradas.
     * @param array<string,string> $directDev Dependências dev diretas declaradas.
     * @return array{0:string,1:list<array<string,mixed>>,2:array<string,mixed>,3:array<string,mixed>} Fonte, packages e metadata dos arquivos.
     */
    private static function packages(string $root, array $directRuntime, array $directDev): array
    {
        $lockFile = $root . '/composer.lock';
        $installedFile = $root . '/vendor/composer/installed.json';
        /** @var array<string,mixed> $lockMetadata */
        $lockMetadata = ['present' => is_file($lockFile), 'content_hash' => null];
        /** @var array<string,mixed> $installedMetadata */
        $installedMetadata = ['present' => is_file($installedFile), 'used_as_fallback' => false];

        // composer.lock é a fonte reproduzível preferida de versões resolvidas.
        if (is_file($lockFile)) {
            $lock = self::readJson($lockFile, 'composer.lock');
            $lockMetadata['content_hash'] = is_string($lock['content-hash'] ?? null) ? $lock['content-hash'] : null;
            /** @var list<array<string,mixed>> $packages */
            $packages = [
                ...self::normalizePackages($lock['packages'] ?? [], false, $directRuntime, $directDev),
                ...self::normalizePackages($lock['packages-dev'] ?? [], true, $directRuntime, $directDev),
            ];
            self::sortPackages($packages);
            return ['composer.lock', $packages, $lockMetadata, $installedMetadata];
        }

        // installed.json só entra quando não existe lock; não deve sobrepor resolução reproduzível.
        if (is_file($installedFile)) {
            $installed = self::readJson($installedFile, 'vendor/composer/installed.json');
            $rawPackages = array_is_list($installed) ? $installed : ($installed['packages'] ?? []);
            /** @var list<array<string,mixed>> $packages */
            $packages = self::normalizePackages($rawPackages, null, $directRuntime, $directDev);
            self::sortPackages($packages);
            $installedMetadata['used_as_fallback'] = true;
            return ['installed.json', $packages, $lockMetadata, $installedMetadata];
        }

        return ['none', [], $lockMetadata, $installedMetadata];
    }

    /**
     * Converte registros Composer em componentes com relação e escopo normalizados.
     *
     * Nome e versão são obrigatórios para um componente útil ao SCA. Directness
     * vem exclusivamente das declarações do composer.json; escopo usa a seção do
     * lock quando conhecida e, no fallback installed.json, usa declaração direta
     * ou `unknown` sem inferir transitividade de runtime/dev.
     *
     * @param mixed $rawPackages Lista bruta de packages do lock/installed.json.
     * @param bool|null $devScope false=runtime, true=dev, null=escopo não fornecido pela fonte.
     * @param array<string,string> $directRuntime Dependências runtime diretas.
     * @param array<string,string> $directDev Dependências dev diretas.
     * @return list<array<string,mixed>> Componentes válidos normalizados.
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

        /** @var list<array<string,mixed>> $packages */
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

    /**
     * Ordena componentes por nome e escopo para manter inventário determinístico.
     *
     * @param list<array<string,mixed>> $packages Lista mutável de componentes normalizados.
     */
    private static function sortPackages(array &$packages): void
    {
        usort($packages, static fn (array $a, array $b): int => [$a['name'], $a['scope']] <=> [$b['name'], $b['scope']]);
    }

    /**
     * Lê um arquivo JSON estrutural do Composer e converte erro de sintaxe em contexto legível.
     *
     * @param string $file Caminho absoluto do arquivo a ler.
     * @param string $label Rótulo usado na mensagem de erro.
     * @return array<string,mixed>|list<mixed> Estrutura JSON decodificada como array.
     * @throws RuntimeException Quando o JSON é inválido ou não decodifica para array.
     */
    private static function readJson(string $file, string $label): array
    {
        try {
            /** @var mixed $decoded Conteúdo JSON decodificado. */
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
