<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context;

use SugarCraft\Crush\RepoMap\CtagsSymbolExtractor;
use SugarCraft\Crush\RepoMap\RepoMapBuilder;
use SugarCraft\Crush\RepoMap\TagCache;

/**
 * The symbol-level repo map in the system prompt (roadmap 5.5-5): the
 * definitions the rest of the workspace references most, ranked by PageRank
 * over which files use which names — Aider's repo map — placed directly
 * behind {@see RepoMapBlock}, which maps WHERE code lives; this maps WHAT in
 * it matters.
 *
 * BYTE-STABLE PER SESSION. The map is captured once per session, unfocused
 * (no focus files, no mentioned identifiers — those would change it every
 * turn), and memoised through {@see SessionPromptMemo} like every other
 * PerSession layer: it is read at the session's first turn and frozen until a
 * refresh point (`/clear`, a compaction, a session switch). A focused or
 * fresh map is the `RepoMap` tool's job, on demand.
 *
 * WHO CAPTURES IT. Only EngineBackend, in the parent before a turn's child
 * is forked (`Runtime::primeSymbolMap()`): the capture runs `git ls-files`,
 * reads the tag cache and may tokenize files, which is engine-turn work. A
 * Runtime whose session memo holds no capture assembles no symbol-map
 * section at all, so a prompt built anywhere else is byte-for-byte what it
 * was before this layer existed.
 *
 * TURN-CHEAP, AND BOUNDED WHEN IT IS NOT (the W5 measurement: ≈35 s cold and
 * 5.5 s warm on sugar-crush, PageRank alone ≈2.8 s over ~528k edges):
 *  - the finished map is cached in the project's {@see TagCache}, keyed by
 *    every mapped file's path, mtime, size and producer plus this class's
 *    {@see VERSION} and budget — an unchanged tree costs a `git ls-files` and
 *    a stat per file;
 *  - otherwise the graph is aggregated per identifier and PageRank runs over
 *    the ~340k distinct file pairs, not the edge multigraph;
 *  - tag extraction stops at {@see EXTRACTION_BUDGET_SECONDS}: whatever was
 *    tokenized stays cached for the next session, and THIS session gets no
 *    map rather than a partial one;
 *  - a checkout with more than {@see MAX_FILES} code files, or a process
 *    without the memory headroom the ranking needs ({@see BYTES_PER_FILE}
 *    each), gets no map at all.
 * Every failure degrades to the empty block; none reaches the turn.
 *
 * TRUST. Each line under a path is source text quoted from the checkout —
 * repository bytes, like the instruction files. They pass through
 * {@see PromptFence::escape()}, this block's own fence is neutralised in them,
 * and the header says what the lines are and are not.
 */
