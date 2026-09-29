<?php

declare(strict_types=1);

require_once __DIR__ . '/ProjectContext.php';
require_once __DIR__ . '/Yii2SemanticModel.php';

/**
 * Verifica contratos PHPDoc de propriedades mágicas para relações ActiveRecord Yii2.
 *
 * A primeira tranche é intencionalmente conservadora: somente classes que já mantêm
 * algum `@property*` no PHPDoc entram na verificação. Relações com target literal devem
 * então possuir uma tag para a propriedade mágica correspondente. Classes sem contrato
 * de properties, com magic accessors customizados ou com propriedade pública nativa de
 * mesmo nome são ignoradas para evitar transformar preferência documental em erro.
 */
final class Yii2MagicPropertyAnalyzer
{
    /**
     * Retorna relações cuja propriedade mágica está ausente de um PHPDoc já existente.
     *
     * A regra verifica presença, não corrige tipo nesta tranche. `expected_tag` usa FQCN
     * explícito para fornecer uma sugestão inequívoca sem depender dos imports atuais.
     *
     * @param ProjectContext $context Contexto Yii2 usado para abrir os arquivos do snapshot.
     * @param Yii2SemanticModel $model Modelo que fornece relações `hasOne()`/`hasMany()`.
     * @return list<array{
     *   file:string,
     *   line:int,
     *   model:string,
     *   relation:string,
     *   kind:'hasOne'|'hasMany',
     *   target:string,
     *   expected_type:string,
     *   expected_tag:string
     * }> Relações documentáveis cujo tag está ausente.
     */
    public function references(ProjectContext $context, Yii2SemanticModel $model): array
    {
        if ($context->profile() !== 'yii2') {
            return [];
        }

        /** @var array<string,array{source:string,namespace:string,uses:array<string,string>}> $cache Source e resolução por arquivo. */
        $cache = [];
        /** @var list<array{file:string,line:int,model:string,relation:string,kind:'hasOne'|'hasMany',target:string,expected_type:string,expected_tag:string}> $references */
        $references = [];

        foreach ($model->relations() as $relation) {
            if ($relation['target'] === null) {
                continue;
            }
            $file = $relation['file'];
            if (!isset($cache[$file])) {
                $absolute = $context->root() . '/' . $file;
                if (!is_file($absolute)) {
                    continue;
                }
                $source = (string) file_get_contents($absolute);
                $cache[$file] = [
                    'source' => $source,
                    'namespace' => $this->namespaceOf($source),
                    'uses' => $this->importsOf($source),
                ];
            }

            $source = $cache[$file]['source'];
            // Magic accessors customizados podem mudar completamente a semântica de get/set; nesse caso o PHPDoc não é inferido.
            if (preg_match('/\\bfunction\\s+__(?:get|set)\\s*\\(/', $source) === 1) {
                continue;
            }

            $short = $this->shortName($relation['model']);
            $doc = $this->classDocblock($source, $short);
            if ($doc === null || preg_match('/@property(?:-read|-write)?\\s+[^$\\r\\n]+\\$[A-Za-z_][A-Za-z0-9_]*/', $doc['doc']) !== 1) {
                continue;
            }
            if ($this->hasPropertyTag($doc['doc'], $relation['name']) || $this->hasPublicNativeProperty($source, $relation['name'])) {
                continue;
            }

            $target = $this->resolveName(
                $relation['target'],
                $cache[$file]['uses'],
                $cache[$file]['namespace'],
            );
            $expectedType = $relation['kind'] === 'hasMany' ? '\\' . $target . '[]' : '\\' . $target . '|null';
            $references[] = [
                'file' => $file,
                'line' => $doc['line'],
                'model' => $relation['model'],
                'relation' => $relation['name'],
                'kind' => $relation['kind'],
                'target' => $target,
                'expected_type' => $expectedType,
                'expected_tag' => '@property-read ' . $expectedType . ' $' . $relation['name'],
            ];
        }

        return $references;
    }

