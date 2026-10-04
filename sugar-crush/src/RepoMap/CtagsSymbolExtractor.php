<?php

declare(strict_types=1);

namespace SugarCraft\Crush\RepoMap;

use SugarCraft\Crush\Tools\Concerns\CapturesProcessOutput;
use SugarCraft\Crush\Tools\Concerns\DetectsCapabilities;

/**
 * Definitions and references in files of every language universal-ctags
 * knows, for the symbol-level repo map (roadmap 5.5-2). The second producer
 * beside {@see PhpSymbolExtractor}, which needs no binary and stays the PHP
 * path.
 *
 * It mirrors Aider's `RepoMap.get_tags_raw()` for the languages its tree-sitter
 * queries cover: definitions from a real parser, references from a lexical
 * pass. Universal-ctags reports DEFINITIONS only (`--output-format=json`), so
 * references come from {@see references()}, a regex identifier scan — the
 * same fallback Aider takes with Pygments when a grammar has no reference
 * query. Without references PageRank has nothing to rank a non-PHP file by.
 *
 * OPTIONAL AND CAPABILITY-PROBED. {@see available()} first walks `PATH`
 * (no subprocess for a host without the name — the
 * {@see DetectsCapabilities} rule), then asks `ctags --version` and accepts
 * only Universal Ctags built with `+json`. The name alone proves nothing:
 * Debian and Ubuntu ship GNU Emacs `ctags` (measured on this host) and BSDs
 * ship Exuberant-era ones, neither of which speaks JSON. The answer is
 * memoized per binary for the process.
 *
 * A BOUNDED CHILD, AND NO SPAWN SITE OF ITS OWN. Every run goes through
 * {@see CapturesProcessOutput::runCaptured()} — the package's one bounded
 * spawn path (`setsid -w` detach, credential-scrubbed environment, both pipes
 * drained, a wall-clock deadline ending in the 15→9 ladder) — with a per-batch
 * deadline ({@see TIMEOUT_SECONDS}) and a retained-output cap
 * ({@see MAX_OUTPUT_BYTES}), so `DescriptorInheritanceGuardTest` and
 * `tools/check-child-lifetimes.php` already account for it. A timed-out or
 * clipped batch answers for none of its files ({@see extractFiles()}).
 *
 * `--options=NONE` IS NOT OPTIONAL. Universal-ctags otherwise reads option
 * files from `./.ctags.d/` in its working directory — content a repository
 * chooses — and an option file can name an output file (`-o`) or redefine a
 * language. The repo map must never let a checkout steer a child it spawns,
 * so every run disables option files first (the option has to be first on
 * the line to apply).
 */
final class CtagsSymbolExtractor
{
    use CapturesProcessOutput;
    use DetectsCapabilities;

    public const BINARY = 'ctags';

    /**
     * Bump when {@see parse()} or {@see references()} answers differently for
     * the same input. Part of {@see cacheStamp()}, so {@see TagCache} re-runs
     * every file this extractor produced — and only those — instead of mixing
     * tags from two rule sets.
     */
    public const EXTRACTOR_VERSION = 1;

    /** Per-batch wall clock. ctags parses thousands of files a second. */
    public const TIMEOUT_SECONDS = 30.0;

    /** The version probe's wall clock. */
    public const PROBE_TIMEOUT_SECONDS = 5.0;

    /** Retained JSON output per batch. */
    public const MAX_OUTPUT_BYTES = 16 * 1024 * 1024;

    /** Files above this are skipped, as {@see PhpSymbolExtractor} skips them. */
    public const MAX_FILE_BYTES = PhpSymbolExtractor::MAX_FILE_BYTES;

    /** At most this many files per ctags run. */
    public const MAX_FILES_PER_BATCH = 256;

    /** At most this many bytes of file arguments per ctags run (well under ARG_MAX). */
    public const MAX_ARGV_BYTES = 65536;

    /** Reference tags kept per file, so one generated file cannot dominate the cache. */
    public const MAX_REFERENCES_PER_FILE = 20000;

