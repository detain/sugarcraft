<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\BuiltIn;

use SugarCraft\Crush\Agents\PathJail as AgentPathJail;
use SugarCraft\Crush\RepoMap\CtagsSymbolExtractor;
use SugarCraft\Crush\RepoMap\PhpSymbolExtractor;
use SugarCraft\Crush\RepoMap\RepoMapRenderer;
use SugarCraft\Crush\RepoMap\SymbolGraph;
use SugarCraft\Crush\RepoMap\Tag;
use SugarCraft\Crush\RepoMap\TagCache;
use SugarCraft\Crush\Tools\AcceptsHeartbeat;
use SugarCraft\Crush\Tools\AcceptsWorktreeJail;
use SugarCraft\Crush\Tools\Catalog\BuildsFromCatalog;
use SugarCraft\Crush\Tools\Catalog\BuiltInTool;
use SugarCraft\Crush\Tools\Catalog\ToolBuildContext;
use SugarCraft\Crush\Tools\Catalog\ToolPermissionClass;
use SugarCraft\Crush\Tools\Concerns\RebindsWorktreeJail;
use SugarCraft\Crush\Tools\PathJail;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolResult;
use SugarCraft\Crush\Workspace\GitRunner;

/**
 * `RepoMap`: the symbol-level repo map as a tool (roadmap 5.5-4) — the
 * definitions the rest of the project leans on most, ranked by PageRank over
 * who references whom and rendered as a compact outline sized to a token
 * budget.
 *
 * It mirrors Aider's repo map (`RepoMap.get_repo_map()`), shipped as a tool
 * the model calls rather than a prompt block, so it costs nothing until it is
 * asked for and its size is the caller's choice; a byte-stable prompt block is
 * the separate, later step 5.5-5.
 *
 * THE PIPELINE, every stage of which already existed (5.5-1…5.5-3):
 *
 *  1. FILES: `git ls-files --cached --others --exclude-standard` in the jail
 *     root, so `.gitignore` decides what is project code exactly as it does
 *     for the user. A symlink is skipped and every path is re-resolved
 *     through {@see PathJail} before anything reads it.
 *  2. TAGS: PHP through {@see PhpSymbolExtractor} (no binary needed), every
 *     other code file through {@see CtagsSymbolExtractor} when Universal Ctags
 *     is installed; both cached in the per-project {@see TagCache} under the
 *     home directory, keyed on path + mtime + size.
 *  3. RANK: {@see SymbolGraph} (Aider's weights), personalised by the files
 *     and identifiers the caller names.
 *  4. RENDER: {@see RepoMapRenderer::fit()} to `max_tokens`.
 *
 * A READ, classified {@see ToolPermissionClass::Read}: it reports source that
 * `Read` would show, writes nothing in the checkout, and its one write is the
 * tag cache under `~/.sugar-crush/cache/repomap/` (an in-memory cache when no
 * owned home exists).
 */
