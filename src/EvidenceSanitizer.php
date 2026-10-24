<?php

declare(strict_types=1);

/** Reduz exposição antes de serializar evidências; texto arbitrário ainda requer revisão. */
final class EvidenceSanitizer
{
    /** @var list<string> $secrets Valores resolvidos em memória, nunca serializados pela classe. */
    private static array $secrets = [];

    /**
     * Registra segredos conhecidos pelo adaptador da execução antes de qualquer saída.
     * @param list<string> $secrets Valores explícitos, sem ler indiscriminadamente o ambiente.
     */
    public static function registerSecrets(array $secrets): void
    {
        self::$secrets = array_values(array_filter($secrets, static fn (string $value): bool => $value !== ''));
    }

    /**
     * Remove campos de payload e credenciais recursivamente, inclusive objetos serializáveis.
     * @param mixed $value Estrutura externa ainda não publicável.
     * @return mixed Estrutura sanitizada com campos sensíveis substituídos.
     */
    public static function value(mixed $value): mixed
    {
        if ($value instanceof JsonSerializable) {
            $value = $value->jsonSerialize();
        } elseif (is_object($value)) {
            $value = get_object_vars($value);
        }
        if (is_array($value)) {
            /** @var array<array-key,mixed> $clean Estrutura com a mesma forma de coleção. */
            $clean = [];
            // Campos opacos podem conter segredos sem qualquer rótulo reconhecível.
            foreach ($value as $key => $item) {
                $name = strtolower(str_replace(['-', '_'], '', (string) $key));
                $sensitive = preg_match('/(?:password|passwd|secret|token|cookie|authorization|apikey|credential)/', $name) === 1;
                $opaque = in_array($name, ['request', 'response', 'curlcommand', 'templateencoded', 'snippet', 'selectedtext', 'lines', 'body', 'headers'], true);
                $clean[$key] = $sensitive || $opaque ? '[REDACTED]' : self::value($item);
            }
            return $clean;
        }
        return is_string($value) ? self::text($value) : $value;
    }

    /**
     * Redige headers, atribuições, query values, userinfo e e-mails em mensagens e URLs.
     * @param string $text Texto externo antes de chegar ao terminal/JSON.
     * @return string Texto com padrões conhecidos removidos e controles de terminal eliminados.
     */
    public static function text(string $text): string
    {
        // Segredos conhecidos também podem aparecer sem rótulo em mensagens/traces.
        foreach (self::$secrets as $secret) {
            $text = str_replace(array_unique([$secret, rawurlencode($secret), urlencode($secret), base64_encode($secret)]), '[REDACTED]', $text);
        }
        $text = preg_replace('/\x1b\[[0-?]*[ -\/]*[@-~]/', '', $text) ?? '';
        $text = preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', $text) ?? '';
        $text = preg_replace('/\b(Authorization|Proxy-Authorization|Cookie|Set-Cookie)["\']?\s*:[^\r\n]*/i', '$1: [REDACTED]', $text) ?? '';
        $text = preg_replace('~(https?://)[^\s/@]+(?::[^\s/@]*)?@~i', '$1[REDACTED]@', $text) ?? '';
        $text = preg_replace('/([?&][^\s=&#]+|\b(?:password|passwd|secret|access[_-]?token|refresh[_-]?token|token|api[_-]?key|session(?:id)?))["\']?\s*(?:=|%3[dD]|:)\s*(?:"[^"\r\n]*"|\'[^\'\r\n]*\'|[^\s&#,;]+)/i', '$1=[REDACTED]', $text) ?? '';
        $text = preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/-]+=*/i', 'Bearer [REDACTED]', $text) ?? '';
        return preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[EMAIL REDACTED]', $text) ?? '';
    }
}