    /**
     * Universal-ctags long kind names => {@see Tag} types. A kind missing here
     * (locals, parameters, imports, labels, headings, namespaces, plain
     * variables) is not a definition the map outlines.
     */
    private const KIND_TYPES = [
        'class' => Tag::TYPE_CLASS,
        'struct' => Tag::TYPE_CLASS,
        'union' => Tag::TYPE_CLASS,
        'record' => Tag::TYPE_CLASS,
        'object' => Tag::TYPE_CLASS,
        'type' => Tag::TYPE_CLASS,
        'typedef' => Tag::TYPE_CLASS,
        'alias' => Tag::TYPE_CLASS,
        'module' => Tag::TYPE_CLASS,
        'interface' => Tag::TYPE_INTERFACE,
        'protocol' => Tag::TYPE_INTERFACE,
        'trait' => Tag::TYPE_TRAIT,
        'mixin' => Tag::TYPE_TRAIT,
        'role' => Tag::TYPE_TRAIT,
        'enum' => Tag::TYPE_ENUM,
        'enumerator' => Tag::TYPE_ENUM_CASE,
        'enumConstant' => Tag::TYPE_ENUM_CASE,
        'function' => Tag::TYPE_FUNCTION,
        'func' => Tag::TYPE_FUNCTION,
        'subroutine' => Tag::TYPE_FUNCTION,
        'procedure' => Tag::TYPE_FUNCTION,
        'method' => Tag::TYPE_METHOD,
        'singletonMethod' => Tag::TYPE_METHOD,
        'constructor' => Tag::TYPE_METHOD,
        'accessor' => Tag::TYPE_METHOD,
        'constant' => Tag::TYPE_CONSTANT,
        'const' => Tag::TYPE_CONSTANT,
        'define' => Tag::TYPE_CONSTANT,
        'macro' => Tag::TYPE_CONSTANT,
        'field' => Tag::TYPE_PROPERTY,
        'property' => Tag::TYPE_PROPERTY,
        'member' => Tag::TYPE_PROPERTY,
    ];

    /** Kinds of a scope that makes a `function` a method. */
    private const CLASS_LIKE_SCOPES = [
        'class', 'struct', 'union', 'record', 'object', 'interface', 'protocol', 'trait', 'mixin',
        'role', 'enum', 'type', 'module', 'impl', 'implementation',
    ];

    /**
     * Words of the common languages that are never a reference. Not a
     * correctness filter — a keyword nobody defines draws no edge anyway — but
     * it keeps the cache from storing every `return` of a project.
     */
    private const NOT_REFERENCES = [
        'and', 'as', 'assert', 'async', 'await', 'break', 'case', 'catch', 'char', 'class', 'const',
        'continue', 'def', 'default', 'defer', 'del', 'do', 'double', 'elif', 'else', 'end', 'enum',
        'except', 'export', 'extends', 'extern', 'false', 'final', 'finally', 'float', 'fn', 'for',
        'from', 'func', 'function', 'go', 'goto', 'if', 'impl', 'implements', 'import', 'in', 'int',
        'interface', 'is', 'let', 'long', 'loop', 'match', 'mod', 'module', 'mut', 'new', 'nil',
        'none', 'not', 'null', 'or', 'package', 'pass', 'private', 'protected', 'pub', 'public',
        'raise', 'return', 'self', 'short', 'static', 'string', 'struct', 'super', 'switch', 'then',
        'this', 'throw', 'throws', 'true', 'try', 'type', 'typeof', 'undefined', 'unsigned', 'use',
        'var', 'void', 'where', 'while', 'with', 'yield',
    ];

    /** @var array<string, bool> binary => is Universal Ctags with JSON */
    private static array $universal = [];

    /** @var array<string, string> binary => the first line of its `--version` banner */
    private static array $banners = [];

    private function __construct(
        private readonly string $binary,
        private readonly float $timeoutSeconds,
    ) {
    }

    public static function new(): self
    {
        return new self(self::BINARY, self::TIMEOUT_SECONDS);
    }

    /**
     * Run a different executable — a name looked up on `PATH`, or a path.
     * The seam the tests point at a scripted stand-in, and the place an
     * embedder names a non-default install.
     */
    public function withBinary(string $binary): self
    {
        return new self($binary, $this->timeoutSeconds);
    }

    public function withTimeout(float $seconds): self
    {
        return new self($this->binary, \max(0.1, $seconds));
    }

    public function binary(): string
    {
        return $this->binary;
    }

