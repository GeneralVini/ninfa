<?php

declare(strict_types=1);

require_once __DIR__ . '/Finding.php';
require_once __DIR__ . '/SecurityInventory.php';

/**
 * Converte a saída JSON de `composer audit` em findings normalizados do Ninfa.
 *
 * Advisories de segurança viram `sca-advisory` e são correlacionados ao
 * componente/versão do SecurityInventory. Pacotes abandonados são preservados
 * separadamente como `dependency-policy`, pois abandono não é tratado como
 * vulnerabilidade.
 *
 * O parser preserva IDs, aliases, severidade, intervalo afetado, URL, data e
 * proveniência quando disponíveis. Ele não consulta a rede e não deduplica
 * findings contra outras fontes como OSV.
 */
final class ComposerAuditParser
{
    /** @return list<Finding> */
    public static function parse(string $json, SecurityInventory $inventory): array
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new UnexpectedValueException('Saída JSON do Composer Audit inválida.');
        }

        $findings = [];
        $advisories = $data['advisories'] ?? [];
        if (!is_array($advisories)) {
            throw new UnexpectedValueException('Campo advisories inválido na saída do Composer Audit.');
        }

        foreach ($advisories as $packageKey => $packageAdvisories) {
            if (!is_array($packageAdvisories)) {
                continue;
            }

            foreach ($packageAdvisories as $advisory) {
                if (!is_array($advisory)) {
                    continue;
                }

                $packageName = self::firstString(
                    $advisory['packageName'] ?? null,
                    is_string($packageKey) ? $packageKey : null,
                );
                if ($packageName === null) {
                    continue;
                }

                $advisoryId = self::firstString(
                    $advisory['advisoryId'] ?? null,
                    $advisory['cve'] ?? null,
                    self::firstRemoteId($advisory['sources'] ?? null),
                ) ?? 'composer-advisory';

                $title = self::firstString($advisory['title'] ?? null) ?? 'Advisory de segurança';
                $affectedVersions = self::firstString($advisory['affectedVersions'] ?? null);
                $cve = self::firstString($advisory['cve'] ?? null);
                $link = self::firstString($advisory['link'] ?? null);
                $reportedAt = self::firstString($advisory['reportedAt'] ?? null);
                $severity = self::normalizeSeverity(self::firstString($advisory['severity'] ?? null));
                $sources = self::normalizeSources($advisory['sources'] ?? null);
                $aliases = self::aliases($advisoryId, $cve, $sources);
                $package = $inventory->package($packageName);

                $installedVersion = is_array($package) && is_string($package['version'] ?? null)
                    ? $package['version']
                    : null;

                $problem = $title . ' afeta ' . $packageName
                    . ($installedVersion !== null ? ' ' . $installedVersion : '');

                $correction = 'Atualize ' . $packageName . ' para uma versão não afetada';
                if ($affectedVersions !== null) {
                    $correction .= ' pelo intervalo ' . $affectedVersions;
                }
                $correction .= '.';
                if ($link !== null) {
                    $correction .= ' Consulte ' . $link;
                }

                $provenance = ['composer-audit'];
                foreach ($sources as $source) {
                    $provenance[] = $source['name'] . ':' . $source['remote_id'];
                }

                $findings[] = new Finding(
                    tool: 'composer-audit',
                    file: 'composer.lock',
                    line: 0,
                    rule: $advisoryId,
                    problem: $problem,
                    correction: $correction,
                    severity: $severity,
                    confidence: 'high',
                    evidenceType: 'sca-advisory',
                    provenance: array_values(array_unique($provenance)),
                    metadata: [
                        'component' => self::componentMetadata($packageName, $package, $inventory),
                        'advisory' => array_filter([
                            'id' => $advisoryId,
                            'aliases' => $aliases,
                            'cve' => $cve,
                            'affected_versions' => $affectedVersions,
                            'url' => $link,
                            'reported_at' => $reportedAt,
                            'sources' => $sources,
                        ], static fn (mixed $value): bool => $value !== null && $value !== []),
                    ],
                );
            }
        }

        $abandoned = $data['abandoned'] ?? [];
        if (is_array($abandoned)) {
            foreach ($abandoned as $packageName => $replacement) {
                if (!is_string($packageName) || $packageName === '') {
                    continue;
                }

                $package = $inventory->package($packageName);
                $installedVersion = is_array($package) && is_string($package['version'] ?? null)
                    ? $package['version']
                    : null;
                $replacementPackage = is_string($replacement) && $replacement !== '' ? $replacement : null;

                $findings[] = new Finding(
                    tool: 'composer-audit',
                    file: 'composer.lock',
                    line: 0,
                    rule: 'composer.abandoned',
                    problem: 'Pacote abandonado: ' . $packageName
                        . ($installedVersion !== null ? ' ' . $installedVersion : ''),
                    correction: $replacementPackage !== null
                        ? 'Planeje a substituição por ' . $replacementPackage . '.'
                        : 'Planeje a remoção ou substituição do pacote abandonado.',
                    confidence: 'high',
                    evidenceType: 'dependency-policy',
                    provenance: ['composer-audit'],
                    metadata: [
                        'component' => self::componentMetadata($packageName, $package, $inventory),
                        'policy' => array_filter([
                            'type' => 'abandoned',
                            'replacement' => $replacementPackage,
                        ], static fn (mixed $value): bool => $value !== null),
                    ],
                );
            }
        }

        return $findings;
    }

    /** @param array<string,mixed>|null $package
     *  @return array<string,mixed>
     */
    private static function componentMetadata(
        string $packageName,
        ?array $package,
        SecurityInventory $inventory,
    ): array {
        return array_filter([
            'name' => $packageName,
            'version' => is_array($package) && is_string($package['version'] ?? null)
                ? $package['version']
                : null,
            'relationship' => is_array($package) && is_string($package['relationship'] ?? null)
                ? $package['relationship']
                : 'unknown',
            'scope' => is_array($package) && is_string($package['scope'] ?? null)
                ? $package['scope']
                : 'unknown',
            'inventory_source' => $inventory->packageSource(),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** @return list<array{name:string,remote_id:string}> */
    private static function normalizeSources(mixed $rawSources): array
    {
        if (!is_array($rawSources)) {
            return [];
        }

        $sources = [];
        foreach ($rawSources as $source) {
            if (!is_array($source)) {
                continue;
            }
            $name = self::firstString($source['name'] ?? null);
            $remoteId = self::firstString($source['remoteId'] ?? null, $source['remote_id'] ?? null);
            if ($name === null || $remoteId === null) {
                continue;
            }
            $sources[] = ['name' => $name, 'remote_id' => $remoteId];
        }

        return $sources;
    }

    /** @param list<array{name:string,remote_id:string}> $sources
     *  @return list<string>
     */
    private static function aliases(string $advisoryId, ?string $cve, array $sources): array
    {
        $aliases = [];
        if ($cve !== null && $cve !== $advisoryId) {
            $aliases[] = $cve;
        }
        foreach ($sources as $source) {
            if ($source['remote_id'] !== $advisoryId) {
                $aliases[] = $source['remote_id'];
            }
        }

        return array_values(array_unique($aliases));
    }

    private static function firstRemoteId(mixed $rawSources): ?string
    {
        foreach (self::normalizeSources($rawSources) as $source) {
            return $source['remote_id'];
        }

        return null;
    }

    private static function normalizeSeverity(?string $severity): ?string
    {
        if ($severity === null) {
            return null;
        }

        $normalized = strtolower(trim($severity));
        return $normalized === '' || $normalized === 'none' ? null : $normalized;
    }

    private static function firstString(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }
}
