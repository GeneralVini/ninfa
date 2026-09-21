<?php

declare(strict_types=1);

/**
 * Centraliza a decoração ANSI usada na saída humana do CLI.
 *
 * `NO_COLOR` desabilita cores. `NINFA_COLOR` aceita `always`, `never` ou
 * `auto`; no modo automático a decisão depende de STDOUT ser um TTY. A classe
 * apenas formata strings e não altera exit codes nem o modelo de resultados.
 */
final class CliStyle
{
    private const RESET = "\033[0m";
    private const GREEN = "\033[32m";
    private const RED = "\033[31m";
    private const YELLOW = "\033[33m";
    private const CYAN = "\033[36m";
    private const DIM = "\033[2m";

    public static function enabled(): bool
    {
        if (getenv('NO_COLOR') !== false) {
            return false;
        }

        $mode = strtolower(trim((string) (getenv('NINFA_COLOR') ?: 'auto')));

        return match ($mode) {
            'always' => true,
            'never' => false,
            'auto' => function_exists('stream_isatty') && @stream_isatty(STDOUT),
            default => function_exists('stream_isatty') && @stream_isatty(STDOUT),
        };
    }

    public static function success(string $text): string
    {
        return self::paint($text, self::GREEN);
    }

    public static function error(string $text): string
    {
        return self::paint($text, self::RED);
    }

    public static function warning(string $text): string
    {
        return self::paint($text, self::YELLOW);
    }

    public static function info(string $text): string
    {
        return self::paint($text, self::CYAN);
    }

    public static function muted(string $text): string
    {
        return self::paint($text, self::DIM);
    }

    public static function legend(): string
    {
        return implode('  ', [
            self::success('✓ sucesso'),
            self::error('✗ bloqueio'),
            self::warning('! atenção'),
            self::info('i informação'),
        ]);
    }

    private static function paint(string $text, string $ansi): string
    {
        return self::enabled() ? $ansi . $text . self::RESET : $text;
    }
}
