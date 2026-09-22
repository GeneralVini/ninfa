<?php

declare(strict_types=1);

require_once __DIR__ . '/SastContract.php';

/**
 * Resolve capabilities e os doze contratos SAST efetivos de um profile.
 *
 * O baseline PHP é sempre aplicado. Yii3 e GLPI Plugin 11 adicionam somente
 * APIs cuja semântica pertence ao ecossistema, mantendo adapters independentes
 * do nome do profile e tornando a resolução inspecionável em JSON.
 */
final class SecurityContract implements JsonSerializable
{
    /** @var list<string> Identificadores canônicos obrigatórios da Etapa 4. */
    public const IDS = [
        'command-injection', 'sql-injection', 'xss', 'path-traversal',
        'file-access', 'ssrf', 'unsafe-redirect', 'header-injection',
        'dynamic-include-require', 'unsafe-deserialization',
        'dangerous-eval-assert', 'cryptographic-misuse',
    ];

    /**
     * Mantém a resolução imutável e exige a ordem canônica dos contratos.
     *
     * @param string $profile Profile que originou o contrato consolidado.
     * @param list<string> $capabilities Capacidades de segurança observáveis no profile.
     * @param array<string,SastContract> $contracts Contratos indexados pelo id canônico.
     * @param list<string> $semgrepConfigs Configurações Semgrep relativas ao repositório.
     */
    private function __construct(
        public readonly string $profile,
        public readonly array $capabilities,
        public readonly array $contracts,
        public readonly array $semgrepConfigs,
    ) {
        if (array_keys($contracts) !== self::IDS) {
            throw new LogicException('SecurityContract deve resolver exatamente os 12 contratos comuns.');
        }
    }

    /**
     * Resolve baseline e overlay suportado sem espalhar condicionais pelos scanners.
     *
     * Yii2 e PHP genérico recebem apenas o baseline comum; somente Yii3 e GLPI
     * Plugin 11 possuem especializações nesta etapa.
     */
    public static function forProfile(string $profile): self
    {
        $contracts = self::commonContracts();
        $capabilities = ['filesystem', 'console'];
        $configs = ['security/semgrep/common.yml'];

        // Profiles especializados agregam APIs e capabilities próprias ao baseline PHP.
        if ($profile === 'yii3') {
            $capabilities = [...$capabilities, 'web-request', 'database', 'view-html', 'http-client', 'redirect-response'];
            $contracts = self::mergeOverlays($contracts, self::yii3Overlays());
            $configs[] = 'security/semgrep/profiles/yii3.yml';
        } elseif ($profile === 'glpi-plugin') {
            $capabilities = [...$capabilities, 'web-request', 'database', 'view-html', 'http-client', 'redirect-response', 'host-api'];
            $contracts = self::mergeOverlays($contracts, self::glpiOverlays());
            $configs[] = 'security/semgrep/profiles/glpi-plugin-11.yml';
        }

        return new self($profile, $capabilities, $contracts, $configs);
    }

    /**
     * Define os doze contratos PHP comuns com semântica mínima auditável.
     *
     * @return array<string,SastContract> Contratos em ordem canônica de IDS.
     */
    private static function commonContracts(): array
    {
        $definitions = [
            'command-injection' => [['$_GET', '$_POST', 'argv'], ['exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen'], ['escapeshellarg', 'escapeshellcmd'], ['shell execution'], ['constant command']],
            'sql-injection' => [['$_GET', '$_POST', '$_REQUEST'], ['PDO::query', 'PDO::exec', 'mysqli_query'], ['prepared statements', 'parameter binding'], [], ['bound parameters']],
            'xss' => [['$_GET', '$_POST', '$_REQUEST'], ['echo', 'print'], ['htmlspecialchars', 'htmlentities'], [], ['escaped output']],
            'path-traversal' => [['request path', 'uploaded filename'], ['filesystem path resolution'], ['basename', 'realpath containment'], [], ['allowlisted path']],
            'file-access' => [['request input'], ['file_get_contents', 'file_put_contents', 'fopen', 'unlink'], ['allowlist', 'realpath containment'], [], ['constant local path']],
            'ssrf' => [['request URL'], ['curl_init', 'file_get_contents URL', 'HTTP client request'], ['scheme and host allowlist'], [], ['fixed endpoint']],
            'unsafe-redirect' => [['request URL'], ['header Location', 'response redirect'], ['local URL validation'], [], ['named route']],
            'header-injection' => [['request input'], ['header', 'response header'], ['CRLF rejection'], [], ['constant header']],
            'dynamic-include-require' => [['request path'], ['include', 'include_once', 'require', 'require_once'], ['allowlist'], ['dynamic include'], ['constant include']],
            'unsafe-deserialization' => [['request input', 'stored untrusted data'], ['unserialize'], ['allowed_classes false'], ['unserialize'], ['json_decode']],
            'dangerous-eval-assert' => [['request input'], ['eval', 'assert string'], [], ['eval', 'dynamic assert'], ['static assertion']],
            'cryptographic-misuse' => [[], ['md5 password', 'sha1 password', 'weak random'], ['password_hash', 'random_bytes'], ['weak cryptography'], ['modern password API']],
        ];

        /** @var array<string,SastContract> $contracts Contratos materializados na ordem canônica. */
        $contracts = [];
        foreach (self::IDS as $id) {
            [$sources, $sinks, $sanitizers, $primitives, $safe] = $definitions[$id];
            $contracts[$id] = new SastContract($id, $sources, $sinks, $sanitizers, $primitives, $safe, ['DATAFLOW', 'DANGEROUS_PRIMITIVE', 'MISUSE'], ['ninfa:common']);
        }
        return $contracts;
    }