#[BuiltInTool(name: 'RepoMap', permission: ToolPermissionClass::Read, position: 13, gloss: 'a ranked outline of the definitions the rest of the project references most, sized to a token budget')]
final readonly class RepoMapTool implements Tool, AcceptsWorktreeJail, AcceptsHeartbeat, BuildsFromCatalog
{
    use RebindsWorktreeJail;

    public const NAME = 'RepoMap';

    public const DEFAULT_MAX_TOKENS = 1024;
    public const MIN_MAX_TOKENS = 256;
    public const MAX_MAX_TOKENS = 8192;

    /** Code files mapped per call; a larger checkout is mapped by its first N paths, and the result says so. */
    public const MAX_FILES = 10000;

    /** At most this many `focus_files` / `mentioned_idents` entries are honoured. */
    public const MAX_HINTS = 50;

    /** Extensions universal-ctags is asked about; anything else (docs, data, images) is not code. */
    private const CTAGS_EXTENSIONS = [
        'c', 'h', 'cc', 'cpp', 'cxx', 'hh', 'hpp', 'hxx', 'cs', 'go', 'java', 'kt', 'kts', 'scala',
        'rs', 'py', 'pyi', 'rb', 'js', 'jsx', 'mjs', 'cjs', 'ts', 'tsx', 'swift', 'm', 'mm', 'lua',
        'pl', 'pm', 'sh', 'bash', 'zsh', 'ex', 'exs', 'erl', 'hrl', 'hs', 'ml', 'mli', 'clj', 'dart',
        'r', 'jl', 'zig', 'nim', 'vim', 'groovy', 'tcl', 'f90', 'f95', 'ada', 'adb', 'ads', 'el',
    ];

    public function __construct(
        private ?string $root = null,
        private ?CtagsSymbolExtractor $ctags = null,
        /** Null means the default `~/.sugar-crush/cache/repomap/<project>.sqlite`. */
        private ?string $cachePath = null,
        // Last, so positional callers keep their meaning (the F-J5 rule).
        private ?AgentPathJail $worktreeJail = null,
    ) {}

    public static function fromCatalog(ToolBuildContext $context): self
    {
        return new self($context->root);
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Get a ranked outline of this project\'s code: the classes, functions and methods the rest '
            . 'of the codebase references most, ranked by PageRank over which files use which names '
            . '(the Aider repo-map technique), each shown as its source line under its file and '
            . 'enclosing class. Reach for it when orienting in an unfamiliar codebase or before a change '
            . 'whose ripple you need to see; it answers "what matters here and where is it", not "what '
            . 'does this line say" (use Read) or "where is this string" (use Grep). Name the files you are '
            . 'working in as focus_files and the identifiers you care about as mentioned_idents to bias '
            . 'the ranking toward their neighbourhood; focus files themselves are left out of the map. '
            . 'Only files git tracks or would track (respecting .gitignore) are mapped: PHP always, other '
            . 'languages only when Universal Ctags is installed, which the result says. The map is sized '
            . 'to max_tokens and the first call in a project can take a few seconds while symbols are '
            . 'cached.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'max_tokens' => [
                    'type' => 'integer',
                    'description' => sprintf(
                        'Token budget for the map (default %d, clamped to %d-%d).',
                        self::DEFAULT_MAX_TOKENS,
                        self::MIN_MAX_TOKENS,
                        self::MAX_MAX_TOKENS,
                    ),
                ],
                'focus_files' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Project-relative paths of the files you are working in: the map ranks what they reference and leaves them out.',
                ],
                'mentioned_files' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Project-relative paths the task names, ranked up but still shown.',
                ],
                'mentioned_idents' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Identifiers (class, function, method names) the task is about, ranked up.',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $args): ToolResult
    {
        return $this->map($args, null);
    }

    /**
     * {@see execute()} with the turn's liveness beat (item 0.4-b): the first
     * map of a large checkout tokenizes every file and can outlast the
     * turn's idle ceiling, so the beat is struck as the work proceeds.
     */
    public function executeWithHeartbeat(array $args, \Closure $heartbeat): ToolResult
    {
        return $this->map($args, $heartbeat);
    }

    /**
     * @param array<string, mixed> $args
     * @param (\Closure(): void)|null $heartbeat
     */
    private function map(array $args, ?\Closure $heartbeat): ToolResult
    {
        $beat = self::throttled($heartbeat);
        $id = \is_string($args['id'] ?? null) ? $args['id'] : '';
        $root = $this->jailRoot();
        $rootReal = $root === null ? false : \realpath($root);
        if ($rootReal === false || !\is_dir($rootReal)) {
            return new ToolResult($id, 'Error: RepoMap has no project directory to map.', true);
        }

        $listing = GitRunner::new($rootReal)
            ->withInheritedGitEnv()
            ->withTimeout(GitRunner::DEFAULT_TIMEOUT_SECONDS)
            ->run('ls-files', '-z', '--cached', '--others', '--exclude-standard');
        if (!$listing['ok']) {
            return new ToolResult(
                $id,
                'Error: RepoMap maps the files git lists, and `git ls-files` failed here: '
                    . \trim($listing['stderr'] !== '' ? $listing['stderr'] : 'exit ' . $listing['exitCode'])
                    . '. Use Glob and Grep to explore a directory that is not a git checkout.',
                true,
            );
        }

        $ctags = $this->ctags ?? CtagsSymbolExtractor::new();
        $ctagsAvailable = $ctags->available();
        [$phpFiles, $otherFiles, $capped] = $this->codeFiles($listing['stdout'], $rootReal, $ctagsAvailable);

        try {
            $cache = TagCache::open($this->cachePath ?? TagCache::defaultPath($rootReal) ?? ':memory:');
        } catch (\RuntimeException $e) {
            return new ToolResult($id, 'Error: RepoMap could not open its tag cache: ' . $e->getMessage(), true);
        }

        $tagsByFile = [];
        $php = PhpSymbolExtractor::new();
        foreach ($phpFiles as $rel => $abs) {
            $tagsByFile[$rel] = $cache->tagsFor($abs, $rel, $php);
            $beat();
        }
        foreach ($this->ctagsTags($otherFiles, $cache, $ctags) as $rel => $tags) {
            $tagsByFile[$rel] = $tags;
        }
        $beat();
        $cache->prune(\array_keys($phpFiles + $otherFiles));

        if ($tagsByFile === []) {
            return new ToolResult($id, 'RepoMap found no code files to map in ' . $rootReal . self::ctagsNote($ctagsAvailable));
        }

        $graph = SymbolGraph::new(
            $tagsByFile,
            self::stringList($args['focus_files'] ?? null),
            self::stringList($args['mentioned_files'] ?? null),
            self::stringList($args['mentioned_idents'] ?? null),
        );
        $maxTokens = self::budget($args['max_tokens'] ?? null);
        $entries = $graph->rankedEntries();
        $beat();
        // The renderer reads these only for scope headers, which are
        // definitions; handing it the references too made every outline line
        // scan every reference in its file (measured: half the warm run).
        $definitions = \array_map(
            static fn (array $tags): array => \array_values(\array_filter($tags, static fn (Tag $t): bool => $t->isDefinition())),
            $tagsByFile,
        );
        $map = RepoMapRenderer::new(
            static fn (string $rel): array => self::sourceLines($rootReal, $rel),
            $definitions,
        )->fit($entries, $maxTokens);

        $header = sprintf(
            'Repo map of %d code file%s (budget %d tokens)%s%s',
            \count($tagsByFile),
            \count($tagsByFile) === 1 ? '' : 's',
            $maxTokens,
            $capped ? sprintf('; only the first %d files git lists were mapped', self::MAX_FILES) : '',
            self::ctagsNote($ctagsAvailable),
        );

        return new ToolResult($id, $map === '' ? $header . "\nNothing fit the budget; raise max_tokens." : $header . "\n" . $map);
    }

    /**
     * The jail root: the injected sub-agent worktree when there is one, else
     * the workspace root — the {@see LspTool} precedence (audit F-J5).
     */
    private function jailRoot(): ?string
    {
        return $this->worktreeJail?->root() ?? $this->root;
    }

    /**
     * Split `git ls-files -z` into PHP files and ctags files, each
     * root-relative => absolute, skipping symlinks and anything PathJail does
     * not place inside the root.
     *
     * @return array{0: array<string, string>, 1: array<string, string>, 2: bool}
     */
    private function codeFiles(string $listing, string $rootReal, bool $withCtags): array
    {
        $paths = \array_values(\array_unique(\array_filter(\explode("\0", $listing), static fn (string $p): bool => $p !== '')));
        \sort($paths, \SORT_STRING);

        $php = [];
        $other = [];
        $capped = false;
        foreach ($paths as $rel) {
            $ext = \strtolower(\pathinfo($rel, \PATHINFO_EXTENSION));
            $isPhp = $ext === 'php';
            if (!$isPhp && !($withCtags && \in_array($ext, self::CTAGS_EXTENSIONS, true))) {
                continue;
            }
            if (\count($php) + \count($other) >= self::MAX_FILES) {
                $capped = true;

                break;
            }

            $candidate = $rootReal . '/' . $rel;
            if (\is_link($candidate)) {
                continue;
            }
            $abs = PathJail::resolve($rootReal, $rel);
            if ($abs === null || !\is_file($abs)) {
                continue;
            }

            if ($isPhp) {
                $php[$rel] = $abs;
            } else {
                $other[$rel] = $abs;
            }
        }

        return [$php, $other, $capped];
    }

    /**
     * Tags for the ctags files: cache hits as they are, misses extracted in
     * one batched run and stored. A file the extractor did not answer for (a
     * cut-off batch) is mapped from nothing this time and not cached.
     *
     * @param array<string, string> $files
     * @return array<string, list<Tag>>
     */
    private function ctagsTags(array $files, TagCache $cache, CtagsSymbolExtractor $ctags): array
    {
        $out = [];
        $misses = [];
        $stamps = [];
        foreach ($files as $rel => $abs) {
            \clearstatcache(true, $abs);
            $stat = @\stat($abs);
            if ($stat === false) {
                continue;
            }
            $stamps[$rel] = [(int) $stat['mtime'], (int) $stat['size']];
            $hit = $cache->get($rel, $stamps[$rel][0], $stamps[$rel][1]);
            if ($hit !== null) {
                $out[$rel] = $hit;
            } else {
                $misses[$rel] = $abs;
            }
        }

        foreach ($ctags->extractFiles($misses) as $rel => $tags) {
            $cache->put($rel, $stamps[$rel][0], $stamps[$rel][1], $tags);
            $out[$rel] = $tags;
        }

        return $out;
    }

    /**
     * The renderer's line source (the W2-j carry-over): CONTAINED — the path
     * is re-resolved through {@see PathJail} against the root, and a symlink
     * is refused — and SIZE-BOUNDED — a file over
     * {@see PhpSymbolExtractor::MAX_FILE_BYTES} supplies no lines, so its
     * outline falls back to `type name`. The renderer is only ever handed
     * paths from this call's own listing; the checks hold anyway, because a
     * closure is a door anyone holding it can walk through.
     *
     * @return list<string>
     */
    private static function sourceLines(string $rootReal, string $rel): array
    {
        if (\is_link($rootReal . '/' . $rel)) {
            return [];
        }
        $abs = PathJail::resolve($rootReal, $rel);
        if ($abs === null || !\is_file($abs)) {
            return [];
        }
        $size = @\filesize($abs);
        if ($size === false || $size > PhpSymbolExtractor::MAX_FILE_BYTES) {
            return [];
        }
        $source = @\file_get_contents($abs);

        return $source === false ? [] : \explode("\n", \str_replace("\r\n", "\n", $source));
    }

    /**
     * $heartbeat struck at most once a second, or a no-op without one.
     *
     * @param (\Closure(): void)|null $heartbeat
     * @return \Closure(): void
     */
    private static function throttled(?\Closure $heartbeat): \Closure
    {
        if ($heartbeat === null) {
            return static function (): void {
            };
        }
        $last = 0.0;

        return static function () use ($heartbeat, &$last): void {
            $now = \microtime(true);
            if ($now - $last >= 1.0) {
                $last = $now;
                $heartbeat();
            }
        };
    }

    private static function budget(mixed $requested): int
    {
        $tokens = \is_int($requested) || (\is_string($requested) && \ctype_digit($requested))
            ? (int) $requested
            : self::DEFAULT_MAX_TOKENS;

        return \max(self::MIN_MAX_TOKENS, \min(self::MAX_MAX_TOKENS, $tokens));
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (\is_string($item) && $item !== '') {
                $out[] = \str_starts_with($item, './') ? \substr($item, 2) : $item;
            }
            if (\count($out) >= self::MAX_HINTS) {
                break;
            }
        }

        return $out;
    }

    private static function ctagsNote(bool $available): string
    {
        return $available
            ? '; non-PHP files via Universal Ctags.'
            : '; PHP only (install Universal Ctags with +json to map other languages).';
    }
}
