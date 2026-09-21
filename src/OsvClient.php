<?php

declare(strict_types=1);

require_once __DIR__ . '/Finding.php';
require_once __DIR__ . '/SecurityInventory.php';

/**
 * Consulta a API OSV para os packages Composer resolvidos no SecurityInventory.
 *
 * Entrada principal:
 * - `SecurityInventory` já materializado para o projeto consumidor.
 *
 * Saída principal:
 * - lista de `Finding` com `evidenceType = sca-advisory`, um por combinação
 *   vulnerabilidade/componente afetado retornada pelo OSV.
 *
 * Efeitos externos:
 * - pode realizar chamadas HTTP para `api.osv.dev`;
 * - pode ler a fixture apontada por `NINFA_OSV_FIXTURE` em execução de teste.
 *
 * Invariantes e precedências:
 * - somente packages com `name` e `version` não vazios entram na consulta;
 * - o ecossistema enviado ao OSV é `Packagist`;
 * - `querybatch` é particionado em lotes de até `BATCH_SIZE` componentes;
 * - paginação é acompanhada por componente, não por vulnerabilidade;
 * - registros `withdrawn` são descartados antes da criação de `Finding`;
 * - transport injetado tem precedência sobre fixture e rede real.
 *
 * Esta classe deliberadamente não deduplica Composer Audit + OSV, não calcula
 * exposure/reachability e não define prioridade operacional. Essas decisões
 * pertencem às camadas de agregação posteriores.
 */
final class OsvClient
{
    /** Endpoint padrão da API pública OSV v1. */
    private const DEFAULT_BASE_URL = 'https://api.osv.dev/v1';

    /** Quantidade máxima de componentes enviada em uma chamada `querybatch`. */
    private const BATCH_SIZE = 100;

    /**
     * Transport opcional usado para substituir I/O HTTP em testes.
     *
     * O callable recebe método HTTP, path relativo e payload opcional, devendo
     * devolver um objeto JSON já decodificado como array associativo.
     *
     * @var Closure(string,string,?array<string,mixed>):array<string,mixed>|null
     */
    private readonly ?Closure $transport;

    /** URL base normalizada sem barra final. */
    private readonly string $baseUrl;

    /**
     * Configura o cliente OSV.
     *
     * @param Closure(string,string,?array<string,mixed>):array<string,mixed>|null $transport
     *        Transport determinístico opcional. Quando informado, nenhuma fixture
     *        ou chamada de rede é consultada por `request()`.
     * @param string|null $baseUrl URL base alternativa, usada principalmente em teste.
     */
    public function __construct(?Closure $transport = null, ?string $baseUrl = null)
    {
        $this->transport = $transport;
        $this->baseUrl = rtrim($baseUrl ?? self::DEFAULT_BASE_URL, '/');
    }

