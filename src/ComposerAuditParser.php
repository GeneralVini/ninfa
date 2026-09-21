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
    /**
     * Normaliza o documento JSON produzido por `composer audit --format=json`.
     *
     * Advisories sem nome de package são ignorados porque não podem ser
     * correlacionados ao inventário. Package abandonado é emitido como policy
     * finding separado. O método não assume que exit code do Composer define a
     * existência de vulnerabilidade; essa decisão permanece no runner.
     *
     * @param string $json Saída stdout do Composer Audit.
     * @param SecurityInventory $inventory Inventário usado para enriquecer componente/versão/escopo.
     * @return list<Finding> Advisories e policy findings normalizados.
     * @throws JsonException Quando o conteúdo não é JSON válido.
     * @throws UnexpectedValueException Quando a raiz ou `advisories` possuem formato incompatível.
     */
    public static function parse(string $json, SecurityInventory $inventory): array
    {
        /** @var mixed $data Documento decodificado do Composer Audit. */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new UnexpectedValueException('Saída JSON do Composer Audit inválida.');
        }

        /** @var list<Finding> $findings Findings SCA/policy preservados na ordem da fonte. */
        $findings = [];
        $advisories = $data['advisories'] ?? [];
        if (!is_array($advisories)) {
            throw new UnexpectedValueException('Campo advisories inválido na saída do Composer Audit.');
        }

        // Cada chave de package pode conter múltiplos advisories independentes.
        foreach ($advisories as $packageKey => $packageAdvisories) {
            if (!is_array($packageAdvisories)) {
                continue;
            }

            foreach ($packageAdvisories as $advisory) {
                if (!is_array($advisory)) {
                    continue;
                }

                // O payload pode trazer packageName no advisory ou somente como chave externa.
                $packageName = self::firstString(
                    $advisory['packageName'] ?? null,
                    is_string($packageKey) ? $packageKey : null,
                );
                if ($packageName === null) {
                    continue;
                }

                // ID oficial tem precedência; CVE/fonte remota funcionam como fallback auditável.
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

                /** @var list<string> $provenance Fonte principal e IDs remotos que sustentam o finding. */
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

        // Abandono é informação de manutenção/policy e nunca recebe evidenceType de vulnerabilidade.
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

    /**
     * Constrói a metadata comum do componente usando fatos do SecurityInventory.
     *
     * Valores desconhecidos de relacionamento/escopo são mantidos como
     * `unknown`; versão ausente é omitida por `array_filter`. Nenhuma inferência
     * de direct/transitive é refeita aqui.
     *
     * @param string $packageName Nome Packagist/Composer do componente.
     * @param array<string,mixed>|null $package Registro resolvido no inventário ou null.
     * @param SecurityInventory $inventory Inventário que informa a origem dos packages.
     * @return array<string,mixed> Metadata normalizada do componente.
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

    /**
     * Converte o campo `sources` do Composer para uma lista mínima e estável.
     *
     * Entradas sem `name` ou remote ID textual são descartadas porque não
     * fornecem proveniência identificável.
     *
     * @param mixed $rawSources Valor bruto de `sources` no advisory.
     * @return list<array{name:string,remote_id:string}> Fontes válidas preservadas na ordem original.
     */
    private static function normalizeSources(mixed $rawSources): array
    {
        if (!is_array($rawSources)) {
            return [];
        }

        /** @var list<array{name:string,remote_id:string}> $sources */
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

    /**
     * Deriva aliases diferentes do ID principal a partir de CVE e fontes remotas.
     *
     * @param string $advisoryId ID escolhido como regra principal do finding.
     * @param string|null $cve CVE informado diretamente pelo Composer, quando existe.
     * @param list<array{name:string,remote_id:string}> $sources Fontes normalizadas do advisory.
     * @return list<string> Aliases únicos sem repetir o ID principal.
     */
    private static function aliases(string $advisoryId, ?string $cve, array $sources): array
    {
        /** @var list<string> $aliases */
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

    /**
     * Retorna o primeiro remote ID válido disponível no campo de fontes.
     *
     * @param mixed $rawSources Valor bruto recebido do Composer.
     * @return string|null Primeiro ID remoto normalizado ou null quando não existe.
     */
    private static function firstRemoteId(mixed $rawSources): ?string
    {
        foreach (self::normalizeSources($rawSources) as $source) {
            return $source['remote_id'];
        }

        return null;
    }

    /**
     * Normaliza severidade textual sem inventar classificação quando a fonte usa `none`.
     *
     * @param string|null $severity Valor bruto do advisory.
     * @return string|null Severidade em lowercase ou null para vazio/none.
     */
    private static function normalizeSeverity(?string $severity): ?string
    {
        if ($severity === null) {
            return null;
        }

        $normalized = strtolower(trim($severity));
        return $normalized === '' || $normalized === 'none' ? null : $normalized;
    }

    /**
     * Seleciona o primeiro valor textual não vazio entre alternativas de payload.
     *
     * @param mixed ...$values Valores candidatos em ordem de precedência.
     * @return string|null Primeiro texto trimado não vazio ou null.
     */
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