    /**
     * Retorna as APIs Yii3 que complementam o baseline sem alterar seu significado.
     * @return array<string,SastContract> Overlays semânticos próprios de Yii3.
     */
    private static function yii3Overlays(): array
    {
        return self::overlays('yii3', [
            'sql-injection' => [['ServerRequestInterface::getQueryParams'], ['Yiisoft\\Db\\Connection::createCommand'], ['bindValue', 'bindValues']],
            'xss' => [['ServerRequestInterface attributes'], ['Response body', 'view rendering'], ['Yiisoft\\Html\\Html::encode']],
            'ssrf' => [['ServerRequestInterface URI'], ['Psr\\Http\\Client\\ClientInterface::sendRequest'], ['trusted URI policy']],
            'unsafe-redirect' => [['query parameters'], ['RedirectMiddleware', 'ResponseFactory redirect'], ['UrlGeneratorInterface']],
            'header-injection' => [['request headers'], ['ResponseInterface::withHeader'], ['header value validation']],
        ]);
    }

    /**
     * Retorna as APIs GLPI Plugin 11 que exigem interpretação específica.
     * @return array<string,SastContract> Overlays semânticos próprios do GLPI.
     */
    private static function glpiOverlays(): array
    {
        return self::overlays('glpi-plugin-11', [
            'sql-injection' => [['$_GET', '$_POST'], ['$DB->query', '$DB->request'], ['$DB->quoteValue', 'DBmysqlIterator criteria']],
            'xss' => [['request parameters'], ['Html::displayErrorAndDie', 'template output'], ['Html::cleanInputText', 'Twig autoescape']],
            'file-access' => [['Document upload', 'request path'], ['Toolbox::getFileContent', 'Filesystem operations'], ['GLPI upload validation']],
            'ssrf' => [['request URL'], ['Toolbox::getURLContent', 'Guzzle client'], ['GLPI proxy and URL policy']],
            'unsafe-redirect' => [['request URL'], ['Html::redirect'], ['GLPI relative URL']],
            'header-injection' => [['request input'], ['header', 'Html::header'], ['header value validation']],
        ]);
    }

    /**
     * Converte definições compactas de profile em objetos de overlay.
     *
     * @param string $profile Nome usado em provenance.
     * @param array<string,array{0:list<string>,1:list<string>,2:list<string>}> $definitions Semântica adicional por contrato.
     * @return array<string,SastContract> Overlays indexados por contrato.
     */
    private static function overlays(string $profile, array $definitions): array
    {
        /** @var array<string,SastContract> $overlays Overlays materializados. */
        $overlays = [];
        foreach ($definitions as $id => [$sources, $sinks, $sanitizers]) {
            $overlays[$id] = new SastContract($id, $sources, $sinks, $sanitizers, [], [], ['DATAFLOW', 'MISUSE'], ['ninfa:' . $profile]);
        }
        return $overlays;
    }

    /**
     * Aplica overlays somente aos contratos conhecidos, rejeitando ids acidentais.
     *
     * @param array<string,SastContract> $base Contratos comuns.
     * @param array<string,SastContract> $overlays Especializações do profile.
     * @return array<string,SastContract> Contratos consolidados.
     */
    private static function mergeOverlays(array $base, array $overlays): array
    {
        foreach ($overlays as $id => $overlay) {
            if (!isset($base[$id])) {
                throw new LogicException('Overlay desconhecido: ' . $id);
            }
            $base[$id] = $base[$id]->merge($overlay);
        }
        return $base;
    }

    /**
     * Serializa profile, capabilities, adapters e contratos efetivos.
     * @return array<string,mixed> Payload completo do contrato resolvido.
     */
    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}
