<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tools\UserTools;

/**
 * One validated user-tier script tool, as {@see UserToolLoader} read it from
 * `~/.candy-code/tools/`.
 *
 * The shape (C-TOOL-6.e): `{"name","description","inputSchema","argv",
 * "timeout"?}` — exactly one tool per file, and EVERY rule of the shape is
 * checked here, at LOAD time, so nothing that reaches a {@see UserScriptTool}
 * needed a runtime re-check. A file that fails any rule is skipped and named;
 * the launcher never gets a half-valid tool.
 *
 * WHY the checks land on the load side rather than the execute side: argv is
 * the command surface. A `{{prop}}` that names no declared property, or one
 * sitting in element 0, is a tool file that cannot mean what it spells — and a
 * person debugging a skipped tool file deserves the bad news at launch, once,
 * not as a mysterious model-visible failure three turns in.
 *
 * This class validates; it does not touch a terminal or the Lang catalogue.
 * The skip announcements it makes possible are assembled by the loader, which
 * owns the human-facing wording.
 */
final readonly class UserToolSpec
{
    /** A tool name is a filesystem-stable slug, and it caps at 48 characters. */
    public const NAME_PATTERN = '/^[a-z][a-z0-9_-]{0,47}$/';

    /** The description bound the shape allows, in bytes. */
    public const MAX_DESCRIPTION_BYTES = 1024;

    /** The declared per-tool wall-clock bound may not exceed this (seconds). */
    public const MAX_TIMEOUT_SECONDS = 600;

    /** …and may not be shorter than this (seconds). */
    public const MIN_TIMEOUT_SECONDS = 1;

    /** What `timeout` means when the file says nothing. */
    public const DEFAULT_TIMEOUT_SECONDS = 60;

    /** The spelling a substitution placeholder takes inside an argv element. */
    public const PLACEHOLDER_PATTERN = '/\{\{([A-Za-z0-9_-]+)\}\}/';

    /**
     * @param list<string> $argv the program and its fixed argument spine
     * @param array<string, mixed> $inputSchema the declared JSON Schema (always `type: object`)
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $inputSchema,
        public array $argv,
        public int $timeoutSeconds,
    ) {}

    /**
     * The whole file contract, as a verdict: null when the decoded shape IS a
     * tool, otherwise the ONE-WORD class of what made it not one — mapped to a
     * `cli.usertools.skip.*` row by {@see UserToolLoader}.
     *
     * @param array<array-key, mixed> $decoded the file's JSON, already decoded
     */
    public static function refusal(array $decoded): ?string
    {
        if (array_keys($decoded) === []) {
            return 'bad_shape';
        }

        // Unknown top-level keys are tolerated (a future field must not brick
        // an old build's loader), but every KNOWN one must be exactly its
        // declared type — no coercion, no trimming, no reinterpretation.
        $name = $decoded['name'] ?? null;
        if (!is_string($name) || preg_match(self::NAME_PATTERN, $name) !== 1) {
            return 'bad_name';
        }

        $description = $decoded['description'] ?? null;
        if (!is_string($description) || $description === '' || strlen($description) > self::MAX_DESCRIPTION_BYTES) {
            return 'bad_description';
        }

        $schema = $decoded['inputSchema'] ?? null;
        if (!is_array($schema) || ($schema['type'] ?? null) !== 'object') {
            return 'bad_schema';
        }

        $properties = $schema['properties'] ?? [];
        if (!is_array($properties)) {
            return 'bad_schema';
        }

        $argv = $decoded['argv'] ?? null;
        if (!is_array($argv) || $argv === [] || !array_is_list($argv)) {
            return 'bad_argv';
        }

        foreach ($argv as $element) {
            if (!is_string($element)) {
                return 'bad_argv';
            }
        }

        /** @var list<string> $argv */
        // Element 0 is the program. A placeholder there could only be
        // substituted by model-supplied text choosing WHICH BINARY runs —
        // the one shape where a declared tool stops being the bound the
        // person writing the file meant it to be.
        if (str_contains($argv[0], '{{')) {
            return 'argv_placeholder';
        }

        $declared = array_keys($properties);
        foreach ($argv as $element) {
            preg_match_all(self::PLACEHOLDER_PATTERN, $element, $hits);

            foreach ($hits[1] as $placeholder) {
                if (!in_array($placeholder, $declared, true)) {
                    return 'undeclared_placeholder';
                }
            }

            // After the well-formed placeholders are lifted, no `{{` may
            // remain: `{{}` and `{{name` are not placeholders, they are
            // typos that would otherwise ship to the shell as themselves.
            $lifted = (string) preg_replace(self::PLACEHOLDER_PATTERN, '', $element);
            if (str_contains($lifted, '{{')) {
                return 'undeclared_placeholder';
            }
        }

        $timeout = $decoded['timeout'] ?? self::DEFAULT_TIMEOUT_SECONDS;
        if (!is_int($timeout)
            || $timeout < self::MIN_TIMEOUT_SECONDS
            || $timeout > self::MAX_TIMEOUT_SECONDS
        ) {
            return 'bad_timeout';
        }

        return null;
    }

    /**
     * Build the validated spec. The caller has checked {@see refusal()}
     * answers null; this method only narrows types for the constructor.
     *
     * @param array<array-key, mixed> $decoded
     */
    public static function fromDecoded(array $decoded): self
    {
        /** @var string $name */
        $name = $decoded['name'];
        /** @var string $description */
        $description = $decoded['description'];
        /** @var array<string, mixed> $schema */
        $schema = $decoded['inputSchema'];
        /** @var list<string> $argv */
        $argv = $decoded['argv'];

        return new self(
            name: $name,
            description: $description,
            inputSchema: $schema,
            argv: $argv,
            timeoutSeconds: (int) ($decoded['timeout'] ?? self::DEFAULT_TIMEOUT_SECONDS),
        );
    }

    /**
     * The property names this tool declares.
     *
     * @return list<string>
     */
    public function declaredProperties(): array
    {
        /** @var list<string> */
        return array_keys($this->inputSchema['properties'] ?? []);
    }

    /**
     * The property names a call must supply.
     *
     * @return list<string>
     */
    public function requiredProperties(): array
    {
        $required = $this->inputSchema['required'] ?? [];

        /** @var list<string> */
        return is_array($required) ? array_values(array_filter($required, 'is_string')) : [];
    }

    /**
     * The placeholders one argv element carries, in order of appearance.
     *
     * @return list<string>
     */
    public static function placeholdersIn(string $element): array
    {
        preg_match_all(self::PLACEHOLDER_PATTERN, $element, $hits);

        /** @var list<string> $hits1 */
        $hits1 = $hits[1];

        return $hits1;
    }
}
