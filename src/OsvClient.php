<?php

declare(strict_types=1);

require_once __DIR__ . '/Finding.php';
require_once __DIR__ . '/SecurityInventory.php';

final class OsvClient
{
    private const DEFAULT_BASE_URL = 'https://api.osv.dev/v1';
    private const BATCH_SIZE = 100;

    /** @var Closure(string,string,?array):array<string,mixed>|null */
    private readonly ?Closure $transport;

    public function __construct(?Closure $transport = null, ?string $baseUrl = null)
    {
        $this->transport = $transport;
        $this->baseUrl = rtrim($baseUrl ?? self::DEFAULT_BASE_URL, '/');
    }

    private readonly string $baseUrl;

    /** @return list<Finding> */
    public function scan(SecurityInventory $inventory): array
    {
        $packages = [];
        foreach (($inventory->toArray()['composer']['packages'] ?? []) as $package) {
            if (!is_array($package)) {
                continue;
            }
            if (!is_string($package['name'] ?? null) || $package['name'] === '') {
                continue;
            }
            if (!is_string($package['version'] ?? null) || $package['version'] === '') {
                continue;
            }
            $packages[] = $package;
        }

        if ($packages === []) {
            return [];
        }

        /** @var array<string,array<string,array<string,mixed>>> $matches */
        $matches = [];
        foreach (array_chunk($packages, self::BATCH_SIZE) as $batch) {
            $this->queryBatch($batch, $matches);
        }

        ksort($matches);
        $findings = [];
        foreach ($matches as $vulnerabilityId => $components) {
            $record = $this->request('GET', '/vulns/' . rawurlencode($vulnerabilityId));
            if (isset($record['withdrawn']) && is_string($record['withdrawn']) && $record['withdrawn'] !== '') {
                continue;
            }

            foreach ($components as $component) {
                $findings[] = $this->finding($record, $component, $inventory);
            }
        }

        usort(
            $findings,
            static fn (Finding $a, Finding $b): int => [
                (string) ($a->metadata['component']['name'] ?? ''),
                $a->rule,
            ] <=> [
                (string) ($b->metadata['component']['name'] ?? ''),
                $b->rule,
            ],
        );

        return $findings;
    }

    /**
     * @param list<array<string,mixed>> $packages
     * @param array<string,array<string,array<string,mixed>>> $matches
     */
    private function queryBatch(array $packages, array &$matches): void
    {
        $pending = array_map(
            static fn (array $package): array => ['component' => $package, 'page_token' => null],
            $packages,
        );

        while ($pending !== []) {
            $queries = [];
            foreach ($pending as $entry) {
                $component = $entry['component'];
                $query = [
                    'version' => $component['version'],
                    'package' => [
                        'name' => $component['name'],
                        'ecosystem' => 'Packagist',
                    ],
                ];
                if (is_string($entry['page_token']) && $entry['page_token'] !== '') {
                    $query['page_token'] = $entry['page_token'];
                }
                $queries[] = $query;
            }

            $response = $this->request('POST', '/querybatch', ['queries' => $queries]);
            $results = $response['results'] ?? null;
            if (!is_array($results) || count($results) !== count($pending)) {
                throw new UnexpectedValueException('OSV querybatch retornou quantidade de resultados incompatível com a consulta.');
            }

            $next = [];
            foreach ($pending as $index => $entry) {
                $result = $results[$index] ?? null;
                if (!is_array($result)) {
                    throw new UnexpectedValueException('OSV querybatch retornou item inválido.');
                }

                $component = $entry['component'];
                $componentKey = $component['name'] . '@' . $component['version'] . ':' . ($component['scope'] ?? 'unknown');
                $vulnerabilities = $result['vulns'] ?? [];
                if (!is_array($vulnerabilities)) {
                    throw new UnexpectedValueException('OSV querybatch retornou campo vulns inválido.');
                }

                foreach ($vulnerabilities as $vulnerability) {
                    if (!is_array($vulnerability) || !is_string($vulnerability['id'] ?? null) || $vulnerability['id'] === '') {
                        continue;
                    }
                    $matches[$vulnerability['id']][$componentKey] = $component;
                }

                $token = $result['next_page_token'] ?? null;
                if (is_string($token) && $token !== '') {
                    $next[] = ['component' => $component, 'page_token' => $token];
                }
            }

            $pending = $next;
        }
    }

