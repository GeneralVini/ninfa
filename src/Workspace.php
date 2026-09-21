<?php

declare(strict_types=1);

/**
 * Representa o workspace externo e descartável associado a um projeto.
 *
 * A base vem de NINFA_WORKSPACE_ROOT ou, por padrão, de <tmp>/ninfa. Cada
 * projeto recebe um subdiretório derivado do SHA-256 da raiz real. A classe
 * rejeita workspaces localizados dentro do projeto consumidor para preservar
 * a separação entre artefatos do Ninfa e arquivos do repositório analisado.
 */
final class Workspace
{
    /**
     * Resolve o workspace determinístico de um projeto sem escrever no consumidor.
     *
     * `NINFA_WORKSPACE_ROOT` pode apontar para base absoluta ou relativa. Bases
     * relativas são ancoradas no diretório atual; bases iguais ou internas à
     * raiz do consumidor são rejeitadas. O identificador final usa os primeiros
     * 16 caracteres do SHA-256 da raiz real do projeto.
     *
     * @param string $projectRoot Raiz informada para o projeto consumidor.
     * @return self Workspace criado/validado para esse projeto.
     * @throws RuntimeException Quando não é possível resolver cwd ou a base fica dentro do consumidor.
     */
    public static function forProject(string $projectRoot): self
    {
        $realProjectRoot = realpath($projectRoot) ?: $projectRoot;
        $base = getenv('NINFA_WORKSPACE_ROOT');
        if (!is_string($base) || $base === '') {
            $base = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'ninfa';
        }

        // Base relativa precisa ser estabilizada antes da verificação de isolamento.
        if (!str_starts_with($base, DIRECTORY_SEPARATOR)) {
            $cwd = getcwd();
            if ($cwd === false) {
                throw new RuntimeException('Não foi possível resolver o diretório atual.');
            }
            $base = $cwd . DIRECTORY_SEPARATOR . $base;
        }

        $project = rtrim($realProjectRoot, DIRECTORY_SEPARATOR);
        $base = rtrim($base, DIRECTORY_SEPARATOR);

        // Artefatos do Ninfa não podem ser materializados dentro do repositório analisado.
        if ($base === $project || str_starts_with($base, $project . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('NINFA_WORKSPACE_ROOT não pode ficar dentro do projeto consumidor.');
        }

        $id = substr(hash('sha256', $realProjectRoot), 0, 16);
        return new self($base . DIRECTORY_SEPARATOR . $id);
    }

    /**
     * Materializa o diretório de workspace quando ele ainda não existe.
     *
     * @param string $path Caminho absoluto/normalizado resolvido por `forProject()` ou teste.
     * @throws RuntimeException Quando a criação do diretório falha.
     */
    public function __construct(private readonly string $path)
    {
        if (!is_dir($this->path) && !mkdir($this->path, 0775, true) && !is_dir($this->path)) {
            throw new RuntimeException('Não foi possível criar workspace Ninfa: ' . $this->path);
        }
    }

    /**
     * Retorna o diretório raiz do workspace associado ao projeto.
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * Monta um caminho filho dentro do workspace sem criar o arquivo solicitado.
     *
     * A barra inicial do nome é removida para impedir que concatenação transforme
     * o argumento em path absoluto e descarte a raiz do workspace.
     *
     * @param string $name Nome/path relativo do artefato.
     * @return string Caminho resultante dentro do workspace.
     */
    public function file(string $name): string
    {
        return $this->path . DIRECTORY_SEPARATOR . ltrim($name, DIRECTORY_SEPARATOR);
    }
}
