<?php

declare(strict_types=1);
require_once __DIR__ . '/EvidenceSanitizer.php';

/** Armazena artefatos privados com publicação atômica e retenção independente de novas capturas. */
final class EvidenceStore
{
    /**
     * Valida diretório exclusivo e aplica retenção ao abrir a store.
     * @param string $directory Diretório exclusivo, fora do consumidor.
     * @param bool $allowRaw Habilitação explícita para esta instância/execução; default false.
     * @throws RuntimeException Quando a raiz não pode ser protegida.
     */
    public function __construct(private readonly string $directory, private readonly bool $allowRaw = false)
    {
        self::privateDirectory($directory);
        $this->cleanup();
    }

    /**
     * Cria/protege diretório e rejeita componentes simbólicos ou de outro proprietário.
     * @param string $path Caminho absoluto do diretório privado.
     */
    public static function privateDirectory(string $path): void
    {
        if (!str_starts_with($path, '/') || str_contains($path, '/../') || str_ends_with($path, '/..')) {
            throw new RuntimeException('Diretório privado exige caminho absoluto sem traversal.');
        }
        $current = '';
        // Verificar ancestrais evita gravar através de symlinks de uma base compartilhada.
        foreach (explode('/', trim($path, '/')) as $part) {
            $current .= '/' . $part;
            if (is_link($current)) {
                throw new RuntimeException('Symlink em diretório de evidência.');
            }
            if (!file_exists($current) && !@mkdir($current, 0700)) {
                throw new RuntimeException('Não foi possível criar diretório privado.');
            }
        }
        if (!is_dir($path) || (function_exists('posix_geteuid') && fileowner($path) !== posix_geteuid()) || !@chmod($path, 0700)) {
            throw new RuntimeException('Diretório privado indisponível ou de outro proprietário.');
        }
    }

    /**
     * Escreve conteúdo já preparado sem expor arquivo parcialmente gravado.
     * @param string $path Destino absoluto dentro de diretório controlado.
     * @param string $bytes Bytes finais, já sanitizados quando compartilháveis.
     * @return string Caminho publicado.
     */
    public static function atomicWrite(string $path, string $bytes): string
    {
        self::privateDirectory(dirname($path));
        if (is_link($path)) {
            throw new RuntimeException('Destino simbólico recusado.');
        }
        $temporary = tempnam(dirname($path), '.evidence-');
        if ($temporary === false) {
            throw new RuntimeException('Não foi possível preparar gravação.');
        }
        try {
            if (!chmod($temporary, 0600) || @file_put_contents($temporary, $bytes) !== strlen($bytes) || !@rename($temporary, $path)) {
                throw new RuntimeException('Falha na publicação atômica da evidência.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
        return $path;
    }

    /**
     * Grava envelope com prazo explícito e digest do payload efetivamente armazenado.
     * @param mixed $data Payload estruturado ou texto.
     * @param bool $raw Solicita exceção privada sem sanitização.
     * @return array{reference:string,sha256:string,expires_at:int,raw:bool} Referência relativa e SHA-256 dos bytes do envelope armazenado.
     */
    public function put(mixed $data, bool $raw = false): array
    {
        if ($raw && !$this->allowRaw) {
            throw new RuntimeException('Raw exige habilitação explícita por execução.');
        }
        $payload = json_encode($raw ? $data : EvidenceSanitizer::value($data), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $hash = hash('sha256', $payload);
        $reference = ($raw ? 'raw-' : 'safe-') . bin2hex(random_bytes(12)) . '.json';
        $expires = time() + ($raw ? 86400 : 2592000);
        $envelope = json_encode(['expires_at' => $expires, 'raw' => $raw, 'sha256' => $hash, 'payload' => $payload], JSON_THROW_ON_ERROR);
        self::atomicWrite($this->directory . '/' . $reference, $envelope);
        return ['reference' => $reference, 'sha256' => hash('sha256', $envelope), 'expires_at' => $expires, 'raw' => $raw];
    }

    /**
     * Aplica o prazo de 30 dias aos snapshots nomeados do CLI sem depender de nova auditoria.
     * @param string $workspace Workspace privado já validado.
     * @param int|null $now Relógio opcional para limpeza/testes.
     * @return int Número de relatórios/inventários expirados removidos.
     */
    public static function cleanupReports(string $workspace, ?int $now = null): int
    {
        self::privateDirectory($workspace);
        $removed = 0;
        // Somente nomes fixos do CLI; links e arquivos arbitrários nunca são seguidos/removidos.
        foreach (['security-report.json', 'security-inventory.json'] as $name) {
            $file = $workspace . '/' . $name;
            if (!is_link($file) && is_file($file) && filemtime($file) + 2592000 <= ($now ?? time())) {
                if (!unlink($file)) {
                    throw new RuntimeException('Falha ao expirar snapshot de segurança.');
                }
                $removed++;
            }
        }
        return $removed;
    }

    /**
     * Remove envelopes expirados mesmo sem captura; recusa seguir links ou formato desconhecido.
     * @param int|null $now Relógio opcional para verificação determinística.
     * @return int Quantidade de artefatos expirados removidos.
     */
    public function cleanup(?int $now = null): int
    {
        $removed = 0;
        foreach (glob($this->directory . '/*.json') ?: [] as $file) {
            // Restringir ao namespace da store evita apagar relatórios de outra política.
            if (!preg_match('/\/(?:raw|safe)-[a-f0-9]{24}\.json$/', $file) || is_link($file)) {
                continue;
            }
            $record = json_decode((string) file_get_contents($file), true);
            if (!is_array($record) || !is_int($record['expires_at'] ?? null)) {
                throw new RuntimeException('Envelope de evidência inválido; requer revisão.');
            }
            if ($record['expires_at'] <= ($now ?? time())) {
                if (!unlink($file)) {
                    throw new RuntimeException('Não foi possível expirar evidência.');
                }
                $removed++;
            }
        }
        return $removed;
    }
}