    /**
     * Localiza o PHPDoc imediatamente associado à classe informada.
     *
     * @param string $source Código-fonte completo.
     * @param string $shortClass Nome curto exato da classe.
     * @return array{doc:string,line:int}|null Docblock e linha da declaração ou null.
     */
    private function classDocblock(string $source, string $shortClass): ?array
    {
        $pattern = '/(?P<doc>\/\\*\\*.*?\\*\/)[\\t ]*(?:\\r?\\n[\\t ]*)*(?:final\\s+|abstract\\s+)?class\\s+'
            . preg_quote($shortClass, '/') . '\\b/s';
        if (preg_match($pattern, $source, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $offset = (int) $match['doc'][1];
        return [
            'doc' => (string) $match['doc'][0],
            'line' => substr_count(substr($source, 0, $offset), "\n") + 1,
        ];
    }

    /**
     * Verifica se qualquer modalidade de `@property*` já documenta a relação.
     *
     * @param string $doc Docblock da classe.
     * @param string $property Nome da propriedade mágica sem `$`.
     * @return bool True quando o nome já aparece em tag de property.
     */
    private function hasPropertyTag(string $doc, string $property): bool
    {
        return preg_match(
            '/@property(?:-read|-write)?\\s+[^$\\r\\n]+\\$' . preg_quote($property, '/') . '\\b/',
            $doc,
        ) === 1;
    }

    /**
     * Evita sugerir propriedade mágica quando a classe já declara propriedade pública nativa.
     *
     * @param string $source Código-fonte completo do arquivo.
     * @param string $property Nome da propriedade sem `$`.
     * @return bool True quando uma declaração pública compatível foi observada.
     */
    private function hasPublicNativeProperty(string $source, string $property): bool
    {
        return preg_match(
            '/\\bpublic\\s+(?:(?:readonly|static)\\s+)*(?:[?\\\\A-Za-z_][A-Za-z0-9_|&?\\\\]*\\s+)?\\$'
            . preg_quote($property, '/') . '\\b/',
            $source,
        ) === 1;
    }

    /**
     * Extrai namespace do arquivo sem executar o consumidor.
     *
     * @param string $source Código-fonte completo.
     * @return string Namespace sem barra inicial.
     */
    private function namespaceOf(string $source): string
    {
        return preg_match('/\\bnamespace\\s+([^;{]+)\\s*[;{]/', $source, $match) === 1
            ? trim((string) $match[1], " \\t\\n\\r\\0\\x0B\\\\")
            : '';
    }

    /**
     * Extrai imports de classe anteriores à primeira declaração de classe.
     *
     * @param string $source Código-fonte completo.
     * @return array<string,string> Alias para FQCN sem barra inicial.
     */
    private function importsOf(string $source): array
    {
        $classOffset = preg_match('/\\b(?:abstract\\s+|final\\s+)?class\\s+[A-Za-z_]/', $source, $classMatch, PREG_OFFSET_CAPTURE) === 1
            ? (int) $classMatch[0][1]
            : strlen($source);
        $prefix = substr($source, 0, $classOffset);
        /** @var array<string,string> $imports Imports normalizados. */
        $imports = [];
        if (preg_match_all('/\\buse\\s+([^;]+);/', $prefix, $matches) === 0) {
            return $imports;
        }

        foreach ($matches[1] as $rawImport) {
            $import = trim((string) $rawImport);
            if (str_contains($import, '{') || str_starts_with(strtolower($import), 'function ') || str_starts_with(strtolower($import), 'const ')) {
                continue;
            }
            $parts = preg_split('/\\s+as\\s+/i', $import) ?: [];
            $fqcn = trim((string) ($parts[0] ?? ''), " \\t\\n\\r\\0\\x0B\\\\");
            if ($fqcn === '') {
                continue;
            }
            $alias = isset($parts[1]) ? trim((string) $parts[1]) : $this->shortName($fqcn);
            $imports[$alias] = $fqcn;
        }
        return $imports;
    }

    /**
     * Resolve nome relativo/importado para FQCN sem barra inicial.
     *
     * @param string $name Nome observado no source.
     * @param array<string,string> $uses Imports normalizados.
     * @param string $namespace Namespace atual.
     * @return string FQCN resolvido.
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
     * Extrai o último segmento de um FQCN para correlacionar a declaração local.
     *
     * @param string $fqcn Nome qualificado sem barra inicial.
     * @return string Nome curto da classe.
     */
    private function shortName(string $fqcn): string
    {
        $position = strrpos($fqcn, '\\');
        return $position === false ? $fqcn : substr($fqcn, $position + 1);
    }
}
