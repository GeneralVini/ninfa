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
    /** Sequência ANSI que encerra qualquer estilo ativo. */
    private const RESET = "\033[0m";
    /** Cor usada para sucesso. */
    private const GREEN = "\033[32m";
    /** Cor usada para erro/bloqueio. */
    private const RED = "\033[31m";
    /** Cor usada para atenção/aviso. */
    private const YELLOW = "\033[33m";
    /** Cor usada para informação de fluxo. */
    private const CYAN = "\033[36m";
    /** Estilo atenuado usado para informação secundária. */
    private const DIM = "\033[2m";

    /**
     * Decide se a saída humana deve conter sequências ANSI.
     *
     * `NO_COLOR` possui precedência absoluta. Sem ele, `NINFA_COLOR=always`
     * força cor, `never` desabilita e `auto`/valor desconhecido só habilita
     * quando `stream_isatty(STDOUT)` estiver disponível e retornar verdadeiro.
     */
    public static function enabled(): bool
    {
        // A convenção NO_COLOR deve vencer qualquer configuração específica do Ninfa.
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

    /**
     * Formata uma mensagem de sucesso sem alterar seu conteúdo textual.
     *
     * @param string $text Texto a apresentar ao usuário.
     * @return string Texto original envolvido por ANSI verde quando cores estão habilitadas.
     */
    public static function success(string $text): string
    {
        return self::paint($text, self::GREEN);
    }

    /**
     * Formata uma mensagem de erro/bloqueio para a saída humana.
     *
     * @param string $text Texto a apresentar ao usuário.
     * @return string Texto original envolvido por ANSI vermelho quando aplicável.
     */
    public static function error(string $text): string
    {
        return self::paint($text, self::RED);
    }

    /**
     * Formata uma mensagem de atenção sem converter o evento em erro.
     *
     * @param string $text Texto a apresentar ao usuário.
     * @return string Texto original envolvido por ANSI amarelo quando aplicável.
     */
    public static function warning(string $text): string
    {
        return self::paint($text, self::YELLOW);
    }

    /**
     * Formata informação de execução usada para títulos e progresso do CLI.
     *
     * @param string $text Texto a apresentar ao usuário.
     * @return string Texto original envolvido por ANSI ciano quando aplicável.
     */
    public static function info(string $text): string
    {
        return self::paint($text, self::CYAN);
    }

    /**
     * Atenua informação secundária sem removê-la da saída textual.
     *
     * @param string $text Texto a apresentar ao usuário.
     * @return string Texto original envolvido pelo estilo DIM quando aplicável.
     */
    public static function muted(string $text): string
    {
        return self::paint($text, self::DIM);
    }

    /**
     * Monta a legenda usada uma vez pelo runner para explicar os símbolos do CLI.
     *
     * @return string Legenda já submetida às mesmas regras de cor da saída normal.
     */
    public static function legend(): string
    {
        return implode('  ', [
            self::success('✓ sucesso'),
            self::error('✗ bloqueio'),
            self::warning('! atenção'),
            self::info('i informação'),
        ]);
    }

    /**
     * Aplica uma sequência ANSI somente quando a política de cor estiver habilitada.
     *
     * @param string $text Texto que não deve ser modificado semanticamente.
     * @param string $ansi Sequência ANSI de abertura a aplicar.
     * @return string Texto colorido seguido de RESET, ou texto puro quando cor está desabilitada.
     */
    private static function paint(string $text, string $ansi): string
    {
        return self::enabled() ? $ansi . $text . self::RESET : $text;
    }
}
