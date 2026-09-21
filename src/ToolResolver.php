<?php

declare(strict_types=1);

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
    private readonly string $ninfaRoot;

    public function __construct(?string $ninfaRoot = null)
    {
        $this->ninfaRoot = $ninfaRoot ?? dirname(__DIR__);
    }

    public function resolve(string $name, string $projectRoot): string
    {
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
        if ($name === 'semgrep') {
            $message .= ' Execute "make security-tools" em ' . $this->ninfaRoot . '.';
        }

        throw new RuntimeException($message);
    }

    /** @return list<string> */
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