    /**
     * Is {@see binary()} Universal Ctags with JSON output? A stat walk first,
     * then one bounded `--version` run; memoized per binary.
     */
    public function available(): bool
    {
        if (isset(self::$universal[$this->binary])) {
            return self::$universal[$this->binary];
        }

        if (!self::capabilityPresent($this->binary)) {
            return self::$universal[$this->binary] = false;
        }

        $run = $this->runCaptured(
            \escapeshellarg($this->binary) . ' --version',
            null,
            65536,
            self::PROBE_TIMEOUT_SECONDS,
        );

        self::$banners[$this->binary] = \trim((string) \strtok($run['stdout'], "\n"));

        return self::$universal[$this->binary] = !$run['timedOut']
            && $run['exitCode'] === 0
            && self::isUniversalWithJson($run['stdout']);
    }

    /**
     * The stamp {@see TagCache} stores beside every file this extractor
     * tagged: the extractor's own rule version and the binary's version
     * banner. Either moving — a ctags upgrade can change what its parsers
     * emit, an edited reference scan changes the references — turns every
     * row it stamped into a miss, so no tag outlives the code that made it.
     */
    public function cacheStamp(): string
    {
        $this->available();

        return 'ctags/' . self::EXTRACTOR_VERSION . '/'
            . \substr(\hash('xxh128', self::$banners[$this->binary] ?? ''), 0, 16);
    }

    /**
     * Does a `ctags --version` banner name Universal Ctags built with JSON?
     */
    public static function isUniversalWithJson(string $versionOutput): bool
    {
        return \str_contains($versionOutput, 'Universal Ctags')
            && \preg_match('/(?:^|[\s,])\+json\b/', $versionOutput) === 1;
    }

    /**
     * Tags for one file, or [] when ctags is unavailable or the file is
     * unreadable, oversized or of no language ctags parses.
     *
     * @return list<Tag>
     */
    public function extractFile(string $absPath, string $relPath): array
    {
        return $this->extractFiles([$relPath => $absPath])[$relPath] ?? [];
    }

    /**
     * Tags for many files, batched into as few ctags runs as the argv and
     * batch bounds allow. Every file it was asked about, could read and whose
     * batch finished gets an entry (possibly []); a file is ABSENT from the
     * answer when it was unreadable or oversized, when its batch hit the
     * deadline or the output cap, or when the binary is unavailable — so an
     * absent key always means "unknown", and a caller that caches only what
     * it was answered never stores a partial or a missing-binary "no tags".
     *
     * @param array<string, string> $files root-relative path => absolute path
     * @return array<string, list<Tag>> root-relative path => its tags
     */
    public function extractFiles(array $files): array
    {
        if ($files === [] || !$this->available()) {
            return [];
        }

        // absolute => relative, for the readable, bounded files only.
        $wanted = [];
        foreach ($files as $rel => $abs) {
            $size = @\filesize($abs);
            if ($size !== false && $size <= self::MAX_FILE_BYTES && \is_file($abs)) {
                $wanted[$abs] = (string) $rel;
            }
        }

        $definitions = [];
        foreach ($this->batches(\array_keys($wanted)) as $batch) {
            $run = $this->runCaptured(
                $this->commandFor($batch),
                null,
                self::MAX_OUTPUT_BYTES,
                $this->timeoutSeconds,
            );
            // A batch cut off by the deadline or the output cap answers for
            // none of its files: a partial answer would be cached as the
            // whole truth about each file until the file next changed.
            if ($run['timedOut'] || $run['stdoutDropped'] > 0) {
                foreach ($batch as $abs) {
                    unset($wanted[$abs]);
                }

                continue;
            }
            foreach (self::parse($run['stdout'], $wanted) as $rel => $tags) {
                $definitions[$rel] = $tags;
            }
        }

        $out = [];
        foreach ($wanted as $abs => $rel) {
            $source = @\file_get_contents($abs);
            if ($source === false) {
                continue;
            }
            $defs = $definitions[$rel] ?? [];
            $out[$rel] = \array_merge($defs, self::references($source, $rel, $defs));
        }

        return $out;
    }

