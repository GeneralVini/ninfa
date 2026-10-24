<?php

declare(strict_types=1);

/**
 * Carrega o catálogo SEC-04.01 e rejeita deriva das fontes revisadas.
 *
 * Esta versão descreve capacidades, sem executar motores ou autorizar supressão.
 * Hash de payload no lock é referência de revisão; a instalação efetiva ainda
 * precisa ser verificada pelo doctor e vinculada ao futuro plano de execução.
 */
final class CapabilityCatalog implements JsonSerializable
{
    /**
     * Preserva o documento validado sem expor estado mutável ao chamador.
     *
     * @param array<string,mixed> $document Catálogo já validado.
     */
    private function __construct(private readonly array $document)
    {
    }

    /**
     * Lê o catálogo e confere versões, referências, ownership e limites de equivalência.
     *
     * A raiz explícita permite testes isolados sem depender de ferramentas instaladas.
     * Arquivos locais e o lock precisam corresponder aos hashes revisados.
     */
    public static function load(string $root, string $relativePath = 'security/capabilities.v1.json'): self
    {
        $data = self::readJson(self::repositoryPath($root, $relativePath));
        self::require(($data['schema_version'] ?? null) === 1, 'schema_version não suportada');
        self::require(is_string($data['catalog_version'] ?? null)
            && preg_match('/^1\.\d+\.\d+$/D', $data['catalog_version']) === 1, 'catalog_version inválida');
        self::require(is_string($data['scope'] ?? null) && $data['scope'] !== '', 'escopo ausente');
        self::require(($data['policy']['unlisted_rules'] ?? null) === 'uncontrolled-overlap', 'política de regras desconhecidas inválida');
        self::require(($data['policy']['equivalence'] ?? null) === 'No approved cross-engine equivalence in v1; suppression forbidden.', 'política de equivalência inválida');
        self::require(is_string($data['policy']['runtime_binding'] ?? null), 'vínculo runtime ausente');

        $lockRef = $data['tool_lock'] ?? [];
        self::require(is_array($lockRef) && ($lockRef['path'] ?? null) === 'security/web-tools.lock.json', 'lock inválido');
        $lockPath = self::repositoryPath($root, $lockRef['path']);
        self::require(hash_file('sha256', $lockPath) === ($lockRef['sha256'] ?? null), 'hash do lock divergiu');
        $lock = self::readJson($lockPath);
        self::require(is_array($data['tools'] ?? null) && $data['tools'] !== [], 'versões ausentes');
        $toolNames = array_keys($data['tools']);
        sort($toolNames);
        self::require($toolNames === ['nuclei', 'nuclei-templates', 'psalm', 'semgrep', 'zap'], 'conjunto de ferramentas incompleto');
        foreach ($data['tools'] as $tool => $binding) {
            self::require(is_array($binding) && isset($lock['tools'][$tool]), 'ferramenta desconhecida');
            self::require(($binding['version'] ?? null) === $lock['tools'][$tool]['version'], 'versão divergiu: ' . $tool);
            self::require(($binding['revision'] ?? null) === ($lock['tools'][$tool]['revision'] ?? null), 'revisão divergiu: ' . $tool);
        }

        // Artefatos do motor são conferidos contra o lock; fontes Ninfa, contra o disco.
        $artifacts = $data['artifacts'] ?? null;
        self::require(is_array($artifacts) && $artifacts !== [], 'artefatos ausentes');
        foreach ($artifacts as $artifact) {
            self::require(is_array($artifact) && is_string($artifact['path'] ?? null), 'artefato inválido');
            self::require(is_string($artifact['sha256'] ?? null)
                && preg_match('/^[a-f0-9]{64}$/D', $artifact['sha256']) === 1, 'hash inválido');
            if (($artifact['origin'] ?? null) === 'repository') {
                $actual = hash_file('sha256', self::repositoryPath($root, $artifact['path']));
            } else {
                self::require(($artifact['origin'] ?? null) === 'tool-lock', 'origem desconhecida');
                $actual = $lock['tools'][$artifact['tool'] ?? '']['files'][$artifact['path']] ?? null;
            }
            self::require($actual === $artifact['sha256'], 'artefato divergiu: ' . $artifact['path']);
        }

        self::require(is_array($data['capabilities'] ?? null) && array_is_list($data['capabilities'])
            && $data['capabilities'] !== [], 'capacidades ausentes');
        /** @var array<string,true> $ids Identificadores já declarados no catálogo. */
        $ids = [];
        /** @var array<string,true> $selectors Ownership único por seletor nativo revisado. */
        $selectors = [];
        foreach ($data['capabilities'] as $capability) {
            self::require(is_array($capability), 'capacidade inválida');
            foreach (['id', 'owner', 'objective', 'technique', 'granularity'] as $field) {
                self::require(is_string($capability[$field] ?? null) && trim($capability[$field]) !== '', 'campo ausente: ' . $field);
            }
            $id = $capability['id'];
            self::require(!isset($ids[$id]), 'capacidade duplicada: ' . $id);
            $ids[$id] = true;
            self::require(in_array($capability['owner'], ['composer-audit', 'osv', 'semgrep', 'psalm', 'zap', 'nuclei'], true), 'proprietário desconhecido');
            self::require(is_array($capability['conditions'] ?? null) && array_is_list($capability['conditions'])
                && $capability['conditions'] !== [], 'condições ausentes');
            foreach ($capability['conditions'] as $condition) {
                self::require(is_string($condition) && trim($condition) !== '', 'condição inválida');
            }
            // Nenhuma alternativa da v1 foi comprovada como substituta de cobertura.
            $alternative = $capability['alternative'] ?? null;
            self::require(is_array($alternative) && array_key_exists('capability', $alternative)
                && $alternative['capability'] === null && is_string($alternative['reason'] ?? null)
                && trim($alternative['reason']) !== '', 'alternativa não aprovada');
            self::require(is_array($capability['selectors'] ?? null) && array_is_list($capability['selectors'])
                && $capability['selectors'] !== [], 'seletores ausentes');
            foreach ($capability['selectors'] as $selector) {
                self::require(is_array($selector) && is_string($selector['id'] ?? null)
                    && trim($selector['id']) !== '' && is_string($selector['artifact'] ?? null)
                    && isset($artifacts[$selector['artifact']]), 'seletor inválido');
                $allowed = match ($capability['owner']) {
                    'semgrep' => ['semgrep-rule'], 'psalm' => ['psalm-issue'],
                    'zap' => ['zap-passive-rule', 'zap-active-rule'],
                    'nuclei' => ['nuclei-template'], default => ['operation'],
                };
                self::require(in_array($selector['kind'] ?? null, $allowed, true), 'tipo de seletor incompatível');
                $source = $artifacts[$selector['artifact']];
                $expectedTool = match ($capability['owner']) {
                    'nuclei' => 'nuclei-templates', 'zap' => 'zap', 'psalm' => 'psalm', default => null,
                };
                self::require($expectedTool === null
                    ? $source['origin'] === 'repository'
                    : ($source['tool'] ?? null) === $expectedTool && $source['origin'] === 'tool-lock', 'artefato incompatível com proprietário');
                if ($capability['owner'] === 'nuclei') {
                    self::require(str_ends_with($source['path'], '.yaml')
                        && is_array($selector['matchers'] ?? null) && array_is_list($selector['matchers'])
                        && $selector['matchers'] !== [], 'matchers Nuclei ausentes');
                    foreach ($selector['matchers'] as $matcher) {
                        self::require(is_string($matcher) && trim($matcher) !== '', 'matcher inválido');
                    }
                    self::require(count(array_unique($selector['matchers'])) === count($selector['matchers']), 'matcher duplicado');
                }
                $key = $capability['owner'] . ':' . $selector['artifact'] . ':' . $selector['id'];
                self::require(!isset($selectors[$key]), 'seletor com ownership duplicado');
                $selectors[$key] = true;
                if ($capability['owner'] === 'semgrep') {
                    self::require($source['origin'] === 'repository', 'regras Semgrep devem ser locais');
                    $rules = file_get_contents(self::repositoryPath($root, $source['path']));
                    self::require(preg_match('/^  - id: ' . preg_quote($selector['id'], '/') . '$/m', (string) $rules) === 1, 'regra Semgrep inexistente');
                }
            }
        }

        self::require(is_array($data['relationships'] ?? null) && array_is_list($data['relationships']), 'relações ausentes');
        /** @var array<string,true> $pairs Relações não direcionais já revisadas. */
        $pairs = [];
        foreach ($data['relationships'] as $relation) {
            self::require(is_array($relation), 'relação inválida');
            $left = $relation['left'] ?? null;
            $right = $relation['right'] ?? null;
            self::require(is_string($left) && is_string($right) && $left !== $right
                && isset($ids[$left], $ids[$right]), 'referência de relação inválida');
            $pair = [$left, $right];
            sort($pair);
            $key = implode(':', $pair);
            self::require(!isset($pairs[$key]), 'relação duplicada');
            $pairs[$key] = true;
            self::require(in_array($relation['status'] ?? null, ['candidate', 'complementary'], true)
                && ($relation['suppress'] ?? null) === false, 'supressão não aprovada na v1');
            self::require(is_string($relation['reason'] ?? null) && trim($relation['reason']) !== '', 'justificativa ausente');
        }

        return new self($data);
    }

