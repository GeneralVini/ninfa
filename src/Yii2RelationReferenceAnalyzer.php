<?php

declare(strict_types=1);

require_once __DIR__ . '/ProjectContext.php';
require_once __DIR__ . '/Yii2SemanticModel.php';

/**
 * Resolve referências literais a relações em chains ActiveQuery de projetos Yii2.
 *
 * O analisador reutiliza o inventário `hasOne()`/`hasMany()` do Yii2SemanticModel e
 * só declara ausência quando consegue provar localmente a classe ActiveRecord e sua
 * cadeia de herança. Herança externa não reconhecida, traits e expressões dinâmicas
 * degradam para `unknown` em vez de produzir falso positivo.
 */
final class Yii2RelationReferenceAnalyzer
{
    /** @var list<string> Bases Yii2 cujo contrato de relações herdadas é conhecido. */
    private const ACTIVE_RECORD_BASES = [
        'yii\\db\\ActiveRecord',
        'yii\\db\\BaseActiveRecord',
        'yii\\redis\\ActiveRecord',
        'yii\\mongodb\\ActiveRecord',
    ];

    /**
     * Localiza chamadas literais `Model::find()->with()/joinWith()/innerJoinWith()`.
     *
     * Apenas strings literais são avaliadas. Arrays, variáveis, closures e demais
     * expressões permanecem fora desta tranche. Para classes com inventário completo,
     * cada segmento pontuado é validado contra as relações observadas, incluindo
     * relações herdadas de parents locais. Aliases de `joinWith`, como `items i`, são
     * normalizados apenas para a resolução e preservados na evidência original.
     *
     * @param ProjectContext $context Contexto Yii2 que delimita root e paths elegíveis.
     * @param Yii2SemanticModel $model Snapshot que fornece as relações declaradas.
     * @return list<array{
     *   file:string,
     *   line:int,
     *   method:string,
     *   model:string,
     *   relation_path:string,
     *   missing_relation:string|null,
     *   resolved_prefix:string,
     *   exists:bool|null,
     *   relation_inventory_complete:bool
     * }> Referências estáticas observadas em ordem de arquivo e ocorrência.
     */
    public function references(ProjectContext $context, Yii2SemanticModel $model): array
    {
        if ($context->profile() !== 'yii2') {
            return [];
        }

        $files = $this->phpFiles($context);
        $classes = $this->classMetadata($files);
        $relations = $this->relationMap($context, $model);

        /** @var list<array{file:string,line:int,method:string,model:string,relation_path:string,missing_relation:string|null,resolved_prefix:string,exists:bool|null,relation_inventory_complete:bool}> $references */
        $references = [];
        $pattern = '/(?P<class>\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*)::find\s*\(\s*\)\s*->\s*(?P<method>with|joinWith|innerJoinWith)\s*\(\s*(?P<quote>[\'\"])(?P<path>[^\'\"]+)\k<quote>/i';

        // O scan é textual por desenho: somente o subconjunto literal comprovável entra na regra nativa.
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            $namespace = $this->namespaceOf($source);
            $uses = $this->importsOf($source);
            if (preg_match_all($pattern, $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === 0) {
                continue;
            }

            foreach ($matches as $match) {
                $rawClass = (string) $match['class'][0];
                if (in_array(strtolower($rawClass), ['self', 'static', 'parent'], true)) {
                    continue;
                }

                $class = $this->resolveName($rawClass, $uses, $namespace);
                $path = (string) $match['path'][0];
                $complete = $this->classInventoryComplete($class, $classes, []);
                $resolution = $complete
                    ? $this->resolvePath($class, $path, $relations, $classes)
                    : ['exists' => null, 'missing_relation' => null, 'resolved_prefix' => ''];

                $offset = (int) $match['path'][1];
                $references[] = [
                    'file' => $this->relativePath($context->root(), $file),
                    'line' => substr_count(substr($source, 0, $offset), "\n") + 1,
                    'method' => (string) $match['method'][0],
                    'model' => $class,
                    'relation_path' => $path,
                    'missing_relation' => $resolution['missing_relation'],
                    'resolved_prefix' => $resolution['resolved_prefix'],
                    'exists' => $resolution['exists'],
                    'relation_inventory_complete' => $complete,
                ];
            }
        }

        return $references;
    }

