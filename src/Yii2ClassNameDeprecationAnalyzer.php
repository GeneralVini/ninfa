<?php

declare(strict_types=1);

require_once __DIR__ . '/ProjectContext.php';

/**
 * Detecta `BaseObject::className()` somente quando a equivalência com `::class` é demonstrável.
 *
 * A regra segue a ideia de `ReplaceClassnameWithClassRector`, mas mantém a política conservadora
 * do Ninfa: `self::className()` e `parent::className()` nunca são reescritos, `static::className()`
 * exige que a classe léxica seja comprovadamente descendente de `yii\base\BaseObject` e chamadas
 * em classes nomeadas só entram quando o tipo resolve para a base conhecida ou para subclasse local.
 */
final class Yii2ClassNameDeprecationAnalyzer
{
    /** Base Yii2 cuja API `className()` está deprecated em favor de `::class`. */
    private const BASE_OBJECT = 'yii\\base\\BaseObject';

    /**
     * Localiza chamadas `className()` com replacement SAFE e offset exato.
     *
     * @param ProjectContext $context Contexto Yii2 que delimita os arquivos elegíveis.
     * @return list<array{file:string,line:int,rule:'NINFA-YII2-DEP-006',kind:'class-name',problem:string,replacement:string,offset:int,length:int}> Evidências em ordem estável.
     */
    public function references(ProjectContext $context): array
    {
        if ($context->profile() !== 'yii2') {
            return [];
        }

        $files = $this->phpFiles($context);
        $classes = $this->classMetadata($files);
        /** @var list<array{file:string,line:int,rule:'NINFA-YII2-DEP-006',kind:'class-name',problem:string,replacement:string,offset:int,length:int}> $references */
        $references = [];

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            $code = $this->codeMask($source);
            $namespace = $this->namespaceOf($code);
            $uses = $this->importsOf($code);
            $scopes = $this->classScopes($code, $namespace);
            $relative = $this->relativePath($context->root(), $file);

            foreach ($this->candidateCalls($code) as $call) {
                if ($call['class'] === 'self' || $call['class'] === 'parent') {
                    continue;
                }

                // `static` depende da classe léxica; classe nomeada usa resolução normal de import/namespace.
                $type = $call['class'] === 'static'
                    ? $this->classAtOffset($scopes, $call['offset'])
                    : $this->resolveName($call['class'], $uses, $namespace);
                if ($type === null || !$this->isBaseObjectType($type, $classes, [])) {
                    continue;
                }

                $references[] = [
                    'file' => $relative,
                    'line' => substr_count(substr($source, 0, $call['offset']), "\n") + 1,
                    'rule' => 'NINFA-YII2-DEP-006',
                    'kind' => 'class-name',
                    'problem' => 'yii\\base\\BaseObject::className() está deprecated; use a constante nativa ::class.',
                    'replacement' => 'class',
                    'offset' => $call['offset'],
                    'length' => $call['length'],
                ];
            }
        }