    /**
     * Retorna uma cópia serializável para inspeção e futuro planejamento.
     *
     * @return array<string,mixed> Documento original validado.
     */
    public function jsonSerialize(): array
    {
        return $this->document;
    }

    /** Interrompe o carregamento antes que metadados inválidos alimentem o planejador. */
    private static function require(bool $valid, string $message): void
    {
        if (!$valid) {
            throw new UnexpectedValueException('CapabilityCatalog: ' . $message);
        }
    }

    /** Resolve somente arquivos contidos fisicamente no repositório, inclusive com symlinks. */
    private static function repositoryPath(string $root, string $relative): string
    {
        $base = realpath($root);
        self::require($base !== false && $relative !== '' && !str_starts_with($relative, '/')
            && !in_array('..', explode('/', $relative), true), 'caminho inválido');
        $path = realpath($base . '/' . $relative);
        self::require($path !== false && is_file($path) && str_starts_with($path, $base . '/'), 'arquivo fora da raiz ou ausente');
        return $path;
    }

    /**
     * Decodifica objetos JSON exigindo uma raiz associativa.
     *
     * @return array<string,mixed> Documento JSON lido do disco.
     */
    private static function readJson(string $path): array
    {
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::require(is_array($data) && !array_is_list($data), 'objeto JSON esperado');
        return $data;
    }
}
