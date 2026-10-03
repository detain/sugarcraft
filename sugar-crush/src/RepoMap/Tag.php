<?php

declare(strict_types=1);

namespace SugarCraft\Crush\RepoMap;

/**
 * One symbol occurrence in one file. It is either a definition (where the
 * symbol is declared) or a reference (where it is used). This is the unit
 * the symbol-level repo map ranks.
 *
 * It mirrors Aider's `Tag` named tuple (`rel_fname, fname, line, name, kind`),
 * with two deliberate differences:
 *  - it keeps only the path relative to the project root, because the cache
 *    and the map are both root-relative and an absolute path would key the
 *    same checkout two ways;
 *  - it adds `type` (class, method, const, ...) for definitions, so the
 *    renderer can print a signature-shaped line without re-reading the file.
 *
 * A reference's `type` is always {@see self::TYPE_REFERENCE}, because the
 * extractor cannot know what a bare identifier resolves to. PageRank only
 * needs the name.
 */
final readonly class Tag
{
    public const KIND_DEFINITION = 'def';
    public const KIND_REFERENCE = 'ref';

    public const TYPE_CLASS = 'class';
    public const TYPE_INTERFACE = 'interface';
    public const TYPE_TRAIT = 'trait';
    public const TYPE_ENUM = 'enum';
    public const TYPE_FUNCTION = 'function';
    public const TYPE_METHOD = 'method';
    public const TYPE_CONSTANT = 'const';
    public const TYPE_ENUM_CASE = 'case';
    public const TYPE_PROPERTY = 'property';
    public const TYPE_REFERENCE = 'ref';

    private const TYPES = [
        self::TYPE_CLASS, self::TYPE_INTERFACE, self::TYPE_TRAIT, self::TYPE_ENUM,
        self::TYPE_FUNCTION, self::TYPE_METHOD, self::TYPE_CONSTANT,
        self::TYPE_ENUM_CASE, self::TYPE_PROPERTY, self::TYPE_REFERENCE,
    ];

    /**
     * @param string $relPath Project-root-relative path, `/`-separated.
     * @param int    $line    1-based line of the name token.
     * @param string $name    The bare identifier (no namespace, no `$`).
     * @param string $kind    {@see self::KIND_DEFINITION} or {@see self::KIND_REFERENCE}.
     * @param string $type    One of the `TYPE_*` constants.
     * @param string $scope   Enclosing class-like name for members, '' otherwise.
     */
    private function __construct(
        public string $relPath,
        public int $line,
        public string $name,
        public string $kind,
        public string $type,
        public string $scope,
    ) {}

    /**
     * @throws \InvalidArgumentException for an unknown kind or type, an empty
     *         name, a line below 1, or a reference carrying a definition type.
     */
    public static function new(string $relPath, int $line, string $name, string $kind, string $type, string $scope = ''): self
    {
        if ($name === '') {
            throw new \InvalidArgumentException('A tag needs a non-empty name.');
        }
        if ($line < 1) {
            throw new \InvalidArgumentException("A tag line is 1-based; got {$line}.");
        }
        if ($kind !== self::KIND_DEFINITION && $kind !== self::KIND_REFERENCE) {
            throw new \InvalidArgumentException("Unknown tag kind '{$kind}'.");
        }
        if (!\in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException("Unknown tag type '{$type}'.");
        }
        if (($kind === self::KIND_REFERENCE) !== ($type === self::TYPE_REFERENCE)) {
            throw new \InvalidArgumentException('A reference tag has type ref, and only a reference does.');
        }

        return new self($relPath, $line, $name, $kind, $type, $scope);
    }

    public static function definition(string $relPath, int $line, string $name, string $type, string $scope = ''): self
    {
        return self::new($relPath, $line, $name, self::KIND_DEFINITION, $type, $scope);
    }

    public static function reference(string $relPath, int $line, string $name): self
    {
        return self::new($relPath, $line, $name, self::KIND_REFERENCE, self::TYPE_REFERENCE);
    }

    public function isDefinition(): bool
    {
        return $this->kind === self::KIND_DEFINITION;
    }

    /**
     * Rebuild a tag from {@see toArray()}'s shape, the row form
     * {@see TagCache} stores.
     *
     * @param array{path:string,line:int|string,name:string,kind:string,type:string,scope?:string} $row
     */
    public static function fromArray(array $row): self
    {
        return self::new($row['path'], (int) $row['line'], $row['name'], $row['kind'], $row['type'], $row['scope'] ?? '');
    }

    /** @return array{path:string,line:int,name:string,kind:string,type:string,scope:string} */
    public function toArray(): array
    {
        return [
            'path' => $this->relPath,
            'line' => $this->line,
            'name' => $this->name,
            'kind' => $this->kind,
            'type' => $this->type,
            'scope' => $this->scope,
        ];
    }
}
