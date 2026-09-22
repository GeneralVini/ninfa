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
 * `PATH` do processo. Quando nenhuma opção é executável, lança exceção em vez
 * de deixar `proc_open()` falhar de forma opaca.
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
     * Somente arquivos existentes e executáveis são aceitos. O método não tenta
     * instalar dependências nem executar o binário encontrado. Para Semgrep, a
     * falha acrescenta a orientação específica de `make security-tools`.
     *
     * @param string $name Nome do executável sem diretório.
     * @param string $projectRoot Raiz do projeto consumidor usada nos candidatos locais.
     * @return string Caminho executável resolvido.
     * @throws ToolUnavailableException Quando nenhum candidato ou entrada de PATH é executável.
     */
    public function resolve(string $name, string $projectRoot): string
    {
        // Candidatos explícitos têm precedência sobre qualquer executável global do PATH.
        foreach ($this->candidates($name, $projectRoot) as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        $path = getenv('PATH');
        if (is_string($path) && $path !== '') {
            foreach (explode(PATH_SEPARATOR, $path) as $directory) {
                if ($directory === '') {
                    continue;
                }

                $candidate = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name;
                if (is_file($candidate) && is_executable($candidate)) {
                    return $candidate;
                }
            }
        }

        $message = sprintf(
            'Ferramenta "%s" não encontrada no projeto, no ambiente do Ninfa ou no PATH.',
            $name,
        );
        // Semgrep possui mecanismo oficial de instalação gerenciada pelo próprio repositório.
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