    /** @param array<string,mixed> $record
     *  @param array<string,mixed> $component
     */
    private function finding(array $record, array $component, SecurityInventory $inventory): Finding
    {
        $id = self::firstString($record['id'] ?? null);
        if ($id === null) {
            throw new UnexpectedValueException('Registro OSV sem id.');
        }

        $name = (string) $component['name'];
        $version = (string) $component['version'];
        $summary = self::firstString($record['summary'] ?? null, $record['details'] ?? null)
            ?? 'Vulnerabilidade conhecida no OSV';
        $aliases = self::strings($record['aliases'] ?? []);
        $reference = self::firstReference($record['references'] ?? []);
        $severity = self::severity($record, $name);

        $correction = 'Atualize ' . $name . ' para uma versão não afetada segundo o advisory OSV.';
        if ($reference !== null) {
            $correction .= ' Consulte ' . $reference;
        }

        return new Finding(
            tool: 'osv',
            file: $inventory->packageSource() === 'composer.lock' ? 'composer.lock' : 'vendor/composer/installed.json',
            line: 0,
            rule: $id,
            problem: $summary . ' afeta ' . $name . ' ' . $version,
            correction: $correction,
            severity: $severity,
            confidence: 'high',
            evidenceType: 'sca-advisory',
            provenance: ['osv', 'osv:' . $id],
            metadata: [
                'component' => [
                    'name' => $name,
                    'version' => $version,
                    'relationship' => is_string($component['relationship'] ?? null) ? $component['relationship'] : 'unknown',
                    'scope' => is_string($component['scope'] ?? null) ? $component['scope'] : 'unknown',
                    'inventory_source' => $inventory->packageSource(),
                ],
                'advisory' => array_filter([
                    'id' => $id,
                    'aliases' => $aliases,
                    'url' => 'https://osv.dev/vulnerability/' . rawurlencode($id),
                    'published' => self::firstString($record['published'] ?? null),
                    'modified' => self::firstString($record['modified'] ?? null),
                    'reference' => $reference,
                    'severity' => is_array($record['severity'] ?? null) ? $record['severity'] : [],
                ], static fn (mixed $value): bool => $value !== null && $value !== []),
            ],
        );
    }

    /** @param array<string,mixed> $record */
    private static function severity(array $record, string $packageName): ?string
    {
        $candidates = [];
        if (is_array($record['database_specific'] ?? null)) {
            $candidates[] = $record['database_specific']['severity'] ?? null;
        }

        $affected = $record['affected'] ?? [];
        if (is_array($affected)) {
            foreach ($affected as $entry) {
                if (!is_array($entry) || !is_array($entry['package'] ?? null)) {
                    continue;
                }
                if (($entry['package']['name'] ?? null) !== $packageName) {
                    continue;
                }
                if (is_array($entry['ecosystem_specific'] ?? null)) {
                    $candidates[] = $entry['ecosystem_specific']['severity'] ?? null;
                }
            }
        }

        foreach ($candidates as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }
            $normalized = strtolower(trim($candidate));
            if ($normalized === 'moderate') {
                return 'medium';
            }
            if (in_array($normalized, ['critical', 'high', 'medium', 'low'], true)) {
                return $normalized;
            }
        }

        return null;
    }

    private static function firstReference(mixed $references): ?string
    {
        if (!is_array($references)) {
            return null;
        }
        foreach ($references as $reference) {
            if (is_array($reference) && is_string($reference['url'] ?? null) && trim($reference['url']) !== '') {
                return trim($reference['url']);
            }
        }
        return null;
    }

    /** @return list<string> */
    private static function strings(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }
        $strings = [];
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                $strings[] = trim($value);
            }
        }
        return array_values(array_unique($strings));
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

    /** @param array<string,mixed>|null $payload
     *  @return array<string,mixed>
     */
    private function request(string $method, string $path, ?array $payload = null): array
    {
        if ($this->transport instanceof Closure) {
            $response = ($this->transport)($method, $path, $payload);
            if (!is_array($response)) {
                throw new UnexpectedValueException('Transport OSV retornou resposta inválida.');
            }
            return $response;
        }

        $fixture = getenv('NINFA_OSV_FIXTURE');
        if (is_string($fixture) && $fixture !== '') {
            return $this->fixtureResponse($fixture, $method, $path);
        }

        $headers = [
            'Accept: application/json',
            'User-Agent: Ninfa-Security/0.1',
        ];
        $options = [
            'method' => $method,
            'timeout' => 15,
            'ignore_errors' => true,
        ];
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
            $options['content'] = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
        $options['header'] = implode("\r\n", $headers) . "\r\n";

        $context = stream_context_create(['http' => $options]);
        $response = @file_get_contents($this->baseUrl . $path, false, $context);
        $status = self::httpStatus($http_response_header ?? []);
        if ($response === false || $status < 200 || $status >= 300) {
            throw new RuntimeException(
                'Falha ao consultar OSV (' . $method . ' ' . $path . ', HTTP ' . ($status ?: 'indisponível') . ').',
            );
        }

        $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new UnexpectedValueException('OSV retornou JSON inválido.');
        }
        return $decoded;
    }

    /** @return array<string,mixed> */
    private function fixtureResponse(string $file, string $method, string $path): array
    {
        if (!is_file($file)) {
            throw new RuntimeException('Fixture OSV não encontrada: ' . $file);
        }
        $decoded = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new UnexpectedValueException('Fixture OSV inválida.');
        }

        if ($method === 'POST' && $path === '/querybatch') {
            $response = $decoded['querybatch'] ?? null;
            if (is_array($response)) {
                return $response;
            }
        }

        if ($method === 'GET' && str_starts_with($path, '/vulns/')) {
            $id = rawurldecode(substr($path, strlen('/vulns/')));
            $response = $decoded['vulns'][$id] ?? null;
            if (is_array($response)) {
                return $response;
            }
        }

        throw new UnexpectedValueException('Fixture OSV não cobre ' . $method . ' ' . $path . '.');
    }

    /** @param list<string> $headers */
    private static function httpStatus(array $headers): int
    {
        foreach ($headers as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/', $header, $matches) === 1) {
                return (int) $matches[1];
            }
        }
        return 0;
    }
}