    /**
     * Definition tags out of universal-ctags JSON lines. Pure, so the mapping
     * is testable on a host without the binary. A line that is not a whole
     * JSON tag object (a pseudo-tag, a line a clipped batch cut in half) is
     * skipped; a tag for a path not in $absToRel is ignored.
     *
     * @param array<string, string> $absToRel absolute path as passed to ctags => root-relative path
     * @return array<string, list<Tag>> root-relative path => definitions, in output order
     */
    public static function parse(string $jsonLines, array $absToRel): array
    {
        $out = [];
        foreach (\explode("\n", $jsonLines) as $line) {
            $line = \trim($line);
            if ($line === '' || $line[0] !== '{') {
                continue;
            }
            try {
                $row = \json_decode($line, true, 8, \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }
            if (!\is_array($row) || ($row['_type'] ?? null) !== 'tag') {
                continue;
            }

            $path = $row['path'] ?? null;
            $name = $row['name'] ?? null;
            $lineNo = $row['line'] ?? null;
            $kind = $row['kind'] ?? null;
            if (!\is_string($path) || !isset($absToRel[$path]) || !\is_string($name) || $name === ''
                || !\is_int($lineNo) || $lineNo < 1 || !\is_string($kind)
            ) {
                continue;
            }

            $type = self::KIND_TYPES[$kind] ?? null;
            if ($type === null) {
                continue;
            }

            $scope = \is_string($row['scope'] ?? null) ? self::lastScopeSegment($row['scope']) : '';
            $scopeKind = \is_string($row['scopeKind'] ?? null) ? $row['scopeKind'] : '';
            $classScoped = $scope !== '' && \in_array($scopeKind, self::CLASS_LIKE_SCOPES, true);
            // `member` is a field in Go and C but a method in Python; only a
            // callable carries a signature, so that is what tells them apart.
            if (($type === Tag::TYPE_FUNCTION && $classScoped)
                || ($kind === 'member' && \is_string($row['signature'] ?? null))
            ) {
                $type = Tag::TYPE_METHOD;
            }

            $rel = $absToRel[$path];
            $out[$rel][] = Tag::definition($rel, $lineNo, $name, $type, $classScoped ? $scope : '');
        }

        return $out;
    }

    /**
     * Reference tags for every identifier in $source, by a lexical scan: a
     * name on its own definition line is the definition, not a use of it, and
     * the {@see NOT_REFERENCES} keywords and one-character names are left out.
     * Capped at {@see MAX_REFERENCES_PER_FILE}.
     *
     * @param list<Tag> $definitions the file's definitions
     * @return list<Tag>
     */
    public static function references(string $source, string $relPath, array $definitions = []): array
    {
        $definedAt = [];
        foreach ($definitions as $tag) {
            $definedAt[$tag->line][$tag->name] = true;
        }
        $skip = \array_fill_keys(self::NOT_REFERENCES, true);

        $tags = [];
        foreach (\explode("\n", $source) as $index => $line) {
            if (\preg_match_all('/[A-Za-z_][A-Za-z0-9_]*/', $line, $matches) === false) {
                continue;
            }
            $lineNo = $index + 1;
            foreach ($matches[0] as $name) {
                if (\strlen($name) < 2 || isset($skip[\strtolower($name)]) || isset($definedAt[$lineNo][$name])) {
                    continue;
                }
                $tags[] = Tag::reference($relPath, $lineNo, $name);
                if (\count($tags) >= self::MAX_REFERENCES_PER_FILE) {
                    return $tags;
                }
            }
        }

        return $tags;
    }

    /**
     * The command line for one batch. Absolute paths only, so no argument
     * can read as an option.
     *
     * @param list<string> $absPaths
     */
    public function commandFor(array $absPaths): string
    {
        $argv = [
            \escapeshellarg($this->binary),
            '--options=NONE',
            '--output-format=json',
            '--fields=+nKsS',
            '--sort=no',
            '-f',
            '-',
        ];
        foreach ($absPaths as $path) {
            $argv[] = \escapeshellarg($path);
        }

        return \implode(' ', $argv);
    }

    /**
     * @param list<string> $absPaths
     * @return list<list<string>>
     */
    private function batches(array $absPaths): array
    {
        $batches = [];
        $current = [];
        $bytes = 0;
        foreach ($absPaths as $path) {
            $cost = \strlen($path) + 3;
            if ($current !== [] && (\count($current) >= self::MAX_FILES_PER_BATCH || $bytes + $cost > self::MAX_ARGV_BYTES)) {
                $batches[] = $current;
                $current = [];
                $bytes = 0;
            }
            $current[] = $path;
            $bytes += $cost;
        }
        if ($current !== []) {
            $batches[] = $current;
        }

        return $batches;
    }

    /** `Foo::Bar`, `a.b.C`, `ns/Inner` => the innermost segment. */
    private static function lastScopeSegment(string $scope): string
    {
        $parts = \preg_split('/::|\.|\//', $scope) ?: [$scope];

        return (string) \end($parts);
    }
}