        usort($references, static fn (array $left, array $right): int => [$left['file'], $left['offset']] <=> [$right['file'], $right['offset']]);
        return $references;
    }

    /**
     * Produz uma visão do source com comentários e strings mascarados sem deslocar offsets.
     *
     * Autofix baseado em regex nunca deve interpretar exemplos em comentário, PHPDoc, string,
     * inline HTML ou conteúdo de heredoc como código executável. Os bytes mascarados viram
     * espaços, mas quebras de linha são preservadas para manter offsets e linhas auditáveis.
     *
     * @param string $source Código-fonte original.
     * @return string Source de mesmo comprimento contendo somente regiões analisáveis.
     */
    private function codeMask(string $source): string
    {
        /** @var list<int> $maskedTokens Tokens cujo texto não representa código elegível. */
        $maskedTokens = [
            T_COMMENT,
            T_DOC_COMMENT,
            T_CONSTANT_ENCAPSED_STRING,
            T_ENCAPSED_AND_WHITESPACE,
            T_INLINE_HTML,
            T_START_HEREDOC,
            T_END_HEREDOC,
        ];
        $masked = '';

        foreach (token_get_all($source) as $token) {
            if (!is_array($token)) {
                $masked .= $token;
                continue;
            }

            $text = $token[1];
            if (!in_array($token[0], $maskedTokens, true)) {
                $masked .= $text;
                continue;
            }

            $replacement = preg_replace('/[^\r\n]/', ' ', $text);
            $masked .= is_string($replacement) ? $replacement : str_repeat(' ', strlen($text));
        }

        return $masked;
    }

    /**
     * Extrai chamadas `X::className()` sem argumentos e preserva o intervalo substituível.
     *
     * O intervalo começa em `className` e inclui os parênteses, portanto trocar todo o trecho
     * por `class` preserva exatamente o qualifier original, inclusive `static` ou FQCN.
     *
     * @param string $source Código-fonte mascarado com offsets idênticos ao original.
     * @return list<array{class:string,offset:int,length:int}> Chamadas candidatas em ordem textual.
     */
    private function candidateCalls(string $source): array
    {
        $name = '(?:\\\\)?[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*';
        $pattern = '/(?P<class>static|self|parent|' . $name . ')\s*::\s*(?P<call>className\s*\(\s*\))/';
        $count = preg_match_all($pattern, $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        if ($count === false || $count === 0) {
            return [];
        }

        /** @var list<array{class:string,offset:int,length:int}> $calls */
        $calls = [];
        foreach ($matches as $match) {
            $calls[] = [
                'class' => (string) $match['class'][0],
                'offset' => (int) $match['call'][1],
                'length' => strlen((string) $match['call'][0]),
            ];
        }
        return $calls;
    }

    /**
     * Resolve a classe léxica que contém determinado offset para validar `static::className()`.
     *
     * @param list<array{class:string,start:int,end:int}> $scopes Escopos de classe do arquivo.
     * @param int $offset Offset absoluto da chamada.
     * @return string|null FQCN da classe mais interna ou null fora de uma classe conhecida.
     */
    private function classAtOffset(array $scopes, int $offset): ?string
    {
        foreach ($scopes as $scope) {
            if ($offset >= $scope['start'] && $offset <= $scope['end']) {
                return $scope['class'];
            }
        }
        return null;
    }

    /**
     * Delimita corpos de classes para associar `static` à declaração correta.
     *
     * @param string $source Código-fonte mascarado.
     * @param string $namespace Namespace do arquivo sem barra inicial.
     * @return list<array{class:string,start:int,end:int}> Escopos de classe não sobrepostos.
     */
    private function classScopes(string $source, string $namespace): array
    {
        $pattern = '/\b(?:abstract\s+|final\s+)?class\s+([A-Za-z_][A-Za-z0-9_]*)[^\{]*\{/';
        $count = preg_match_all($pattern, $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        if ($count === false || $count === 0) {
            return [];
        }

        /** @var list<array{class:string,start:int,end:int}> $scopes */
        $scopes = [];
        foreach ($matches as $match) {
            $opening = strpos($source, '{', (int) $match[0][1]);
            if ($opening === false) {
                continue;
            }
            $end = $this->balancedBlockEnd($source, $opening);
            if ($end === null) {
                continue;
            }
            $short = (string) $match[1][0];
            $scopes[] = [
                'class' => $namespace === '' ? $short : $namespace . '\\' . $short,
                'start' => $opening,
                'end' => $end,
            ];
        }
        return $scopes;
    }

    /**
     * Prova `yii\base\BaseObject` ou descendência por cadeia de classes locais.
     *
     * Parents externos que não sejam a própria base encerram a prova; essa restrição evita
     * inferir a hierarquia completa do Yii2 sem reflection ou execução do consumidor.
     *
     * @param string $type FQCN candidato sem barra inicial.
     * @param array<string,array{parent:string|null}> $classes Índice local de herança.
     * @param array<string,bool> $visited Proteção contra ciclos inválidos.
     * @return bool True somente quando a cadeia termina na base conhecida.
     */
    private function isBaseObjectType(string $type, array $classes, array $visited): bool
    {
        if ($type === self::BASE_OBJECT) {
            return true;
        }
        if (isset($visited[$type]) || !isset($classes[$type])) {
            return false;
        }
        $parent = $classes[$type]['parent'];
        if ($parent === null) {
            return false;
        }
        $visited[$type] = true;
        return $this->isBaseObjectType($parent, $classes, $visited);
    }

    /**
     * Indexa classes locais e seus parents resolvidos por namespace/import.
     *
     * @param list<string> $files Arquivos PHP elegíveis.
     * @return array<string,array{parent:string|null}> FQCN local para parent resolvido.
     */
    private function classMetadata(array $files): array
    {
        /** @var array<string,array{parent:string|null}> $classes Índice de herança local. */
        $classes = [];
        $pattern = '/\b(?:abstract\s+|final\s+)?class\s+([A-Za-z_][A-Za-z0-9_]*)(?P<tail>[^{]*)\{/';

        foreach ($files as $file) {
            $source = $this->codeMask((string) file_get_contents($file));
            $namespace = $this->namespaceOf($source);
            $uses = $this->importsOf($source);
            $count = preg_match_all($pattern, $source, $matches, PREG_SET_ORDER);
            if ($count === false || $count === 0) {
                continue;
            }

            foreach ($matches as $match) {
                $short = (string) $match[1];
                $class = $namespace === '' ? $short : $namespace . '\\' . $short;
                $tail = (string) ($match['tail'] ?? '');
                $parentRaw = preg_match('/\bextends\s+([^\s{]+)/', $tail, $parentMatch) === 1
                    ? trim((string) $parentMatch[1])
                    : '';
                $classes[$class] = [
                    'parent' => $parentRaw === '' ? null : $this->resolveName($parentRaw, $uses, $namespace),
                ];
            }
        }
        return $classes;
    }

    /**
     * Retorna o offset da chave que fecha um bloco iniciado em `$openingOffset`.
     *
     * @param string $source Código-fonte mascarado.
     * @param int $openingOffset Offset da chave de abertura.
     * @return int|null Offset da chave final ou null quando o bloco é inválido.
     */
    private function balancedBlockEnd(string $source, int $openingOffset): ?int
    {
        $depth = 0;
        $length = strlen($source);
        for ($cursor = $openingOffset; $cursor < $length; $cursor++) {
            if ($source[$cursor] === '{') {
                $depth++;
                continue;
            }
            if ($source[$cursor] !== '}') {
                continue;
            }
            $depth--;
            if ($depth === 0) {
                return $cursor;
            }
        }
        return null;
    }

    /**
     * Lista arquivos PHP somente nos paths autorizados pelo ProjectContext.
     *
     * @param ProjectContext $context Contexto do consumidor.
     * @return list<string> Arquivos absolutos, únicos e ordenados.
     */
    private function phpFiles(ProjectContext $context): array
    {
        /** @var list<string> $files Arquivos PHP elegíveis. */
        $files = [];
        foreach ($context->paths() as $path) {
            $absolute = $context->root() . '/' . $path;
            if (is_file($absolute) && strtolower(pathinfo($absolute, PATHINFO_EXTENSION)) === 'php') {
                $files[] = $absolute;
                continue;
            }
            if (!is_dir($absolute)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $item) {
                if ($item->isFile() && strtolower($item->getExtension()) === 'php') {
                    $files[] = $item->getPathname();
                }
            }
        }

        sort($files);
        return array_values(array_unique($files));
    }

    /**
     * Extrai namespace do arquivo sem executar código.
     *
     * @param string $source Código-fonte mascarado.
     * @return string Namespace sem barra inicial.
     */
    private function namespaceOf(string $source): string
    {
        return preg_match('/\bnamespace\s+([^;{]+)\s*[;{]/', $source, $match) === 1
            ? trim((string) $match[1], " \t\n\r\0\x0B\\")
            : '';
    }

    /**
     * Extrai imports de classe simples declarados antes da primeira classe.
     *
     * Group use e imports de function/const ficam fora deste parser.
     *
     * @param string $source Código-fonte mascarado.
     * @return array<string,string> Alias para FQCN sem barra inicial.
     */
    private function importsOf(string $source): array
    {
        $classOffset = preg_match('/\b(?:abstract\s+|final\s+)?class\s+[A-Za-z_]/', $source, $classMatch, PREG_OFFSET_CAPTURE) === 1
            ? (int) $classMatch[0][1]
            : strlen($source);
        $prefix = substr($source, 0, $classOffset);
        /** @var array<string,string> $imports Imports normalizados. */
        $imports = [];
        if (preg_match_all('/\buse\s+([^;]+);/', $prefix, $matches) === 0) {
            return $imports;
        }

        foreach ($matches[1] as $rawImport) {
            $import = trim((string) $rawImport);
            if (str_contains($import, '{') || str_starts_with(strtolower($import), 'function ') || str_starts_with(strtolower($import), 'const ')) {
                continue;
            }
            $parts = preg_split('/\s+as\s+/i', $import) ?: [];
            $fqcn = trim((string) ($parts[0] ?? ''), " \t\n\r\0\x0B\\");
            if ($fqcn === '') {
                continue;
            }
            $alias = isset($parts[1]) ? trim((string) $parts[1]) : $this->shortName($fqcn);
            $imports[$alias] = $fqcn;
        }
        return $imports;
    }

    /**
     * Resolve nome nominal usando FQCN, imports e namespace corrente.
     *
     * @param string $name Nome observado no source.
     * @param array<string,string> $uses Imports por alias.
     * @param string $namespace Namespace corrente.
     * @return string FQCN sem barra inicial.
     */
    private function resolveName(string $name, array $uses, string $namespace): string
    {
        $trimmed = ltrim(trim($name), '\\');
        if (str_starts_with(trim($name), '\\')) {
            return $trimmed;
        }
        $segments = explode('\\', $trimmed);
        $first = $segments[0] ?? '';
        if (isset($uses[$first])) {
            array_shift($segments);
            return $uses[$first] . ($segments === [] ? '' : '\\' . implode('\\', $segments));
        }
        return $namespace === '' ? $trimmed : $namespace . '\\' . $trimmed;
    }

    /**
     * Extrai o último segmento de um FQCN.
     *
     * @param string $fqcn FQCN sem barra inicial.
     * @return string Nome curto.
     */
    private function shortName(string $fqcn): string
    {
        $position = strrpos($fqcn, '\\');
        return $position === false ? $fqcn : substr($fqcn, $position + 1);
    }

    /**
     * Converte path absoluto em path relativo estável ao root do consumidor.
     *
     * @param string $root Raiz absoluta do consumidor.
     * @param string $file Arquivo absoluto observado.
     * @return string Path relativo com separador `/`.
     */
    private function relativePath(string $root, string $file): string
    {
        return str_replace('\\', '/', substr($file, strlen(rtrim($root, DIRECTORY_SEPARATOR)) + 1));
    }
}