    /**
     * Consulta OSV para todos os packages Composer resolvidos no inventário.
     *
     * Packages sem nome ou versão são ignorados porque a consulta OSV deste
     * cliente depende de uma coordenada exata `package + version`. O método
     * acumula IDs retornados por `querybatch`, busca os registros completos,
     * descarta advisories retirados e produz findings ordenados por componente
     * e regra para manter saída determinística.
     *
     * @param SecurityInventory $inventory Inventário SCA já resolvido para o projeto.
     * @return list<Finding> Findings OSV normalizados e correlacionados ao componente.
     * @throws RuntimeException Em falha de transporte/rede ou fixture ausente.
     * @throws UnexpectedValueException Quando a resposta OSV viola o formato esperado.
     * @throws JsonException Quando uma resposta/fixture contém JSON inválido.
     */
    public function scan(SecurityInventory $inventory): array
    {
        /**
         * Packages elegíveis para OSV. O shape reflete `SecurityInventory::normalizePackages()`.
         *
         * @var list<array{
         *   name:string,
         *   version:string,
         *   pretty_version:?string,
         *   direct:bool,
         *   relationship:string,
         *   scope:string,
         *   type:?string
         * }> $packages
         */
        $packages = [];

        // Filtra o inventário para coordenadas que podem ser consultadas sem heurística.
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

        // Sem package resolvido não há consulta possível; ausência de input não é finding.
        if ($packages === []) {
            return [];
        }

        /**
         * Vulnerabilidades indexadas por ID OSV e depois por chave única do componente.
         * O segundo índice evita repetir o mesmo componente durante paginação.
         *
         * @var array<string,array<string,array<string,mixed>>> $matches
         */
        $matches = [];

        // Divide o inventário conforme o limite operacional do querybatch.
        foreach (array_chunk($packages, self::BATCH_SIZE) as $batch) {
            $this->queryBatch($batch, $matches);
        }

        ksort($matches);

        /** @var list<Finding> $findings */
        $findings = [];

        // Enriquecimento por ID: querybatch retorna IDs; /vulns/{id} fornece o advisory completo.
        foreach ($matches as $vulnerabilityId => $components) {
            /** @var array<string,mixed> $record */
            $record = $this->request('GET', '/vulns/' . rawurlencode($vulnerabilityId));

            // Advisory retirado não deve continuar aparecendo como vulnerabilidade vigente.
            if (isset($record['withdrawn']) && is_string($record['withdrawn']) && $record['withdrawn'] !== '') {
                continue;
            }

            foreach ($components as $component) {
                $findings[] = $this->finding($record, $component, $inventory);
            }
        }

        // Ordenação estável facilita diff de relatório e testes determinísticos.
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
     * Executa `querybatch` e acrescenta os matches ao acumulador da varredura.
     *
     * A paginação é carregada em `$pending`: cada item conserva o componente que
     * originou a consulta e o `next_page_token` correspondente. Isso impede
     * associar uma página posterior ao package errado quando apenas parte do
     * lote original ainda possui resultados pendentes.
     *
     * @param list<array<string,mixed>> $packages Componentes resolvidos deste lote.
     * @param array<string,array<string,array<string,mixed>>> $matches
     *        Acumulador mutável indexado por vulnerability ID e componente.
     * @return void
     * @throws RuntimeException Em falha de transporte/rede.
     * @throws UnexpectedValueException Quando `querybatch` retorna estrutura incompatível.
     * @throws JsonException Quando a resposta HTTP contém JSON inválido.
     */
    private function queryBatch(array $packages, array &$matches): void
    {
        /**
         * Fila de páginas pendentes por componente.
         *
         * @var list<array{component:array<string,mixed>,page_token:?string}> $pending
         */
        $pending = array_map(
            static fn (array $package): array => ['component' => $package, 'page_token' => null],
            $packages,
        );

        // Cada iteração consome uma página de todos os componentes ainda pendentes.
        while ($pending !== []) {
            /**
             * Payload posicional do querybatch. A API devolve `results` na mesma ordem.
             *
             * @var list<array{
             *   version:mixed,
             *   package:array{name:mixed,ecosystem:string},
             *   page_token?:string
             * }> $queries
             */
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

            /** @var array<string,mixed> $response */
            $response = $this->request('POST', '/querybatch', ['queries' => $queries]);
            $results = $response['results'] ?? null;

            // A correlação usa posição; contagem divergente tornaria o mapeamento inseguro.
            if (!is_array($results) || count($results) !== count($pending)) {
                throw new UnexpectedValueException('OSV querybatch retornou quantidade de resultados incompatível com a consulta.');
            }

            /** @var list<array{component:array<string,mixed>,page_token:?string}> $next */
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

                // Registra apenas IDs válidos; detalhes são obtidos depois em /vulns/{id}.
                foreach ($vulnerabilities as $vulnerability) {
                    if (!is_array($vulnerability) || !is_string($vulnerability['id'] ?? null) || $vulnerability['id'] === '') {
                        continue;
                    }
                    $matches[$vulnerability['id']][$componentKey] = $component;
                }

                // Apenas componentes paginados retornam para a próxima rodada.
                $token = $result['next_page_token'] ?? null;
                if (is_string($token) && $token !== '') {
                    $next[] = ['component' => $component, 'page_token' => $token];
                }
            }

            $pending = $next;
        }
    }