    /**
     * Lista PHP apenas nos paths já autorizados pelo ProjectContext.
     *
     * @param ProjectContext $context Contexto que fornece root e paths do consumidor.
     * @return list<string> Arquivos PHP absolutos, únicos e ordenados.
     */
    private function phpFiles(ProjectContext $context): array
    {
        /** @var list<string> $files Arquivos PHP elegíveis à análise de referências. */
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
     * Indexa classes locais, parent resolvido e uso de trait que reduz completude.
     *
     * Imports são lidos apenas antes da primeira declaração de classe para não
     * confundir `use SomeTrait;` interno com import de namespace. Uma classe que usa
     * trait é mantida no índice, porém seu inventário não é considerado conclusivo.
     *
     * @param list<string> $files Arquivos PHP candidatos.
     * @return array<string,array{file:string,parent:string|null,trait_use:bool}> Metadados por FQCN local.
     */
    private function classMetadata(array $files): array
    {
        /** @var array<string,array{file:string,parent:string|null,trait_use:bool}> $classes */
        $classes = [];

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            $namespace = $this->namespaceOf($source);
            $uses = $this->importsOf($source);
            $pattern = '/\b(?:abstract\s+|final\s+)?class\s+([A-Za-z_][A-Za-z0-9_]*)(?:\s+extends\s+(\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*))?/';
            if (preg_match_all($pattern, $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === 0) {
                continue;
            }

            foreach ($matches as $match) {
                $short = (string) $match[1][0];
                $class = $namespace === '' ? $short : $namespace . '\\' . $short;
                $parentRaw = isset($match[2][0]) ? trim((string) $match[2][0]) : '';
                $parent = $parentRaw === '' ? null : $this->resolveName($parentRaw, $uses, $namespace);
                $offset = (int) $match[0][1];
                $tail = substr($source, $offset);
                $traitUse = preg_match('/\buse\s+[A-Za-z_\\\\][A-Za-z0-9_\\\\]*(?:\s*,\s*[A-Za-z_\\\\][A-Za-z0-9_\\\\]*)*\s*;/', $tail) === 1;

                $classes[$class] = [
                    'file' => $file,
                    'parent' => $parent,
                    'trait_use' => $traitUse,
                ];
            }
        }

        return $classes;
    }

    /**
     * Normaliza o inventário de relações por FQCN e resolve o target literal.
     *
     * @param ProjectContext $context Contexto usado para abrir os arquivos relativos do snapshot.
     * @param Yii2SemanticModel $model Snapshot com relações `hasOne`/`hasMany` observadas.
     * @return array<string,array<string,string|null>> Mapa model => relation => target FQCN/null.
     */
    private function relationMap(ProjectContext $context, Yii2SemanticModel $model): array
    {
        /** @var array<string,array<string,string|null>> $map Relações resolvidas por model. */
        $map = [];
        /** @var array<string,array{namespace:string,uses:array<string,string>}> $fileContext Cache de resolução por arquivo. */
        $fileContext = [];

        foreach ($model->relations() as $relation) {
            $relativeFile = $relation['file'];
            if (!isset($fileContext[$relativeFile])) {
                $source = (string) file_get_contents($context->root() . '/' . $relativeFile);
                $fileContext[$relativeFile] = [
                    'namespace' => $this->namespaceOf($source),
                    'uses' => $this->importsOf($source),
                ];
            }

            $target = $relation['target'];
            $resolvedTarget = $target === null
                ? null
                : $this->resolveName(
                    $target,
                    $fileContext[$relativeFile]['uses'],
                    $fileContext[$relativeFile]['namespace'],
                );
            $map[$relation['model']][$relation['name']] = $resolvedTarget;
        }

        return $map;
    }

    /**
     * Determina se a classe possui inventário suficientemente completo para negar relação.
     *
     * Uma classe é conclusiva apenas quando sua herança termina em um ActiveRecord Yii2
     * conhecido ou em parent local igualmente conclusivo. Traits tornam o resultado
     * incompleto porque podem introduzir getters de relação fora do arquivo da classe.
     *
     * @param string $class FQCN local candidato.
     * @param array<string,array{file:string,parent:string|null,trait_use:bool}> $classes Índice local.
     * @param array<string,bool> $visited Proteção contra ciclos de herança inválidos.
     * @return bool True quando ausência no inventário pode ser tratada como evidência.
     */
    private function classInventoryComplete(string $class, array $classes, array $visited): bool
    {
        if (isset($visited[$class]) || !isset($classes[$class])) {
            return false;
        }
        if ($classes[$class]['trait_use']) {
            return false;
        }

        $parent = $classes[$class]['parent'];
        if ($parent === null) {
            return false;
        }
        if (in_array($parent, self::ACTIVE_RECORD_BASES, true)) {
            return true;
        }

        // Parents locais são seguidos recursivamente; parent externo desconhecido impede prova de ausência.
        $visited[$class] = true;
        return $this->classInventoryComplete($parent, $classes, $visited);
    }

    /**
     * Valida cada segmento de uma relation path usando relações próprias e herdadas.
     *
     * @param string $modelClass FQCN do ActiveRecord de origem.
     * @param string $path Relation path literal, possivelmente pontuada/aliased.
     * @param array<string,array<string,string|null>> $relations Relações normalizadas por classe.
     * @param array<string,array{file:string,parent:string|null,trait_use:bool}> $classes Índice local.
     * @return array{exists:bool|null,missing_relation:string|null,resolved_prefix:string} Resultado conservador.
     */
    private function resolvePath(string $modelClass, string $path, array $relations, array $classes): array
    {
        $segments = explode('.', $path);
        $current = $modelClass;
        /** @var list<string> $resolved Segmentos comprovadamente encontrados. */
        $resolved = [];

        foreach ($segments as $segment) {
            $trimmed = trim($segment);
            $parts = preg_split('/\s+/', $trimmed, 2) ?: [];
            $relation = $parts[0] ?? '';
            if ($relation === '') {
                return ['exists' => null, 'missing_relation' => null, 'resolved_prefix' => implode('.', $resolved)];
            }

            $available = $this->relationsForClass($current, $relations, $classes, []);
            if (!array_key_exists($relation, $available)) {
                return [
                    'exists' => false,
                    'missing_relation' => $relation,
                    'resolved_prefix' => implode('.', $resolved),
                ];
            }

            $resolved[] = $relation;
            $target = $available[$relation];
            if ($target === null) {
                return ['exists' => null, 'missing_relation' => null, 'resolved_prefix' => implode('.', $resolved)];
            }
            $current = $target;

            // Segmentos seguintes só podem ser negados quando o target local também tem inventário completo.
            if ($segment !== end($segments) && !$this->classInventoryComplete($current, $classes, [])) {
                return ['exists' => null, 'missing_relation' => null, 'resolved_prefix' => implode('.', $resolved)];
            }
        }

        return ['exists' => true, 'missing_relation' => null, 'resolved_prefix' => implode('.', $resolved)];
    }

    /**
     * Combina relações herdadas de parents locais com as relações declaradas na classe.
     *
     * Relações da classe filha prevalecem sobre nomes herdados, espelhando override de
     * getter. Bases framework conhecidas encerram a recursão sem adicionar relações.
     *
     * @param string $class FQCN cuja visão efetiva será montada.
     * @param array<string,array<string,string|null>> $relations Mapa de relações próprias.
     * @param array<string,array{file:string,parent:string|null,trait_use:bool}> $classes Índice local.
     * @param array<string,bool> $visited Proteção contra ciclos.
     * @return array<string,string|null> Relações efetivas por nome.
     */
    private function relationsForClass(string $class, array $relations, array $classes, array $visited): array
    {
        if (isset($visited[$class])) {
            return [];
        }
        $visited[$class] = true;

        /** @var array<string,string|null> $effective Relações herdadas e próprias. */
        $effective = [];
        $parent = $classes[$class]['parent'] ?? null;
        if ($parent !== null && isset($classes[$parent])) {
            $effective = $this->relationsForClass($parent, $relations, $classes, $visited);
        }
        foreach ($relations[$class] ?? [] as $name => $target) {
            $effective[$name] = $target;
        }

        return $effective;
    }

    /**
     * Extrai namespace declarado no arquivo.
     *
     * @param string $source Código-fonte PHP completo.
     * @return string Namespace sem barra inicial ou string vazia.
     */
    private function namespaceOf(string $source): string
    {
        return preg_match('/\bnamespace\s+([^;{]+)\s*[;{]/', $source, $match) === 1
            ? trim($match[1])
            : '';
    }

    /**
     * Extrai imports de classe simples declarados antes da primeira classe.
     *
     * Group use, `use function` e `use const` permanecem fora desta tranche. O alias
     * explícito ou o nome curto final é usado como chave do mapa de resolução.
     *
     * @param string $source Código-fonte PHP completo.
     * @return array<string,string> Mapa alias/nome curto => FQCN sem barra inicial.
     */
    private function importsOf(string $source): array
    {
        $classPosition = preg_match('/\bclass\s+[A-Za-z_]/', $source, $classMatch, PREG_OFFSET_CAPTURE) === 1
            ? (int) $classMatch[0][1]
            : strlen($source);
        $prefix = substr($source, 0, $classPosition);
        /** @var array<string,string> $uses Imports simples por alias. */
        $uses = [];
        $pattern = '/^\s*use\s+(?!function\b|const\b)([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)(?:\s+as\s+([A-Za-z_][A-Za-z0-9_]*))?\s*;/mi';
        if (preg_match_all($pattern, $prefix, $matches, PREG_SET_ORDER) === 0) {
            return $uses;
        }

        foreach ($matches as $match) {
            $fqcn = ltrim((string) $match[1], '\\');
            $segments = explode('\\', $fqcn);
            $alias = isset($match[2]) && $match[2] !== '' ? (string) $match[2] : (string) end($segments);
            $uses[$alias] = $fqcn;
        }

        return $uses;
    }

    /**
     * Resolve nome curto/qualificado conforme imports e namespace do arquivo.
     *
     * @param string $name Nome textual encontrado no source.
     * @param array<string,string> $uses Imports simples por alias.
     * @param string $namespace Namespace corrente.
     * @return string FQCN normalizado sem barra inicial.
     */
    private function resolveName(string $name, array $uses, string $namespace): string
    {
        $name = trim($name);
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }

        $parts = explode('\\', $name, 2);
        $head = $parts[0];
        if (isset($uses[$head])) {
            return $uses[$head] . (isset($parts[1]) ? '\\' . $parts[1] : '');
        }
        if (str_contains($name, '\\')) {
            return $namespace === '' ? $name : $namespace . '\\' . $name;
        }

        return $namespace === '' ? $name : $namespace . '\\' . $name;
    }

    /**
     * Relativiza arquivo físico para evidência estável do Finding.
     *
     * @param string $root Raiz física do consumidor.
     * @param string $file Arquivo absoluto observado.
     * @return string Path relativo quando o arquivo pertence ao projeto.
     */
    private function relativePath(string $root, string $file): string
    {
        $prefix = rtrim(str_replace('\\', '/', $root), '/') . '/';
        $normalized = str_replace('\\', '/', $file);
        return str_starts_with($normalized, $prefix) ? substr($normalized, strlen($prefix)) : $normalized;
    }
}
