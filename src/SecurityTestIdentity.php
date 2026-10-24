<?php

declare(strict_types=1);

require_once __DIR__ . '/CapabilityCatalog.php';

/**
 * Identifica uma unidade de cobertura SEC-04.02 sem decidir sua execução.
 *
 * A identidade contém somente seletores revisados, enums e digests de contexto.
 * Dados desconhecidos impedem comparação e recebem nonce próprio: nunca viram
 * evidência de equivalência por coincidência de URL, CWE, arquivo ou run_id.
 */
final class SecurityTestIdentity implements JsonSerializable
{
    /**
     * Mantém a chave e o descritor imutáveis após validação.
     *
     * @param array<string,mixed> $descriptor Campos canônicos usados no hash.
     * @param list<string> $limitations Razões que impedem comparação segura.
     */
    private function __construct(
        public readonly string $key,
        public readonly bool $verifiable,
        private readonly array $descriptor,
        public readonly array $limitations,
    ) {
    }

    /**
     * Vincula uma capacidade a fatos efetivos fornecidos pelo adapter futuro.
     *
     * Todos os campos são explícitos; null representa desconhecido ou não
     * aplicável conforme a fonte. Digests devem vir de metadados sem segredos,
     * nunca de senhas, tokens ou cookies. O método não descobre fatos no host.
     *
     * @param array{run_id:string,context:array<string,mixed>,asset:array<string,mixed>,request:array<string,mixed>,bindings:array<string,mixed>} $input Fatos completos de contexto e motor.
     */
    public static function create(CapabilityCatalog $catalog, string $capabilityId, array $input): self
    {
        self::fields($input, ['run_id', 'context', 'asset', 'request', 'bindings']);
        self::require(is_string($input['run_id']) && preg_match('/^[a-f0-9]{32}$/D', $input['run_id']) === 1, 'run_id inválido');
        $document = $catalog->jsonSerialize();
        $capability = self::capability($document, $capabilityId);
        $owner = $capability['owner'];
        $web = in_array($owner, ['zap', 'nuclei'], true);
        $sca = in_array($owner, ['composer-audit', 'osv'], true);

        $context = $input['context'];
        self::fields($context, ['project_digest', 'scope_digest', 'authorization_digest', 'code_digest',
            'deployment_digest', 'deployment_link', 'host_digest', 'profile', 'role_digest', 'session_context_digest']);
        foreach (['project_digest', 'scope_digest', 'authorization_digest'] as $field) {
            self::digest($context[$field], false);
        }
        foreach (['code_digest', 'deployment_digest', 'host_digest', 'role_digest', 'session_context_digest'] as $field) {
            self::digest($context[$field], true);
        }
        self::require(in_array($context['profile'], ['php-generic', 'yii2', 'yii3', 'glpi-plugin'], true), 'profile inválido');
        self::require(in_array($context['deployment_link'], ['verified', 'declared', 'unknown', 'not-applicable'], true), 'vínculo de deploy inválido');
        self::require($web || ($context['deployment_link'] === 'not-applicable'
            && $context['deployment_digest'] === null && $context['role_digest'] === null
            && $context['session_context_digest'] === null), 'dimensões web indevidas para código/inventário');

        $asset = $input['asset'];
        self::fields($asset, ['kind', 'digest']);
        self::digest($asset['digest'], false);
        $passive = $capability['technique'] === 'passive-response';
        $expectedKind = $web ? ($passive ? 'http-response' : 'http-target') : ($sca ? 'component-inventory' : 'codebase');
        self::require($asset['kind'] === $expectedKind, 'granularidade de ativo incompatível');

        $request = $input['request'];
        self::fields($request, ['method', 'parameter_location', 'parameter_digest']);
        self::require($request['method'] === null || (is_string($request['method'])
            && preg_match('/^[A-Za-z0-9!#$%&\x27*+.^_`|~-]{1,64}$/D', $request['method']) === 1), 'método HTTP inválido');
        self::require(in_array($request['parameter_location'], [null, 'query', 'body', 'header', 'cookie', 'path', 'multipart'], true), 'localização de parâmetro inválida');
        self::digest($request['parameter_digest'], true);
        self::require(($request['parameter_location'] === null) === ($request['parameter_digest'] === null), 'parâmetro incompleto');
        self::require($web || ($request['method'] === null && $request['parameter_digest'] === null), 'requisição indevida para código/inventário');

        $bindings = $input['bindings'];
        self::fields($bindings, ['engine_version', 'engine_digest', 'runtime_digest', 'configuration_digest',
            'rules_digest', 'data_digest', 'conditions_digest', 'payload_family_digest']);
        self::require(is_string($bindings['engine_version'])
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9.+_-]{0,63}$/D', $bindings['engine_version']) === 1, 'versão de motor inválida');
        foreach (['engine_digest', 'runtime_digest', 'configuration_digest', 'rules_digest', 'conditions_digest'] as $field) {
            self::digest($bindings[$field], false);
        }
        self::digest($bindings['data_digest'], true);
        self::digest($bindings['payload_family_digest'], true);
        self::require($bindings['rules_digest'] === self::rulesDigest($catalog, $capabilityId), 'regras efetivas diferem das revisadas');
        if (isset($document['tools'][$owner])) {
            self::require($bindings['engine_version'] === $document['tools'][$owner]['version'], 'versão efetiva difere do catálogo');
        }

        // Ausência de fatos não pode produzir uma chave reutilizável por acidente.
        /** @var list<string> $limitations Dados necessários ainda não verificados. */
        $limitations = [];
        if ($context['code_digest'] === null) {
            $limitations[] = 'code-or-inventory-snapshot-unknown';
        }
        if ($context['profile'] === 'glpi-plugin' && $context['host_digest'] === null) {
            $limitations[] = 'host-snapshot-unknown';
        }
        if ($web) {
            if ($context['deployment_link'] !== 'verified' || $context['deployment_digest'] === null) {
                $limitations[] = 'deployment-link-unverified';
            }
            if ($context['role_digest'] === null || $context['session_context_digest'] === null) {
                $limitations[] = 'role-or-session-context-unknown';
            }
            if ($request['method'] === null) {
                $limitations[] = 'http-method-unknown';
            }
        }
        if ($capability['technique'] === 'active-injection' && $request['parameter_digest'] === null) {
            $limitations[] = 'injection-vector-unknown';
        }
        $cookie = in_array($capabilityId, ['zap.passive.cookie-httponly', 'zap.passive.cookie-secure'], true);
        self::require(!$cookie || in_array($request['parameter_location'], [null, 'cookie'], true), 'localização de cookie incorreta');
        if ($cookie && $request['parameter_digest'] === null) {
            $limitations[] = 'cookie-identity-unknown';
        }
        if ($sca && $bindings['data_digest'] === null) {
            $limitations[] = 'advisory-snapshot-unknown';
        }
        if (in_array($capability['technique'], ['active-injection', 'http-specific-cve'], true)
            && $bindings['payload_family_digest'] === null) {
            $limitations[] = 'payload-family-unknown';
        }

        $descriptor = [
            'identity_version' => 1, 'catalog_version' => $document['catalog_version'],
            'capability' => $capabilityId, 'owner' => $owner, 'technique' => $capability['technique'],
            'granularity' => $capability['granularity'], 'context' => $context,
            'asset' => $asset, 'request' => $request, 'bindings' => $bindings,
        ];
        if ($limitations !== []) {
            $descriptor['run_id'] = $input['run_id'];
            $descriptor['unverified_nonce'] = bin2hex(random_bytes(16));
        }
        return new self('sec-test-v1:' . self::hash($descriptor), $limitations === [], $descriptor, $limitations);
    }

