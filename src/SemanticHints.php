<?php

declare(strict_types=1);

/**
 * Extrai hints semânticos exclusivamente da documentação do projeto consumidor.
 *
 * Lê um conjunto limitado de arquivos Markdown, registra quais foram usados,
 * coleta trechos entre crases como símbolos documentados e conta menções a
 * GLPI/Yii2/Yii3. Os limites de quantidade e tamanho evitam varredura
 * documental irrestrita.
 *
 * O resultado não representa símbolos reais do código, call graph nem
 * reachability e não deve ser usado como evidência de exposição.
 */
final class SemanticHints
{
    /** Limite por arquivo para evitar carregar documentação arbitrariamente grande. */
    private const MAX_FILE_BYTES = 131072;
    /** Quantidade máxima de documentos consumidos por projeto. */
    private const MAX_FILES = 32;

    /** @var list<string> Paths documentais relativos/normalizados efetivamente consumidos. */
    private array $files = [];

    /** @var list<string> Tokens entre crases aceitos como símbolos documentados. */
    private array $symbols = [];

    /** @var array<string,int> Contadores textuais por profile conhecido. */
    private array $signals = ['glpi-plugin' => 0, 'yii2' => 0, 'yii3' => 0];

    /**
     * Constrói os hints a partir de documentação Markdown conhecida do consumidor.
     *
     * Arquivos inexistentes, maiores que o limite ou ilegíveis são ignorados. A
     * coleta é limitada e determinística; símbolos são deduplicados/ordenados no
     * final. Nenhum arquivo PHP/JS é percorrido por este método.
     *
     * @param string $root Raiz do projeto consumidor.
     * @return self Snapshot documental pronto para serialização/consulta.
     */
    public static function fromProject(string $root): self
    {
        $self = new self();

        /** @var list<string> $candidates Arquivos Markdown candidatos em ordem de precedência. */
        $candidates = [
            $root . '/README.md',
            $root . '/AGENTS.md',
            $root . '/CONTRIBUTING.md',
            $root . '/ARCHITECTURE.md',
        ];

        // docs/*.md amplia contexto sem percorrer recursivamente toda a árvore do consumidor.
        foreach (glob($root . '/docs/*.md') ?: [] as $file) {
            $candidates[] = $file;
        }

        foreach (array_values(array_unique($candidates)) as $file) {
            // O limite global e os filtros de existência/tamanho protegem custo previsível.
            if (count($self->files) >= self::MAX_FILES || !is_file($file)) {
                continue;
            }

            $size = filesize($file);
            if (!is_int($size) || $size > self::MAX_FILE_BYTES) {
                continue;
            }

            $content = file_get_contents($file);
            if (!is_string($content)) {
                continue;
            }

            $real = realpath($file) ?: $file;
            $self->files[] = str_starts_with($real, $root . DIRECTORY_SEPARATOR)
                ? substr($real, strlen($root) + 1)
                : $real;
            $self->consume($content);
        }

        // A saída estável evita diferenças apenas por repetição/ordem dos documentos.
        $self->symbols = array_values(array_unique($self->symbols));
        sort($self->symbols);

        return $self;
    }

    /**
     * Retorna os documentos que contribuíram efetivamente para os hints.
     *
     * @return list<string> Paths relativos quando localizados sob a raiz do consumidor.
     */
    public function files(): array
    {
        return $this->files;
    }

    /**
     * Retorna os símbolos textuais encontrados entre crases na documentação.
     *
     * @return list<string> Símbolos deduplicados e ordenados; não representam AST real.
     */
    public function symbols(): array
    {
        return $this->symbols;
    }

    /**
     * Retorna contagens textuais de menções aos profiles conhecidos.
     *
     * @return array<string,int> Contadores documentais por identificador de profile.
     */
    public function profileSignals(): array
    {
        return $this->signals;
    }

    /**
     * Incorpora um documento aos contadores e símbolos internos.
     *
     * Trechos entre pares de crases só são aceitos quando curtos, sem espaços e
     * não vazios; sufixo `()` é removido para normalizar nomes de função/método.
     *
     * @param string $content Conteúdo Markdown já validado pelo chamador.
     */
    private function consume(string $content): void
    {
        $lower = strtolower($content);
        $this->signals['glpi-plugin'] += substr_count($lower, 'glpi');
        $this->signals['yii2'] += substr_count($lower, 'yii2') + substr_count($lower, 'yii 2');
        $this->signals['yii3'] += substr_count($lower, 'yii3') + substr_count($lower, 'yii 3');

        // Apenas segmentos ímpares de explode('`') representam conteúdo entre crases simples.
        foreach (explode('`', $content) as $index => $chunk) {
            if ($index % 2 === 0) {
                continue;
            }

            $candidate = trim($chunk);
            if ($candidate === '' || strlen($candidate) > 160 || str_contains($candidate, ' ')) {
                continue;
            }

            $this->symbols[] = rtrim($candidate, '()');
        }
    }
}
