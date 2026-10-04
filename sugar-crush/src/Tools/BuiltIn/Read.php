<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\BuiltIn;

use SugarCraft\Crush\Agents\PathJail as AgentPathJail;
use SugarCraft\Crush\Context\InstructionFileLoader;
use SugarCraft\Crush\Context\RulePathNudge;
use SugarCraft\Crush\LSP\LspClient;
use SugarCraft\Crush\Skills\SkillPathNudge;
use SugarCraft\Crush\Tools\CarriesSessionState;
use SugarCraft\Crush\Tools\Concerns\TruncatesOutput;
use SugarCraft\Crush\Tools\ParallelSafe;
use SugarCraft\Crush\Tools\PromptGuidance;
use SugarCraft\Crush\Tools\AcceptsWorktreeJail;
use SugarCraft\Crush\Tools\Concerns\RebindsWorktreeJail;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolResult;
use SugarCraft\Crush\Tools\PathJail;
use SugarCraft\Crush\Tools\Catalog\BuildsFromCatalog;
use SugarCraft\Crush\Tools\Catalog\BuiltInTool;
use SugarCraft\Crush\Tools\Catalog\ToolBuildContext;
use SugarCraft\Crush\Tools\Catalog\ToolPermissionClass;

#[BuiltInTool(name: 'Read', permission: ToolPermissionClass::Read, position: 2)]
final readonly class Read implements Tool, AcceptsWorktreeJail, ParallelSafe, CarriesSessionState, PromptGuidance, BuildsFromCatalog
{
    use RebindsWorktreeJail;
    use TruncatesOutput;

    /**
     * The hard ceiling on one call's page, and the base the instruction and
     * nudge reserves below are figured from. A page never exceeds it, but the
     * default page ({@see PAGE_BYTES}) is far smaller, so at the shipped
     * default this is the bound a caller-supplied `maxBytes` lowers rather
     * than the size a read comes back at.
     */
    private const DEFAULT_MAX_BYTES = 1024 * 1024;

    /**
     * Lines one call returns when the caller names no `limit` (audit 0.12).
     * Paging by line rather than by byte is what lets a model ask for "the next
     * window" and know exactly where it starts.
     */
    private const PAGE_LINES = 2000;

    /**
     * Bytes one page may hold, line prefixes included, whatever `limit` asks
     * for: 50 KiB, so a call costs a bounded slice of context instead of the
     * whole 1 MiB ceiling. The effective page is the smaller of this and
     * $maxBytes.
     */
    private const PAGE_BYTES = 50 * 1024;

    /**
     * The largest single fread(). A file is scanned in blocks this size (or
     * $maxBytes, when that is smaller), so neither counting the lines of a huge
     * file nor skipping to a late offset ever holds more than one block.
     */
    private const SCAN_CHUNK = 64 * 1024;

    /**
     * The most rows an outline lists, and the bytes it may take — whichever
     * binds first (step 3.F). The outline rides on a first page that already
     * holds up to {@see PAGE_BYTES}, so it is a map of where to read next,
     * never a second copy of the file.
     */
    private const OUTLINE_MAX_ROWS = 200;
    private const OUTLINE_MAX_BYTES = 8 * 1024;

    /**
     * $skillNudge turns a skill's `paths:` frontmatter into a live signal
     * (crush_feat.md section 7 E4): reading a file a skill scopes itself to
     * announces that skill once, the way Claude Code loads skills on first
     * read inside a scoped subdirectory. Null keeps the tool standalone.
     */
    public function __construct(
        private ?string $root = null,
        private int $maxBytes = self::DEFAULT_MAX_BYTES,
        private ?AgentPathJail $worktreeJail = null,
        private ?InstructionFileLoader $instructionLoader = null,
        private array $sessionCache = [],
        private ?SkillPathNudge $skillNudge = null,
        private ?RulePathNudge $ruleNudge = null,
        // Step 3.F: the launch's language servers, for the outline of a file
        // too long for one page. Null outlines from the source text alone.
        private ?LspClient $lsp = null,
    ) {}

    /**
     * Opening a file mutates nothing a sibling call could observe, so a batch
     * of reads is the canonical case for
     * {@see \SugarCraft\Crush\Runtime}'s concurrent dispatch.
     *
     * The one thing `execute()` DOES mutate — the announce-once marks of the
     * shared {@see InstructionFileLoader}/{@see SkillPathNudge} — would die
     * with the forked child, which is why this tool also implements
     * {@see CarriesSessionState} and hands those marks back.
     */
    public function isParallelSafe(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     *
     * Kept byte-identical to {@see Glob::exportSessionState()}: both tools are
     * wired to the SAME two collaborators (see
     * {@see \SugarCraft\Crush\Cli\Bootstrap::tools()}), so a key one of them
     * exported and the other did not would leave that half re-announcing
     * forever.
     */
    public function exportSessionState(): array
    {
        return [
            'emittedInstructionPaths' => $this->instructionLoader?->emittedPaths() ?? [],
            'announcedInstructionRefusals' => $this->instructionLoader?->announcedRefusals() ?? [],
            'announcedSkills' => $this->skillNudge?->announced() ?? [],
            'announcedRules' => $this->ruleNudge?->announcedPaths() ?? [],
        ];
    }

    /**
     * @param array<string, mixed> $state
     */
    public function mergeSessionState(array $state): void
    {
        $paths = $state['emittedInstructionPaths'] ?? null;
        if (is_array($paths)) {
            $this->instructionLoader?->markEmitted(array_values($paths));
        }

        $refusals = $state['announcedInstructionRefusals'] ?? null;
        if (is_array($refusals)) {
            $this->instructionLoader?->markRefusalsAnnounced(array_values($refusals));
        }

        $skills = $state['announcedSkills'] ?? null;
        if (is_array($skills)) {
            $this->skillNudge?->markAnnounced(array_values($skills));
        }

        $rules = $state['announcedRules'] ?? null;
        if (is_array($rules)) {
            $this->ruleNudge?->markAnnouncedPaths(array_values($rules));
        }
    }

    public static function fromCatalog(ToolBuildContext $context): self
    {
        return new self($context->root, instructionLoader: $context->loader, skillNudge: $context->skillNudge, ruleNudge: $context->ruleNudge, lsp: $context->lsp);
    }

    public function name(): string
    {
        return 'Read';
    }
    /**
     * Paging is the behaviour worth stating (audit 0.12): every line comes back
     * numbered, a file longer than one page comes back as a window that SAYS so
     * and names the call that continues it, and a model told only "read a file"
     * has no reason to suspect the content it got was the head of a larger one.
     *
     * The byte figure is read off the instance ({@see pageBytes()}) rather
     * than written out — a caller that passed its own cap would otherwise
     * advertise the default's number instead of its own. The prefer-this-over-
     * `cat` clauses are conditional for the same reason: containment and
     * instruction-file surfacing come from two DIFFERENT injected
     * collaborators, and an instance holding neither must not claim either.
     */
    public function description(): string
    {
        // Each advantage is claimed only by the instance that actually has
        // it: containment comes from a root or a worktree jail, and the
        // instruction-file surfacing comes from the loader. A standalone
        // instance has neither, and must not advertise them.
        $advantages = [];
        if ($this->worktreeJail !== null || $this->root !== null) {
            $advantages[] = 'it is confined to the workspace root';
        }
        if ($this->instructionLoader !== null) {
            $advantages[] = 'any CLAUDE.md/AGENTS.md governing the file\'s directory is '
                . 'surfaced with the content the first time that directory is touched';
        }
        $advantages[] = 'a read failure comes back as a tool error rather than as a crash';

        return 'Read a file from the local filesystem, one page at a time. Every line comes back '
            . 'as `N: text`; the `N: ` prefix is the 1-based line number and is NOT part of the '
            . 'file, so leave it out of an Edit\'s old_string. A page is at most '
            . number_format(self::PAGE_LINES) . ' lines and ' . number_format($this->pageBytes())
            . ' bytes; pass `offset` (the first line to return) and `limit` (how many lines) to '
            . 'read another window. When more of the file remains, the result ends with '
            . '"[lines a-b of N — call Read with offset=b+1 to continue]", so a result without '
            . 'that footer reached the end of the file. A file longer than one page, read without '
            . '`offset` or `limit`, also gets an outline of its declarations and their line numbers '
            . 'after the first page, so you can read the part you need by offset. A single line longer '
            . 'than a page comes back cut short and marked. Prefer this over `cat`/`head` through Bash: '
            . implode('; ', $advantages) . '.';
    }

    /**
     * The facts a model needs at session scale rather than per call: lines
     * are numbered and the numbers are not file content, a long file arrives a
     * page at a time with a footer naming the next call, a rejected path is an
     * error it can correct, and directory-level project rules arrive with the
     * first read there. Kept free of sibling-tool names so the fragment stays
     * true when this tool is wired alone ({@see PromptGuidance}).
     */
    public function promptGuidance(): string
    {
        return 'The Read tool returns file contents one page at a time, every line prefixed with '
            . 'its 1-based line number as `N: `. That prefix is not part of the file: never copy it '
            . 'into text you write back. A file longer than one page ends with a footer naming the '
            . 'offset to continue from, so a result without that footer is the rest of the file; '
            . 'read a specific region with `offset` and `limit` instead of the whole file. A path '
            . 'that resolves outside the allowed workspace root, or that cannot be opened, comes '
            . 'back as a readable tool error rather than a crash. When a directory carries project '
            . 'instruction files, the first read inside it surfaces those rules alongside the '
            . 'content.';
    }

    public function inputSchema(): array
    {
        return [
        'type' => 'object',
        'properties' => [
            'file_path' => ['type' => 'string', 'description' => 'Path to file to read'],
            'offset' => [
                'type' => 'integer',
                'minimum' => 1,
                'description' => 'The 1-based line number to start reading from. Omit to start at line 1; '
                    . 'to continue a paged read, pass the offset its footer names.',
            ],
            'limit' => [
                'type' => 'integer',
                'minimum' => 1,
                'description' => 'The most lines to return (default ' . number_format(self::PAGE_LINES)
                    . '). A page is also bounded in bytes, so it may stop earlier.',
            ],
            'description' => [
                'type' => 'string',
                'description' => 'Clear, concise 5-10 word description in active voice of why this file is being read (e.g. "Inspect the chat model constructor", not "reads a file").',
            ],
        ],
        'required' => ['file_path', 'description'],
        ];
    }

    public function execute(array $args): ToolResult
    {
        $path = $args['file_path'] ?? '';

        // The type check is part of the same guard, not a separate nicety: this
        // sits ABOVE the try/catch below, and under strict_types str_contains()
        // raises a TypeError on a non-string. Without it, `file_path: 123` --
        // which the untyped tool-call JSON can carry -- turned the very crash
        // the NUL guard exists to remove back into an uncaught throw out of
        // execute(), where it had previously been caught and reported as a tool
        // error. ToolRegistry::execute() does not wrap its call site, so that
        // is a crash the model never sees as a result.
        if (!is_string($path)) {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: 'Error: file_path must be a string',
                isError: true,
            );
        }

        // A NUL byte makes realpath()/filesize() throw a ValueError rather than
        // fail, which escaped execute() as an uncaught crash instead of a tool
        // error the model can read and correct. It is not a containment bypass
        // -- both jail branches below reject the path anyway -- but the throw
        // happened before they got the chance on the no-jail path.
        if (str_contains($path, "\0")) {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: 'Error: file_path contains a NUL byte',
                isError: true,
            );
        }

        $offset = self::lineArgument($args, 'offset');
        $limit = self::lineArgument($args, 'limit');
        if (is_string($offset) || is_string($limit)) {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: is_string($offset) ? $offset : (string) $limit,
                isError: true,
            );
        }

        if ($this->worktreeJail !== null) {
            // resolve() proves containment AND returns the canonical path, so
            // the bytes read below are the bytes that were checked. The old
            // jailPath()+isAllowed() pairing proved containment on the
            // realpath() of $path and then re-opened the UNRESOLVED $path --
            // a symlink component swapped between the two calls resolved
            // somewhere else on the second pass (crush_code.md P8.14/15).
            //
            // The file_exists() half is isAllowed()'s existence-strictness,
            // kept verbatim: resolve() also accepts a not-yet-existing file
            // whose parent is in the jail, which is not something Read may
            // open, and rejecting it here preserves this call site's error.
            $resolved = $this->worktreeJail->resolve($path);
            if ($resolved === null || !file_exists($resolved)) {
                return new ToolResult(
                    toolCallId: $args['id'] ?? '',
                    content: 'Error: path outside worktree',
                    isError: true,
                );
            }
            $path = $resolved;
        } elseif ($this->root !== null) {
            $resolved = PathJail::resolve($this->root, $path);
            if ($resolved === null) {
                return new ToolResult(
                    toolCallId: $args['id'] ?? '',
                    content: 'Error: path outside workspace root',
                    isError: true,
                );
            }
            $path = $resolved;
        }

        // A FIFO (or socket, or device) is not a source file, and opening one
        // can block forever: filesize() reports 0 for a FIFO, so the read fell
        // into file_get_contents(), whose open(2) waits for a writer that never
        // comes -- a lone Read wedged until the turn-level SIGKILL (audit
        // F-T4). is_file() is a stat(), never an open, and it follows symlinks,
        // so a link to a regular file still reads. A missing path and a
        // directory are excluded on purpose: both already fail fast through
        // the error handler below, with the messages callers know.
        if (file_exists($path) && !is_file($path) && !is_dir($path)) {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: "Error: not a regular file: $path",
                isError: true,
            );
        }

        set_error_handler(static function (int $errno, string $errstr) use ($path): bool {
            throw new \RuntimeException("Error reading file {$path}: {$errstr}");
        });
        try {
            clearstatcache(true, $path);
            $content = $this->readPage($path, $offset ?? 1, $limit ?? self::PAGE_LINES);
            restore_error_handler();

            // A FILE TOO LONG FOR ONE PAGE, read with no window named, gets an
            // outline of its declarations after the first page (step 3.F,
            // Zed's `read_file` outline): the model sees where every class and
            // function starts and can ask for that window by `offset`, instead
            // of paging through the whole file to find it. Only then — a read
            // that already has the whole file, or that named its window, has
            // no use for a map.
            if ($offset === null && $limit === null && preg_match('/\[lines? 1(?:-\d+)? of \d+ — call Read with offset=\d+ to continue\]$/', $content) === 1) {
                $outline = $this->outline($path);
                if ($outline !== '') {
                    $content .= "\n\n" . $outline;
                }
            }

            // Prepended, unlike Grep and Glob, and that difference is the
            // point rather than drift: there is exactly ONE file here and
            // exactly one rule set governing it, so position alone says what
            // the markdown is. In a record stream of `path:line:text` or a
            // list of paths it would not, which is why those two label it and
            // put it last.
            //
            // BOUNDED, which it was not. $maxBytes is a per-file READ bound —
            // "how much of the file the model asked for does it get" — and the
            // instruction body was outside it entirely. MEASURED at 4a4ecb98
            // against `ToolOutputBudgetTest`'s fixture: a 9,611-byte
            // `sub/CLAUDE.md` in front of a 39-byte file, read at a 200-byte
            // cap, returned 9,651 bytes — 48.3x, of which 39 were the file.
            // The body now gets its own quarter of $maxBytes with its own
            // marker; the same call now returns 141 bytes.
            //
            // Since audit 0.12 the file's share is a PAGE (at most $maxBytes,
            // and 50 KiB by default) rather than $maxBytes itself, while both
            // reserves are still figured from $maxBytes. Every multiple stated
            // here is therefore a ceiling against $maxBytes: the page can only
            // make the total smaller.
            //
            // The FILE's share is deliberately NOT reduced to pay for it. A
            // read that returns less of the file because a sibling CLAUDE.md
            // exists is a silent content loss against the thing the caller
            // actually asked for, and the whole failure being fixed here is a
            // budget spent on the wrong content. So the total is bounded at
            // $maxBytes plus the instruction reserve rather than at $maxBytes
            // — a stated 1.25x, replacing an unbounded multiple. The skill
            // nudge below takes a further eighth on the same terms, so the
            // whole result is bounded at 1.375x $maxBytes.
            //
            // max(1, ...) and not the raw quarter: 0 is clipInstructions()'s
            // "no cap" sentinel, so a $maxBytes small enough for intdiv() to
            // round the quarter to zero disabled the bound entirely. MEASURED
            // before this guard, on the same fixture: $maxBytes of 1, 2 and 3
            // returned 9,629, 9,630 and 9,631 bytes — the whole rule book —
            // where 4 returned 122. Glob had the same knife-edge one layer up.
            $nestedContent = $this->instructionLoader?->loadForPath($path);
            if ($nestedContent !== null) {
                $reserve = $this->instructionBudget($this->maxBytes);
                $content = $this->clipInstructions(
                    $nestedContent,
                    $this->maxBytes > 0 ? max(1, $reserve) : $reserve,
                ) . "\n" . $content;
            }

            // Appended, not prepended: the file the model asked for stays the
            // head of the result and the reminder trails it.
            //
            // BOUNDED, which it was not — E66. It emitted one line per
            // matching auto-invocable skill carrying that skill's whole
            // frontmatter `description`, with no clip anywhere. MEASURED at
            // 8add627b through this tool at $maxBytes 200: five path-scoped
            // skills with 5,000-byte descriptions returned 25,216 bytes
            // (126.1x) and twenty with 20,000-byte descriptions returned
            // 400,406 (2,002.0x).
            //
            // An EIGHTH of $maxBytes, where the instruction body takes a
            // quarter, so the stated total is $maxBytes + 3/8 — 1.375x — and
            // is a multiple rather than the unbounded one it replaces. It is
            // the same share {@see Glob} and {@see Grep} give it, for the same
            // reason.
            //
            // "An eighth" was prose and nothing asserted it: MEASURED at
            // ae30fee5, changing the 8 here to a 2 survived the whole suite
            // (E71). It is now pinned as a two-sided threshold by
            // SkillPathScopingTest::testReadSpendsExactlyAnEighthOfMaxBytesOnTheSkillNudge(),
            // which asks the tracker what one entry costs rather than writing
            // a byte figure down.
            //
            // The FILE's share is again deliberately NOT reduced to pay for
            // it, on the argument set out above for the instruction body: a
            // read that returns less of the file because a skill claims its
            // directory is a silent content loss against the thing the caller
            // asked for.
            //
            // AN EIGHTH, via {@see SkillPathNudge::CALLER_BUDGET_DIVISOR} —
            // the same dial Grep and Glob turn, held in one place since E87
            // rather than written out three times.
            //
            // A budget too small for one entry surfaces nothing and SPENDS
            // nothing, so the skill is announced by the next call with room
            // rather than retired unseen. $maxBytes <= 0 is unreachable here —
            // fread() is called with it above and throws first — so the null
            // branch is stated for the contract, not for a caller.
            $nudge = $this->skillNudge?->forPath(
                $path,
                $this->maxBytes > 0
                    ? intdiv($this->maxBytes, SkillPathNudge::CALLER_BUDGET_DIVISOR)
                    : null,
            );
            if ($nudge !== null) {
                $content .= "\n\n" . $nudge;
            }

            // P6.S5b: the second transient channel, for `paths:`-scoped RULES,
            // appended on the same terms as the skill nudge directly above —
            // AN EIGHTH of $maxBytes via {@see RulePathNudge::CALLER_BUDGET_DIVISOR},
            // spent inside the caller's cap rather than beside it, and spent only
            // after the read actually landed. The 1.375x total the paragraph above
            // states is the file + instruction + SKILL bound, and it stays exactly
            // that for every launch with no path-scoped rule; this channel adds a
            // further eighth of the same shape, so a launch that carries scoped
            // rules is bounded at 1.5x $maxBytes.
            // A budget too small for one entry surfaces nothing and SPENDS nothing,
            // so a rule is announced by the next call with room rather than retired
            // unseen.
            $ruleNudge = $this->ruleNudge?->forPath(
                $path,
                $this->maxBytes > 0
                    ? intdiv($this->maxBytes, RulePathNudge::CALLER_BUDGET_DIVISOR)
                    : null,
            );
            if ($ruleNudge !== null) {
                $content .= "\n\n" . $ruleNudge;
            }

            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: $content,
                isError: false,
            );
        } catch (\Throwable $e) {
            restore_error_handler();
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: $e->getMessage(),
                isError: true,
            );
        }
    }

    /**
     * The declarations in $path, one per line as `N: kind name` and indented by
     * nesting, under a header saying what the list is — or '' when none is
     * found. From the file's language server when one is configured
     * ({@see LspClient::outline()}), else from the source text
     * ({@see LspClient::regexOutline()}). Bounded by {@see OUTLINE_MAX_ROWS}
     * and {@see OUTLINE_MAX_BYTES}; a cut outline says how many rows it left out.
     */
    private function outline(string $path): string
    {
        try {
            $rows = $this->lsp !== null ? $this->lsp->outline($path) : LspClient::regexOutline($path);
        } catch (\Throwable) {
            return '';
        }
        if ($rows === []) {
            return '';
        }

        $lines = [];
        $bytes = 0;
        foreach ($rows as $row) {
            $line = str_repeat('  ', $row['depth']) . $row['line'] . ': ' . $row['kind'] . ' ' . $row['name'];
            if (\count($lines) >= self::OUTLINE_MAX_ROWS || $bytes + \strlen($line) + 1 > self::OUTLINE_MAX_BYTES) {
                break;
            }
            $lines[] = $line;
            $bytes += \strlen($line) + 1;
        }
        $left = \count($rows) - \count($lines);

        return '[outline of this file — ' . \count($rows) . ' declarations; call Read with offset=N to read from line N]'
            . "\n" . implode("\n", $lines)
            . ($left > 0 ? "\n… and {$left} more" : '');
    }

    /**
     * The bytes one page may hold: {@see PAGE_BYTES}, or $maxBytes when the
     * instance was built with a smaller ceiling.
     */
    private function pageBytes(): int
    {
        return min(self::PAGE_BYTES, $this->maxBytes);
    }

    /**
     * `offset`/`limit` as a positive int, null when absent, or the error text to
     * return. A numeric string is accepted: tool-call JSON from a weaker model
     * routinely quotes numbers, and refusing `"40"` would cost a turn for nothing.
     *
     * @param array<string, mixed> $args
     */
    private static function lineArgument(array $args, string $name): int|string|null
    {
        $value = $args[$name] ?? null;
        if ($value === null) {
            return null;
        }
        if (is_string($value) && preg_match('/^\s*\d+\s*$/', $value) === 1) {
            $value = (int) trim($value);
        }
        if (!is_int($value) || $value < 1) {
            return "Error: {$name} must be a positive integer (line numbers start at 1)";
        }

        return $value;
    }

    /**
     * One page of $path: lines $offset onward, each as `N: text`, stopping at
     * $limit lines or {@see pageBytes()} bytes (prefixes and newlines counted),
     * whichever comes first, with a continuation footer when lines remain.
     *
     * Two passes over the file, each a block at a time: the first counts the
     * lines, so the footer can say "of N" and an `offset` past the end can be
     * refused with the real count; the second skips to $offset and builds the
     * page. Neither holds more than one block plus one page in memory, however
     * large the file or long its lines.
     *
     * A line that cannot fit even on an otherwise empty page is the one case
     * that cannot be paged by line: it comes back cut at the page size and
     * marked with how much of it was shown, and the footer moves on to the next
     * line, so repeated calls always make progress.
     *
     * Every fread() asks for min({@see SCAN_CHUNK}, $maxBytes) bytes, so a
     * non-positive $maxBytes still throws on the first one, as it always has.
     */
    private function readPage(string $path, int $offset, int $limit): string
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException("Error reading file {$path}");
        }

        try {
            $chunkLength = min(self::SCAN_CHUNK, $this->maxBytes);
            $read = static function () use ($handle, $chunkLength, $path): string {
                $chunk = fread($handle, $chunkLength);
                if ($chunk === false) {
                    throw new \RuntimeException("Error reading file {$path}");
                }

                return $chunk;
            };

            // Pass 1: how many lines. A final line without a newline counts.
            $total = 0;
            $lastByte = '';
            while (($chunk = $read()) !== '') {
                $total += substr_count($chunk, "\n");
                $lastByte = $chunk[-1];
            }
            if ($lastByte !== '' && $lastByte !== "\n") {
                $total++;
            }

            if ($total === 0) {
                // An empty file is a complete, empty read, not an offset error.
                if ($offset === 1) {
                    return '';
                }
            }
            if ($offset > $total) {
                throw new \RuntimeException(
                    "Error: offset {$offset} is past the end of {$path}, which has {$total} "
                    . ($total === 1 ? 'line' : 'lines'),
                );
            }

            // Pass 2: skip to $offset, then build the page.
            rewind($handle);
            $buffer = '';
            $skip = $offset - 1;
            while ($skip > 0) {
                $chunk = $read();
                if ($chunk === '') {
                    break;
                }
                $newlines = substr_count($chunk, "\n");
                if ($newlines < $skip) {
                    $skip -= $newlines;
                    continue;
                }
                $at = -1;
                for ($i = 0; $i < $skip; $i++) {
                    $at = (int) strpos($chunk, "\n", $at + 1);
                }
                $buffer = substr($chunk, $at + 1);
                $skip = 0;
            }

            $pageBytes = $this->pageBytes();
            $lines = [];
            $used = 0;
            $lineNumber = $offset;
            $cutNote = null;
            $line = '';
            $lineLength = 0;
            $eof = false;

            while (count($lines) < $limit) {
                $newline = strpos($buffer, "\n");
                if ($newline === false) {
                    // Keep at most one page of the current line; count the rest.
                    $lineLength += strlen($buffer);
                    if (strlen($line) <= $pageBytes) {
                        $line .= substr($buffer, 0, $pageBytes + 1 - strlen($line));
                    }
                    $buffer = $read();
                    if ($buffer !== '') {
                        continue;
                    }
                    $eof = true;
                    if ($lineLength === 0) {
                        break;
                    }
                } else {
                    $piece = substr($buffer, 0, $newline);
                    $lineLength += strlen($piece);
                    if (strlen($line) <= $pageBytes) {
                        $line .= substr($piece, 0, $pageBytes + 1 - strlen($line));
                    }
                    $buffer = substr($buffer, $newline + 1);
                }

                $prefix = $lineNumber . ': ';
                $cost = strlen($prefix) + $lineLength + ($lines === [] ? 0 : 1);
                if ($used + $cost <= $pageBytes) {
                    $lines[] = $prefix . $line;
                    $used += $cost;
                } elseif ($lines === []) {
                    $marker = ' … [line ' . $lineNumber . ' truncated: %s of ' . number_format($lineLength)
                        . ' bytes shown]';
                    $room = max(0, $pageBytes - strlen($prefix) - strlen(sprintf($marker, number_format($pageBytes))));
                    $head = mb_strcut($line, 0, $room, 'UTF-8');
                    $lines[] = $prefix . $head . sprintf($marker, number_format(strlen($head)));
                    $cutNote = $lineNumber;
                } else {
                    break;
                }
                $lineNumber++;
                $line = '';
                $lineLength = 0;
                if ($eof || $cutNote !== null) {
                    break;
                }
            }

            $last = $lineNumber - 1;
            $page = implode("\n", $lines);
            if ($last < $total) {
                $span = $last === $offset ? "line {$offset}" : "lines {$offset}-{$last}";
                $page .= "\n\n[{$span} of {$total} — call Read with offset=" . ($last + 1) . ' to continue]';
            }

            return $page;
        } finally {
            fclose($handle);
        }
    }
}
