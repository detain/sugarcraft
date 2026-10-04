<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\BuiltIn;

use SugarCraft\Crush\Agents\PathJail as AgentPathJail;
use SugarCraft\Crush\RepoMap\CtagsSymbolExtractor;
use SugarCraft\Crush\RepoMap\RepoMapBuilder;
use SugarCraft\Crush\RepoMap\TagCache;
use SugarCraft\Crush\Tools\AcceptsHeartbeat;
use SugarCraft\Crush\Tools\AcceptsWorktreeJail;
use SugarCraft\Crush\Tools\Catalog\BuildsFromCatalog;
use SugarCraft\Crush\Tools\Catalog\BuiltInTool;
use SugarCraft\Crush\Tools\Catalog\ToolBuildContext;
use SugarCraft\Crush\Tools\Catalog\ToolPermissionClass;
use SugarCraft\Crush\Tools\Concerns\RebindsWorktreeJail;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * `RepoMap`: the symbol-level repo map as a tool (roadmap 5.5-4) — the
 * definitions the rest of the project leans on most, ranked by PageRank over
 * who references whom and rendered as a compact outline sized to a token
 * budget.
 *
 * It mirrors Aider's repo map (`RepoMap.get_repo_map()`), shipped as a tool
 * the model calls, so it costs nothing until it is asked for and its size and
 * focus are the caller's choice. The system prompt carries an unfocused,
 * byte-stable map of the same kind once per session
 * ({@see \SugarCraft\Crush\Context\SymbolMapBlock}, roadmap 5.5-5); this
 * tool is how the model draws a fresh or focused one.
 *
 * THE PIPELINE lives in {@see RepoMapBuilder}, shared with that block:
 *
 *  1. FILES: `git ls-files --cached --others --exclude-standard` in the jail
 *     root, so `.gitignore` decides what is project code exactly as it does
 *     for the user. A symlink is skipped and every path is re-resolved
 *     through {@see \SugarCraft\Crush\Tools\PathJail} before anything reads it.
 *  2. TAGS: PHP through {@see \SugarCraft\Crush\RepoMap\PhpSymbolExtractor}
 *     (no binary needed), every other code file through
 *     {@see CtagsSymbolExtractor} when Universal Ctags is installed; both
 *     cached in the per-project {@see TagCache} under the home directory,
 *     keyed on path + mtime + size + the producer that tagged the file.
 *  3. RANK: {@see \SugarCraft\Crush\RepoMap\SymbolGraph} (Aider's weights),
 *     personalised by the files and identifiers the caller names.
 *  4. RENDER: {@see \SugarCraft\Crush\RepoMap\RepoMapRenderer::fit()} to `max_tokens`.
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

        try {
            $cache = TagCache::open($this->cachePath ?? TagCache::defaultPath($rootReal) ?? ':memory:');
        } catch (\RuntimeException $e) {
            return new ToolResult($id, 'Error: RepoMap could not open its tag cache: ' . $e->getMessage(), true);
        }

        // The pipeline is shared with the system prompt's SymbolMapBlock
        // (roadmap 5.5-5), so the tool and the prompt map the same files the
        // same way.
        $builder = RepoMapBuilder::new($rootReal, $cache, $this->ctags);
        $listing = $builder->codeFiles(self::MAX_FILES);
        if (!$listing['ok']) {
            return new ToolResult(
                $id,
                'Error: RepoMap maps the files git lists, and `git ls-files` failed here: '
                    . $listing['error']
                    . '. Use Glob and Grep to explore a directory that is not a git checkout.',
                true,
            );
        }
        $ctagsAvailable = $builder->ctagsAvailable();

        $summaries = $builder->summaries($listing['php'], $listing['other'], $beat);
        $mapped = \count($summaries['definitions']);
        if ($mapped === 0) {
            return new ToolResult($id, 'RepoMap found no code files to map in ' . $rootReal . self::ctagsNote($ctagsAvailable));
        }

        $maxTokens = self::budget($args['max_tokens'] ?? null);
        $map = $builder->render(
            $summaries['definitions'],
            $summaries['references'],
            $maxTokens,
            self::stringList($args['focus_files'] ?? null),
            self::stringList($args['mentioned_files'] ?? null),
            self::stringList($args['mentioned_idents'] ?? null),
        );
        $beat();

        $header = sprintf(
            'Repo map of %d code file%s (budget %d tokens)%s%s',
            $mapped,
            $mapped === 1 ? '' : 's',
            $maxTokens,
            $listing['capped'] ? sprintf('; only the first %d files git lists were mapped', self::MAX_FILES) : '',
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