    /**
     * Converte um advisory OSV completo e um componente do inventário em Finding.
     *
     * @param array<string,mixed> $record Registro retornado por `/vulns/{id}`.
     * @param array<string,mixed> $component Package correlacionado pelo querybatch.
     * @param SecurityInventory $inventory Inventário usado para registrar a fonte do package.
     * @return Finding Finding SCA com metadata de componente e advisory OSV.
     * @throws UnexpectedValueException Quando o registro não possui ID utilizável.
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

    /**
     * Extrai a primeira severidade textual reconhecida para o package informado.
     *
     * A ordem atual é `database_specific.severity` seguida de
     * `affected[].ecosystem_specific.severity` do package correspondente. O
     * método normaliza `moderate` para `medium`; ele não calcula CVSS nem escolhe
     * a maior severidade entre múltiplas fontes.
     *
     * @param array<string,mixed> $record Registro OSV completo.
     * @param string $packageName Nome Packagist usado para limitar `affected`.
     * @return string|null `critical`, `high`, `medium`, `low` ou null.
     */
    private static function severity(array $record, string $packageName): ?string
    {
        /** @var list<mixed> $candidates */
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

        // A primeira severidade textual válida segundo a precedência acima vence.
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

    /**
     * Retorna a primeira URL de referência não vazia presente no campo OSV.
     *
     * @param mixed $references Valor bruto de `references` recebido do OSV.
     * @return string|null URL normalizada com `trim()` ou null quando ausente/inválida.
     */
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

    /**
     * Normaliza uma coleção bruta para strings únicas e não vazias.
     *
     * @param mixed $values Valor potencialmente retornado por uma chave JSON OSV.
     * @return list<string> Strings após `trim()`, preservando a primeira ocorrência.
     */
    private static function strings(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }

        /** @var list<string> $strings */
        $strings = [];
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                $strings[] = trim($value);
            }
        }

        return array_values(array_unique($strings));
    }

    /**
     * Retorna a primeira string não vazia entre candidatos heterogêneos.
     *
     * @param mixed ...$values Valores candidatos na ordem de precedência desejada.
     * @return string|null Primeira string útil após `trim()` ou null.
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

    /**
     * Executa uma requisição OSV segundo a precedência transport -> fixture -> HTTP.
     *
     * O caminho informado é sempre relativo a `$baseUrl`. Em rede real, usa o
     * stream wrapper HTTP do PHP com timeout de 15 segundos, `ignore_errors`
     * habilitado para permitir inspeção do status e cabeçalho User-Agent próprio.
     *
     * @param string $method Método HTTP, atualmente `GET` ou `POST`.
     * @param string $path Path relativo iniciado por `/`.
     * @param array<string,mixed>|null $payload Corpo JSON opcional para POST.
     * @return array<string,mixed> Objeto JSON decodificado.
     * @throws RuntimeException Em erro de rede, status HTTP fora de 2xx ou fixture ausente.
     * @throws UnexpectedValueException Quando transport/OSV não devolvem objeto compatível.
     * @throws JsonException Quando encode/decode do JSON falha.
     */
    private function request(string $method, string $path, ?array $payload = null): array
    {
        // Transport injetado é a primeira opção para testes unitários sem I/O real.
        if ($this->transport instanceof Closure) {
            $response = ($this->transport)($method, $path, $payload);
            if (!is_array($response)) {
                throw new UnexpectedValueException('Transport OSV retornou resposta inválida.');
            }
            return $response;
        }

        // Fixture de ambiente permite teste de integração determinístico do pipeline.
        $fixture = getenv('NINFA_OSV_FIXTURE');
        if (is_string($fixture) && $fixture !== '') {
            return $this->fixtureResponse($fixture, $method, $path);
        }

        /** @var list<string> $headers */
        $headers = [
            'Accept: application/json',
            'User-Agent: Ninfa-Security/0.1',
        ];

        /** @var array<string,mixed> $options */
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

        // Falha de transporte é erro de execução, nunca evidência de ausência de CVE.
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

    /**
     * Resolve uma resposta OSV a partir do arquivo de fixture de teste.
     *
     * O formato aceito possui uma chave `querybatch` para POST /querybatch e um
     * mapa `vulns` indexado pelo ID usado em GET /vulns/{id}. Qualquer operação
     * não coberta é erro explícito para impedir fixture silenciosamente parcial.
     *
     * @param string $file Caminho do JSON de fixture.
     * @param string $method Método HTTP lógico da chamada simulada.
     * @param string $path Path lógico da chamada simulada.
     * @return array<string,mixed> Resposta simulada para a operação solicitada.
     * @throws RuntimeException Quando o arquivo não existe.
     * @throws UnexpectedValueException Quando o formato não cobre a operação.
     * @throws JsonException Quando a fixture contém JSON inválido.
     */
    private function fixtureResponse(string $file, string $method, string $path): array
    {
        if (!is_file($file)) {
            throw new RuntimeException('Fixture OSV não encontrada: ' . $file);
        }

        $decoded = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new UnexpectedValueException('Fixture OSV inválida.');
        }

        // Reproduz a resposta batch usada para descoberta de IDs.
        if ($method === 'POST' && $path === '/querybatch') {
            $response = $decoded['querybatch'] ?? null;
            if (is_array($response)) {
                return $response;
            }
        }

        // Reproduz a consulta individual usada para enriquecer cada advisory.
        if ($method === 'GET' && str_starts_with($path, '/vulns/')) {
            $id = rawurldecode(substr($path, strlen('/vulns/')));
            $response = $decoded['vulns'][$id] ?? null;
            if (is_array($response)) {
                return $response;
            }
        }

        throw new UnexpectedValueException('Fixture OSV não cobre ' . $method . ' ' . $path . '.');
    }

    /**
     * Extrai o primeiro status HTTP reconhecível da lista de headers do wrapper.
     *
     * @param list<string> $headers Headers disponibilizados pelo stream wrapper.
     * @return int Código HTTP de três dígitos ou 0 quando não identificável.
     */
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