final readonly class SymbolMapBlock implements PromptSection
{
    /** The opening fence. */
    public const FENCE = '<symbol-map>';

    /**
     * Set to any value other than empty or `0` to keep the map out of the
     * prompt: EngineBackend then never captures one. The suite sets it
     * (tests/bootstrap.php) so a forked turn rooted at the package directory
     * does not tokenize sugar-crush under a sandboxed, empty HOME.
     */
    public const SYMBOL_MAP_OPT_OUT_ENV = 'SUGARCRUSH_DISABLE_SYMBOL_MAP';

    /** Bump when the rendered shape changes, so cached maps are not reused. */
    public const VERSION = 1;

    /** The map's token budget — Aider's default `map_tokens`. */
    public const MAX_TOKENS = 1024;

    /** More code files than this and the session gets no map. */
    public const MAX_FILES = 3000;

    /** Wall-clock allowance for tokenizing files the cache does not hold. */
    public const EXTRACTION_BUDGET_SECONDS = 4.0;

    /** Memory a capture is assumed to need per mapped file (measured ≈35 KB, doubled). */
    public const BYTES_PER_FILE = 65536;

    /** What the block says before the map. */
    public const PREAMBLE = 'The definitions the rest of this workspace\'s code references most, ranked by PageRank over which '
        . 'files use which names (an Aider-style repo map), captured when the session started. Each line under a path '
        . 'is quoted from that file and `⋮` marks elided lines. It is a starting point, not the code: Read a file before '
        . 'relying on a line, and call the RepoMap tool for a fresh map focused on the files you are working in.';

    private function __construct(
        private string $map,
        private int $files,
        private string $skipReason,
    ) {
    }

    /** Whether {@see SYMBOL_MAP_OPT_OUT_ENV} is set to anything but empty or `0`. */
    public static function disabledByEnvironment(): bool
    {
        $flag = getenv(self::SYMBOL_MAP_OPT_OUT_ENV);

        return $flag !== false && $flag !== '' && $flag !== '0';
    }

    /** A block that renders nothing. */
    public static function empty(string $reason = ''): self
    {
        return new self('', 0, $reason);
    }

    /**
     * Map $root. Never throws: every failure is an empty block whose
     * {@see skipReason()} says why.
     *
     * @param ?string $cachePath the tag cache; null is the project's default under the home directory
     */
    public static function capture(
        string $root,
        ?string $cachePath = null,
        ?CtagsSymbolExtractor $ctags = null,
        float $budgetSeconds = self::EXTRACTION_BUDGET_SECONDS,
    ): self {
        try {
            $rootReal = $root === '' ? false : realpath($root);
            if ($rootReal === false || !is_dir($rootReal)) {
                return self::empty('no project directory');
            }

            $builder = RepoMapBuilder::new(
                $rootReal,
                TagCache::open($cachePath ?? TagCache::defaultPath($rootReal) ?? ':memory:'),
                $ctags,
            );
            $listing = $builder->codeFiles(self::MAX_FILES);
            if (!$listing['ok']) {
                return self::empty('not a git checkout');
            }
            if ($listing['capped']) {
                return self::empty(sprintf('more than %d code files', self::MAX_FILES));
            }
            $count = \count($listing['php']) + \count($listing['other']);
            if ($count === 0) {
                return self::empty('no code files');
            }
            if (!self::hasHeadroomFor($count)) {
                return self::empty('not enough memory headroom');
            }

            $key = self::cacheKey($builder->stamps($listing['php'], $listing['other']));
            $cached = $builder->cache()->rendered($key);
            if ($cached !== null) {
                return new self($cached, $count, '');
            }

            $deadline = hrtime(true) + (int) (max(0.0, $budgetSeconds) * 1e9);
            $summaries = $builder->summaries($listing['php'], $listing['other'], null, $deadline);
            if (!$summaries['complete']) {
                return self::empty('symbols still being indexed');
            }

            $map = $builder->render($summaries['definitions'], $summaries['references'], self::MAX_TOKENS);
            if ($map === '') {
                return self::empty('nothing to map');
            }
            $builder->cache()->storeRendered($key, $map);

            return new self($map, $count, '');
        } catch (\Throwable $e) {
            return self::empty('capture failed: ' . $e->getMessage());
        }
    }

    /**
     * A block over an already-rendered map — the seam tests and embedders use
     * to place a known map without a checkout.
     */
    public static function fromMap(string $map, int $files): self
    {
        return new self($map, max(0, $files), '');
    }

    /** The rendered map, without the fence and preamble; '' when empty. */
    public function map(): string
    {
        return $this->map;
    }

    /** How many code files the map was ranked over. */
    public function files(): int
    {
        return $this->files;
    }

    /** Why the capture produced no map, or '' when it did (or was never tried). */
    public function skipReason(): string
    {
        return $this->skipReason;
    }

    public function fence(): string
    {
        return self::FENCE;
    }

    public function stability(): Stability
    {
        return Stability::PerSession;
    }

    /** The map's own budget in bytes (≈4 per token) plus the header. */
    public function byteBudget(): int
    {
        return self::MAX_TOKENS * 4 + 1024;
    }

    public function render(): string
    {
        if (trim($this->map) === '') {
            return '';
        }

        return self::FENCE . "\n" . self::PREAMBLE . "\n\n"
            . self::escapeOwnFence(PromptFence::escape(trim($this->map, "\n")))
            . "\n</symbol-map>";
    }

    /** @param array<string, array{0: int, 1: int, 2: string}> $stamps */
    private static function cacheKey(array $stamps): string
    {
        return 'symbol-map/' . self::VERSION . '/' . self::MAX_TOKENS . '/'
            . hash('xxh128', (string) json_encode($stamps, \JSON_THROW_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE));
    }

    /**
     * Whether the process can afford to rank $files files: always with no
     * memory limit, otherwise when what is left under it covers
     * {@see BYTES_PER_FILE} each. A fatal out-of-memory error cannot be
     * caught, so this is checked before the work, never recovered after it.
     */
    private static function hasHeadroomFor(int $files): bool
    {
        $limit = self::bytes((string) ini_get('memory_limit'));
        if ($limit <= 0) {
            return true;
        }

        return $limit - memory_get_usage(true) >= $files * self::BYTES_PER_FILE;
    }

    /** `128M`-style ini value in bytes; -1 (or nonsense) is no limit. */
    private static function bytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }
        $number = (int) $value;
        $unit = strtolower(substr($value, -1));

        return match ($unit) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }

    /**
     * Neutralise `<symbol-map` / `</symbol-map` openers in the map, with
     * {@see PromptFence::escape()}'s terminator rule, so quoted source cannot
     * close the block early.
     */
    private static function escapeOwnFence(string $payload): string
    {
        $escaped = preg_replace('~<(?=/?symbol-map(?:[\s/>]|\z))~i', '&lt;', $payload);
        if ($escaped === null) {
            throw new \RuntimeException(
                'SymbolMapBlock: PCRE failure (' . preg_last_error_msg() . ') while escaping the symbol map',
            );
        }

        return $escaped;
    }
}