    /**
     * Calcula o vínculo esperado de seletores e artefatos para confronto com o adapter.
     *
     * O adapter deve verificar os bytes efetivos antes de fornecer este digest;
     * copiar o valor esperado não comprova instalação nem execução.
     */
    public static function rulesDigest(CapabilityCatalog $catalog, string $capabilityId): string
    {
        $document = $catalog->jsonSerialize();
        $capability = self::capability($document, $capabilityId);
        /** @var array<string,mixed> $sources Artefatos revisados associados aos seletores. */
        $sources = [];
        foreach ($capability['selectors'] as $selector) {
            $sources[$selector['artifact']] = $document['artifacts'][$selector['artifact']];
        }
        return self::hash(['capability' => $capability, 'artifacts' => $sources, 'catalog_version' => $document['catalog_version']]);
    }

    /** Compara apenas unidades verificáveis; cobertura válida e prazo são decisões do ledger futuro. */
    public function sameVerifiableUnit(self $other): bool
    {
        return $this->verifiable && $other->verifiable && hash_equals($this->key, $other->key);
    }

    /**
     * Exporta os fatos canônicos sem URL, corpo, cabeçalhos ou credenciais brutos.
     *
     * @return array{key:string,verifiable:bool,descriptor:array<string,mixed>,limitations:list<string>} Identidade inspecionável.
     */
    public function jsonSerialize(): array
    {
        return ['key' => $this->key, 'verifiable' => $this->verifiable, 'descriptor' => $this->descriptor, 'limitations' => $this->limitations];
    }

    /**
     * Localiza somente capacidades presentes no catálogo validado.
     *
     * @param array<string,mixed> $document Documento revisado.
     * @return array<string,mixed> Capacidade encontrada.
     */
    private static function capability(array $document, string $id): array
    {
        foreach ($document['capabilities'] as $capability) {
            if ($capability['id'] === $id) {
                return $capability;
            }
        }
        throw new InvalidArgumentException('SecurityTestIdentity: capacidade desconhecida.');
    }

    /**
     * Rejeita campos omitidos ou adicionais, inclusive entradas de segredo indevidas.
     *
     * @param mixed $data Grupo recebido do adapter.
     * @param list<string> $expected Chaves aceitas nesta versão.
     */
    private static function fields(mixed $data, array $expected): void
    {
        self::require(is_array($data), 'objeto de campos esperado');
        $keys = array_keys($data);
        sort($keys);
        sort($expected);
        self::require($keys === $expected, 'campos ausentes ou desconhecidos');
    }

    /** Exige SHA-256 hexadecimal minúsculo; null só é aceito em dimensões explicitamente opcionais. */
    private static function digest(mixed $value, bool $nullable): void
    {
        self::require(($nullable && $value === null) || (is_string($value)
            && preg_match('/^[a-f0-9]{64}$/D', $value) === 1), 'digest inválido');
    }

    /**
     * Ordena recursivamente mapas, preservando ordem de listas e tipos escalares.
     *
     * @param array<array-key,mixed> $value Estrutura sem objetos ou floats fornecidos pelo adapter.
     * @return array<array-key,mixed> Estrutura ordenada deterministicamente.
     */
    private static function canonical(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $child) {
            if (is_array($child)) {
                $value[$key] = self::canonical($child);
            }
        }
        return $value;
    }

    /**
     * Produz SHA-256 do JSON canônico sem concatenar campos ambíguos.
     *
     * @param array<array-key,mixed> $value Estrutura de identidade já validada.
     */
    private static function hash(array $value): string
    {
        return hash('sha256', json_encode(self::canonical($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** Encerra a criação diante de um contrato de entrada inválido. */
    private static function require(bool $valid, string $reason): void
    {
        if (!$valid) {
            throw new InvalidArgumentException('SecurityTestIdentity: ' . $reason);
        }
    }
}
