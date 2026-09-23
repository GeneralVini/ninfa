<?php

declare(strict_types=1);

/** Indica que uma capability planejada não possui executável disponível. */
final class ToolUnavailableException extends RuntimeException
{
}

/**
 * Localiza o executável usado por cada etapa sem instalar nada no consumidor.
 *
 * A resolução verifica, nesta ordem, binários do projeto consumidor,
 * ferramentas gerenciadas pelo Ninfa, dependências do próprio Ninfa e o
 * `PATH` do processo. Quando encontra um candidato presente porém sem permissão
 * de execução, preserva esse diagnóstico para orientar a correção sem alterar o
 * projeto consumidor automaticamente.
 */
final class ToolResolver
{
    /** Raiz usada para localizar ferramentas privadas e dependências do próprio Ninfa. */
    private readonly string $ninfaRoot;

    /**
     * Define a raiz do Ninfa usada durante a resolução de executáveis.
     *
     * @param string|null $ninfaRoot Override usado principalmente por testes; null usa a raiz deste repositório.
     */
    public function __construct(?string $ninfaRoot = null)
    {
        $this->ninfaRoot = $ninfaRoot ?? dirname(__DIR__);
    }

    /**
     * Resolve um executável respeitando a precedência explícita do projeto/Ninfa/PATH.
     *
     * O método localiza executáveis, mas não executa probes arbitrários como
     * `--version`, pois ferramentas distintas possuem contratos de CLI diferentes.
     * Saúde operacional é validada pelos pontos específicos que conhecem cada
     * ferramenta. Um arquivo presente sem `+x` é reportado de forma distinta de
     * uma ferramenta ausente.
     *
     * @param string $name Nome do executável sem diretório.
     * @param string $projectRoot Raiz do projeto consumidor usada nos candidatos locais.
     * @return string Caminho executável resolvido.
     * @throws ToolUnavailableException Quando nenhum candidato ou entrada de PATH é executável.
     */
    public function resolve(string $name, string $projectRoot): string
    {
        /** @var list<string> $notExecutable Candidatos existentes que falharam apenas na permissão de execução. */
        $notExecutable = [];

        // Candidatos explícitos têm precedência sobre qualquer executável global do PATH.
        foreach ($this->candidates($name, $projectRoot) as $candidate) {
            if (!is_file($candidate)) {
                continue;
            }

            if (is_executable($candidate)) {
                return $candidate;
            }

            $notExecutable[] = $candidate;
        }

        $path = getenv('PATH');
        if (is_string($path) && $path !== '') {
            foreach (explode(PATH_SEPARATOR, $path) as $directory) {
                if ($directory === '') {
                    continue;
                }

                $candidate = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name;
                if (!is_file($candidate)) {
                    continue;
                }

                if (is_executable($candidate)) {
                    return $candidate;
                }

                $notExecutable[] = $candidate;
            }
        }

        if ($notExecutable !== []) {
            $candidate = $notExecutable[0];
            $message = sprintf(
                'Ferramenta "%s" encontrada, mas sem permissão de execução: %s. Para corrigir: chmod +x %s',
                $name,
                $candidate,
                escapeshellarg($candidate),
            );

            if ($name === 'semgrep' && str_starts_with($candidate, $this->ninfaRoot . DIRECTORY_SEPARATOR)) {
                $message .= ' Se o launcher continuar falhando, execute "make security-tools" em ' . $this->ninfaRoot . '.';
            }

            throw new ToolUnavailableException($message);
        }

        $message = sprintf(
            'Ferramenta "%s" não encontrada no projeto, no ambiente do Ninfa ou no PATH.',
            $name,
        );
        if ($name === 'semgrep') {
            $message .= ' Execute "make security-tools" em ' . $this->ninfaRoot . '.';
        }

        throw new ToolUnavailableException($message);
    }

    /**
     * Monta a lista ordenada de candidatos locais antes do fallback para PATH.
     *
     * A ordem prioriza ferramentas do consumidor, depois `.tools` gerenciado e
     * por fim dependências Node/Composer do próprio Ninfa.
     *
     * @param string $name Nome do executável procurado.
     * @param string $projectRoot Raiz do consumidor.
     * @return list<string> Paths candidatos na ordem exata de precedência.
     */
    private function candidates(string $name, string $projectRoot): array
    {
        return [
            $projectRoot . '/node_modules/.bin/' . $name,
            $projectRoot . '/vendor/bin/' . $name,
            $this->ninfaRoot . '/.tools/' . $name . '/bin/' . $name,
            $this->ninfaRoot . '/node_modules/.bin/' . $name,
            $this->ninfaRoot . '/vendor/bin/' . $name,
        ];
    }
}
