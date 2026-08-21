<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\BuiltIn;

use SugarCraft\Crush\Context\InstructionFileLoader;
use SugarCraft\Crush\Skills\SkillPathNudge;
use SugarCraft\Crush\Tools\CarriesSessionState;
use SugarCraft\Crush\Tools\Concerns\TruncatesOutput;
use SugarCraft\Crush\Tools\IgnoreRules;
use SugarCraft\Crush\Tools\ParallelSafe;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolResult;
use SugarCraft\Crush\Tools\PathJail;

final readonly class Glob implements Tool, ParallelSafe, CarriesSessionState
{
    use TruncatesOutput;

    /**
     * Directories a recursive walk skips unless the caller asks for them.
     *
     * These are the four trees that make `**` unusable in a real project: they
     * are large, machine-generated, and almost never what a question about
     * "the code" means. Measured on this very repo, `**\/*.php` returned 8,916
     * paths of which 8,439 were under `vendor/` — the signal was 5% of the
     * answer and the other 95% was replayed into every request of the turn.
     *
     * Pruned during the WALK, not filtered afterwards: the cost being avoided
     * is the traversal itself (and, for each match, an
     * {@see InstructionFileLoader} tree-walk on top of it).
     *
     * Shared with {@see Grep} through {@see IgnoreRules} so the two tools
     * cannot drift into answering the same question differently.
     */
    private const DEFAULT_PRUNED_DIRS = IgnoreRules::DEFAULT_EXCLUDED_DIRS;

    /**
     * Ceiling on how many paths one call collects.
     *
     * A count cap rather than only a byte cap because the traversal is the
     * expensive half: stopping at 1,000 entries means the walk ENDS there,
     * instead of collecting 112,000 and discarding 111,000 of them. 1,000
     * paths is also about what the byte cap admits, so the two agree rather
     * than one silently pre-empting the other.
     */
    private const DEFAULT_MAX_MATCHES = 1000;

    /**
     * $skillNudge turns a skill's `paths:` frontmatter into a live signal
     * (crush_feat.md section 7 E4): the whole match list is scoped in ONE
     * call, so a 500-file glob costs one in-memory pass, not one per file.
     * Null keeps the tool standalone.
     *
     * $prunedDirs null means {@see DEFAULT_PRUNED_DIRS}; pass `[]` to walk
     * everything. Note that an explicit prune list is not the only escape
     * hatch — see {@see prunedDirs()} for the per-pattern opt-out.
     */
    public function __construct(
        private ?string $root = null,
        private ?InstructionFileLoader $instructionLoader = null,
        private array $sessionCache = [],
        private ?SkillPathNudge $skillNudge = null,
        private int $maxOutputBytes = self::DEFAULT_MAX_OUTPUT_BYTES,
        private int $maxMatches = self::DEFAULT_MAX_MATCHES,
        private ?array $prunedDirs = null,
    ) {}

    /**
     * Walking the tree mutates nothing a sibling call could observe. The
     * announce-once marks `execute()` sets on the shared
     * {@see InstructionFileLoader}/{@see SkillPathNudge} DO need to survive
     * the fork, hence {@see CarriesSessionState}.
     */
    public function isParallelSafe(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     *
     * Kept byte-identical to {@see Read::exportSessionState()} — see the note
     * there on why the two must not drift.
     */
    public function exportSessionState(): array
    {
        return [
            'emittedInstructionPaths' => $this->instructionLoader?->emittedPaths() ?? [],
            'announcedSkills' => $this->skillNudge?->announced() ?? [],
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

        $skills = $state['announcedSkills'] ?? null;
        if (is_array($skills)) {
            $this->skillNudge?->markAnnounced(array_values($skills));
        }
    }

    public function name(): string
    {
        return 'Glob';
    }
    /**
     * The prune list is stated here, not just in the result, because a default
     * the model only learns about AFTER it has already asked the wrong
     * question costs a turn. Naming the directories up front lets it write
     * `vendor/**\/*.php` the first time.
     *
     * Stays COMPUTED, and the added when-to-reach-for-this guidance is shared
     * by both branches: a caller that switched pruning off (`prunedDirs: []`)
     * still needs to know what the tool is for and what it returns, it just
     * has no prune list to be warned about.
     */
    public function description(): string
    {
        // `**` is the tool's own advertised pattern shape (see inputSchema()
        // and match()'s note on PHP glob() having no globstar), so the lead
        // spells it rather than leaving the model to guess whether recursion
        // is supported at all.
        $lead = 'Find files matching a glob pattern (e.g. "**/*.php") under a base directory. '
            . 'Reach for this instead of a shell `find`/`ls` when you know how the files are '
            . 'named but not where they live: `**` matches across directory levels, and '
            . 'matches come back one path per line, followed by notes naming anything '
            . 'pruned, gitignored, not followed or clipped.';

        // Claimed only by an instance that HAS the loader, because only then
        // does execute() add an instruction section to the path list -- and a
        // result that is not purely a path list has to say so.
        //
        // "after the list", not "above that path": the bodies used to be
        // interleaved one before each path they governed, which is what let
        // them spend the byte cap the paths needed. They are now one labelled
        // section at the end, with its own share of the budget.
        if ($this->instructionLoader !== null) {
            $lead .= ' A CLAUDE.md/AGENTS.md governing a matched file\'s directory is '
                . 'surfaced once, in a labelled section after the list, the first time '
                . 'the directory is touched.';
        }

        $pruned = $this->prunedDirNames();
        if ($pruned === []) {
            return $lead;
        }

        return $lead . sprintf(
            ' A recursive `**` walk skips %s by default; '
            . 'naming one of those directories in the pattern (e.g. "%s/**/*.php") or pointing '
            . 'path inside it searches there instead.',
            implode(', ', $pruned),
            self::pruneExample($pruned),
        );
    }
    public function inputSchema(): array
    {
        $pruned = $this->prunedDirNames();
        $patternHint = $pruned === [] ? '' : sprintf(
            ' A recursive `**` walk skips %s unless you name the directory here (e.g. "%s/**/*.php").',
            implode(', ', $pruned),
            self::pruneExample($pruned),
        );
        $pathHint = $pruned === [] ? '' : sprintf(
            ' Pointing this inside a skipped directory (e.g. "%s/acme/pkg") searches it in full.',
            self::pruneExample($pruned),
        );

        return [
        'type' => 'object',
        'properties' => [
            'pattern' => [
                'type' => 'string',
                'description' => 'The glob pattern to match (e.g., **/*.php).' . $patternHint,
            ],
            'path' => [
                'type' => 'string',
                'description' => 'Base directory path.' . $pathHint,
            ],
            'description' => [
                'type' => 'string',
                'description' => 'Clear, concise 5-10 word description in active voice of what this search looks for (e.g. "Find every provider test file", not "globs *.php").',
            ],
            'include_ignored' => [
                'type' => 'boolean',
                'description' => 'Search files the project\'s .gitignore excludes (build output, caches, local config). Off by default; the result says when .gitignore hid something.',
            ],
        ],
        'required' => ['pattern', 'path', 'description'],
        ];
    }

    public function execute(array $args): ToolResult
    {
        $pattern = $args['pattern'] ?? '';
        $path = $args['path'] ?? '';

        if ($pattern === '') {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: 'Error: pattern cannot be empty',
                isError: true,
            );
        }

        // SECURITY: the jail below validates `path` and nothing else, but the
        // non-recursive branch then concatenates `pattern` onto the resolved
        // base — so `path: "."` with `pattern: "../*.php"` walked straight out
        // of the workspace with the jail reporting success. `..` has no
        // legitimate use here: the tool already takes a base directory, which
        // is the supported way to search somewhere else.
        if (self::hasTraversalSegment($pattern)) {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: 'Error: pattern may not contain a ".." segment; use the path argument to choose a base directory',
                isError: true,
            );
        }

        // realpath() THROWS a ValueError on a NUL byte rather than failing, so
        // an unguarded NUL left execute() as an uncaught crash. PathJail also
        // rejects it now, but say so in this tool's own vocabulary instead of
        // reporting a malformed argument as a containment verdict.
        if (str_contains($path, "\0")) {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: 'Error: path contains a NUL byte',
                isError: true,
            );
        }

        if ($this->root !== null) {
            $resolved = PathJail::resolveDir($this->root, $path);
            if ($resolved === null) {
                return new ToolResult(
                    toolCallId: $args['id'] ?? '',
                    content: 'Error: path outside workspace root',
                    isError: true,
                );
            }
            $path = $resolved;
        } elseif (!is_dir($path)) {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: "Error: directory not found: $path",
                isError: true,
            );
        }

        // rtrim eats the root directory entirely — "/" becomes "" — and an
        // empty string is a \ValueError out of RecursiveDirectoryIterator,
        // not the \UnexpectedValueException the walk is prepared for. `path:
        // "/"` is a plausible thing for a model to send, and it must not
        // surface as a raw internal PHP message.
        $baseDir = rtrim($path, '/');
        if ($baseDir === '') {
            $baseDir = '/';
        }

        // Two different problems, so two different messages. The jailed branch
        // above settles CONTAINMENT, not directory-ness: PathJail::resolveDir()
        // gates on existence and hands back an existing regular file as readily
        // as a directory (deliberately — it is a containment predicate, and
        // both answers are inside the jail). So `path: "sub/here.txt"` reached
        // here and was reported as an unreadable directory, which is wrong
        // twice over about a perfectly readable file and sends the model
        // hunting for a permission problem that does not exist.
        if (!is_dir($baseDir)) {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: sprintf('Error: not a directory: %s', $baseDir),
                isError: true,
            );
        }

        // A permission problem is not an answer. Left to the walk it came back
        // as content='' / isError=false, i.e. a confident "no such files" for
        // a directory nobody was allowed to look inside — the same
        // stated-with-confidence wrong answer the truncation markers exist to
        // avoid.
        if (!is_readable($baseDir)) {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: sprintf('Error: directory is not readable: %s', $baseDir),
                isError: true,
            );
        }

        $normalized = ltrim($pattern, '/');

        // Compile ONCE, up front. Compiling per walked entry meant a malformed
        // bracket expression emitted one `preg_match(): Compilation failed`
        // warning PER FILE -- on a large tree, six figures of them, written to
        // stdout underneath the TUI frame -- and then returned isError=false
        // with empty content, i.e. "no files match" for a pattern that was
        // never valid. A bad pattern is a bad request, and says so once.
        $regex = null;
        if (str_contains($normalized, '**')) {
            $regex = self::patternToRegex($normalized);
            if (!self::compiles($regex)) {
                return new ToolResult(
                    toolCallId: $args['id'] ?? '',
                    content: sprintf(
                        'Error: pattern "%s" is not a valid glob (it compiles to an invalid expression). '
                        . 'Check the bracket expressions -- e.g. an empty "[]" or a reversed range "[z-a]".',
                        $pattern,
                    ),
                    isError: true,
                );
            }
        }

        // The ignore root is the WORKSPACE root when there is one, so a search
        // scoped to a subdirectory still honours the project's top-level
        // .gitignore. Unjailed there is nothing above $baseDir this tool has
        // been told to trust, and silently climbing out of the directory the
        // caller named to pick up a stranger's rules is worse than missing
        // them.
        $rules = IgnoreRules::new($this->root ?? $baseDir)
            ->withGitignore(!self::flag($args['include_ignored'] ?? null));

        // Pointing `path` INSIDE an ignored directory is a deliberate request
        // for it, exactly as it already is for the prune list. Without this,
        // `path: "build/reports"` in a project that ignores `build/` came back
        // empty — a confident "nothing there" about a directory the caller had
        // just named.
        if ($rules->ignores($baseDir, true)) {
            $rules = $rules->withGitignore(false);
        }

        $found = $this->match($baseDir, $normalized, $regex, $rules);
        if ($found['error'] !== null) {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: $found['error'],
                isError: true,
            );
        }
        $files = $this->withinRoot($found['files']);

        // The LIST first, on its own, with nothing interleaved into it.
        //
        // The bodies used to be prepended into this loop, one before each path
        // it governed, and then the whole thing was clipped — so the rules
        // were inside the budget and the paths were what the clip spent it on.
        // MEASURED at 4a4ecb98 against the fixture in `ToolOutputBudgetTest`:
        // a 9,611-byte `sub/CLAUDE.md` over five matched `.php` files returned
        // 195 bytes against a 200-byte cap containing the `BIG-RULE` heading, a
        // truncation marker, and ZERO of the five paths. The tool was asked
        // which files match and answered with a rule book.
        //
        // Grep had the opposite defect at the same commit — it appended the
        // bodies AFTER its clip, so the hits survived but the cap did not (400
        // -> 10,096 bytes). The two tools answer the same shape of question and
        // now compose the same way: results, then notes, then a LABELLED
        // instruction section that has its own quarter of the budget and its
        // own marker.
        $output = '';
        foreach ($files as $file) {
            $output .= $file . "\n";
        }

        if ($found['capped']) {
            $output .= sprintf(
                "... [truncated: stopped after %d matches. This list is PARTIAL, not the complete answer "
                . "— narrow the pattern or point path at a subdirectory to see the rest.]\n",
                $this->maxMatches,
            );
        }

        $ceiling = $this->instructionSectionCeiling($this->maxOutputBytes);

        // Probed at the FLOOR — the smallest budget the final list can be
        // given — so every path whose instruction file is loaded below is
        // guaranteed to still be in the result. Loading against the FULL list
        // is what this replaces, and that was a second announce-once leak in
        // its own right: a `**/*.php` over a large tree retired the
        // `CLAUDE.md` of every matched directory for the whole session while
        // showing the model only the handful of paths that fit the cap.
        // {@see Grep::execute()} takes the same probe for the same reason.
        // max(1, ...) and not max(0, ...): 0 is truncateOutput()'s "no cap"
        // sentinel, so a cap small enough that a quarter rounds the floor to
        // zero handed the probe an UNBOUNDED budget. MEASURED before this
        // guard: Glob at maxOutputBytes = 1 returned 10,068 bytes — the whole
        // rule book verbatim — where caps 2 to 8 returned 183.
        // The floor is computed from instructionSectionCeiling() and NOT from
        // the reserve, and the difference only shows at small caps: where a
        // quarter cannot hold one body floor the section is allowed to spend
        // that floor instead, because a rule governing a path the model was
        // shown has to be surfaced somewhere. Sizing the floor with the same
        // ceiling is what keeps the total inside the cap anyway — the result
        // simply gives up the difference — and what keeps this probe no
        // tighter than the final clip below, which is the property the
        // announce-once mark depends on.
        $floor = $this->maxOutputBytes > 0
            ? max(1, $this->maxOutputBytes - $ceiling - 1)
            : 0;
        $probe = $this->truncateOutput($output, $floor);
        $shown = self::pathsIn($probe, $files);

        // Bounded in COUNT as well as in bytes, and the count is decided
        // BEFORE any body is loaded — see
        // {@see \SugarCraft\Crush\Tools\Concerns\TruncatesOutput::instructionSection()}.
        // Glob is where that matters most: `**\/*.php` over a real tree names
        // hundreds of directories, and one floor-priced entry per directory
        // is a section that grows without limit however the byte share is
        // divided.
        $section = $this->instructionSection($shown, $this->instructionLoader, $this->maxOutputBytes);

        // Clipped at the FLOOR whenever a rule was surfaced, and NOT at what
        // the section happened to leave over. Spending the leftover would show
        // more paths than the probe examined, and the rules of those extra
        // paths are then neither announced nor spent — harmless, but it makes
        // "the announce-once mark is spent on exactly what the model receives"
        // an accident of how big the section came out rather than a law.
        // MEASURED with the leftover spent, over
        // `GrepInstructionWiringTest`'s six-directory fixture at cap 1024: the
        // final clip showed aaa, bbb and fff where the probe had examined only
        // bbb. Pinning the result at the floor makes probe and final clip the
        // same cut, so what is ANNOUNCED is drawn from exactly the set that is
        // VISIBLE. It is a containment and not an equality — the count bound
        // can still withhold a path the probe examined, and says so in the
        // result — but the direction that matters is closed: nothing is ever
        // announced for a path the model cannot see.
        //
        // It costs only the calls that actually surface a rule, and only the
        // difference between the cap and the three-quarter floor the split
        // promises. Under announce-once that is the first touch of a
        // directory; every call after it has no section and takes the whole
        // cap, byte-identical to the same tool built with no loader at all.
        $output = $section === ''
            ? $this->truncateOutput($output, $this->maxOutputBytes)
            : $probe;

        // The pruning has to announce itself for the same reason the byte cap
        // and the match cap do: a silently shortened list reads as a complete
        // one, so `**\/Controller.php` over a project whose real source lives
        // in vendor/ came back empty and the model concluded the file does not
        // exist. Naming what was skipped, and how to ask for it, is the whole
        // difference between a partial answer and a wrong one.
        //
        // Appended AFTER the clip, like the nudge: it is one line, it is the
        // only place the escape hatch appears in the result, and it must not
        // be the thing the budget sacrifices.
        if ($found['pruned'] !== []) {
            $output = self::separated($output);
            $output .= sprintf(
                "... [pruned: skipped %s. Files inside those directories are NOT in this list — "
                . "name the directory in the pattern (e.g. \"%s/**/*.php\") or point path inside it "
                . "to search there.]\n",
                implode(', ', $found['pruned']),
                self::pruneExample($found['pruned']),
            );
        }

        // Same doctrine as the prune note, for the same reason: a list quietly
        // shortened by .gitignore reads as a complete one. The difference is
        // that the hatch here cannot be spelled in the pattern — an ignore rule
        // is not a directory name — so the note has to name the argument.
        if ($found['ignored'] > 0) {
            $output = self::separated($output);
            $output .= sprintf(
                "... [gitignored: skipped %d path(s) the project's .gitignore excludes. "
                . "Pass include_ignored: true to search them.]\n",
                $found['ignored'],
            );
        }

        // A symlinked directory is where this monorepo's sibling libraries
        // live, so "not followed" is a genuinely surprising omission and the
        // note carries its own hatch: seeding the walk AT the link is bounded,
        // where following every link on the way past is not.
        if ($found['symlinks'] > 0) {
            $output = self::separated($output);
            $output .= sprintf(
                "... [symlinks: did not follow %d symlinked director%s. "
                . "Point path at the link itself to search inside it.]\n",
                $found['symlinks'],
                $found['symlinks'] === 1 ? 'y' : 'ies',
            );
        }

        // After the notes, like Grep: the notes are one line each and are the
        // only place the escape hatch appears, so they go where the budget
        // cannot reach them. The instruction section is bounded by its own
        // reserve, subtracted from the list's budget above, so putting it last
        // costs the cap nothing.
        if ($section !== '') {
            $output = self::separated($output);
            $output .= $section;
        }

        // Scoped to every MATCHED path, not to $shown. Deliberate, and the one
        // place this tool does not follow the announce-once doctrine above:
        // {@see \SugarCraft\Crush\Skills\SkillPathNudge::forPaths()} answers
        // "does a skill claim this area of the tree", which the clip does not
        // change the truth of, and the nudge names the SKILL rather than the
        // path — so a nudge earned by a path the cap dropped is still a true
        // and actionable statement, where an instruction body shown for an
        // unseen path is neither.
        $nudge = $this->skillNudge?->forPaths($files);
        if ($nudge !== null) {
            $output .= "\n" . $nudge;
        }

        return new ToolResult(
            toolCallId: $args['id'] ?? '',
            content: $output,
            isError: false,
        );
    }

    /**
     * Resolve $pattern against $baseDir.
     *
     * PHP's native glob() has NO globstar support: it treats a doubled star
     * as an ordinary single-segment wildcard, so a recursive pattern silently
     * returned only the files exactly one directory down — missing both the
     * base directory and anything deeper — with no error and no warning.
     * This tool's own inputSchema advertises that very pattern shape, so the
     * advertised usage was the broken one.
     *
     * glob() is still the fast path for every pattern WITHOUT `**`: it is a
     * single libc call and already correct there.
     *
     * @param ?string $regex precompiled and already validated by execute();
     *                       null on the non-recursive fast path
     *
     * @return array{files: list<string>, capped: bool, pruned: list<string>, ignored: int, symlinks: int, error: ?string}
     *         absolute paths, sorted; `pruned` names the default-skipped
     *         directories this walk ACTUALLY passed over, `ignored` counts the
     *         entries `.gitignore` excluded and `symlinks` the symlinked
     *         directories it refused to follow — so the result can say what it
     *         is missing instead of pretending to be complete
     */
    private function match(string $baseDir, string $pattern, ?string $regex, IgnoreRules $rules): array
    {
        // The separator-free form, used only where this method JOINS a base to
        // something ("$stripped/$pattern", "$stripped/$relative"): at "/" that
        // is "" and the join still produces exactly one slash. It is
        // deliberately not what the iterator is seeded with — "" is a
        // \ValueError there — nor what the offset below is measured from.
        $stripped = rtrim($baseDir, '/');

        if ($regex === null) {
            $files = glob($stripped . '/' . $pattern);
            $files = $files === false ? [] : array_values($files);

            // The fast path never descends, so there is no traversal to prune
            // and no symlink to refuse — but a single-level `*.php` can still
            // land squarely on ignored build output, and a hit is a hit no
            // matter which branch found it.
            $kept = array_values(array_filter(
                $files,
                static fn (string $file): bool => !$rules->ignores($file, is_dir($file)),
            ));

            return $this->capped($kept, count($files) - count($kept));
        }

        $hidden = self::hiddenPolicy($pattern);
        $pruned = $this->prunedDirs($pattern, $baseDir);
        // Measured against $baseDir, NOT the stripped form:
        // RecursiveDirectoryIterator builds every pathname as the string it was
        // HANDED plus "/" plus the filename, so seeding it with "/" yields
        // "//admin" and the prefix to drop is 2, not 1. Deriving the offset
        // from $stripped left a leading "/" on the relative path, which the
        // anchored regex can never match — a recursive glob based at "/"
        // returned zero matches with isError=false, and since nothing matched,
        // the cap never engaged and the walk ran the whole filesystem.
        $baseLen = strlen($baseDir) + 1;
        $skipped = [];

        try {
            $directory = new \RecursiveDirectoryIterator(
                $baseDir,
                \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_PATHNAME,
            );
        } catch (\Throwable $e) {
            // \Throwable, not \UnexpectedValueException: the constructor also
            // raises \ValueError (an empty path), which is an \Error and would
            // have escaped execute() as a raw internal PHP message.
            return [
                'files' => [],
                'capped' => false,
                'pruned' => [],
                'ignored' => 0,
                'symlinks' => 0,
                'error' => sprintf('Error: cannot read directory %s: %s', $baseDir, $e->getMessage()),
            ];
        }

        $ignored = 0;
        $symlinks = 0;

        // Returning false here does double duty: the entry is dropped AND, if
        // it is a directory, never descended into. That is what makes this a
        // prune rather than a post-hoc filter.
        $filtered = new \RecursiveCallbackFilterIterator(
            $directory,
            static function (string $path) use ($hidden, $pruned, $rules, &$skipped, &$ignored, &$symlinks): bool {
                $base = basename($path);

                if (isset($pruned[$base]) && is_dir($path)) {
                    // Recorded, not merely skipped: what the walk left out is
                    // part of the answer.
                    $skipped[$base] = true;

                    return false;
                }

                // The symlink hard stop. RecursiveDirectoryIterator would not
                // have descended it either (hasChildren() defaults to
                // $allowLinks=false), but leaning on that default left the
                // property invisible, untested at the tool's own boundary, and
                // one flag change away from an unbounded walk — this repo's
                // path-repo symlinks form real cycles. See IgnoreRules::halts().
                if ($rules->halts($path)) {
                    $symlinks++;

                    return false;
                }

                // Checked before the hidden-name rule so an ignored dotfile is
                // counted as ignored rather than silently absorbed by it.
                if ($rules->ignores($path, is_dir($path))) {
                    $ignored++;

                    return false;
                }

                // glob() never matches a leading dot with a wildcard, so
                // neither does the walk. Deciding this PER SEGMENT (rather
                // than "the pattern mentions a dot somewhere") is what keeps
                // `.github/**/*.yml` out of `.git/`: naming one hidden
                // directory opts into that directory, not into all of them.
                if (str_starts_with($base, '.')) {
                    return $hidden['all'] || isset($hidden['names'][$base]);
                }

                return true;
            },
        );

        // CATCH_GET_CHILD: one unreadable subdirectory must not abort the
        // whole walk. SELF_FIRST so a pattern can match a directory, as
        // glob() does.
        $walker = new \RecursiveIteratorIterator(
            $filtered,
            \RecursiveIteratorIterator::SELF_FIRST,
            \RecursiveIteratorIterator::CATCH_GET_CHILD,
        );

        $matches = [];
        $capped = false;
        foreach ($walker as $path) {
            $relative = substr((string) $path, $baseLen);
            if ($relative !== '' && preg_match($regex, $relative) === 1) {
                // Rebuilt from $stripped rather than reported as the iterator
                // spelled it: at "/" the iterator's own pathnames carry the
                // doubled separator ("//admin"), and a path handed back to the
                // model is a path it will feed to Read or Bash next.
                $matches[] = $stripped . '/' . $relative;
                if ($this->maxMatches > 0 && count($matches) >= $this->maxMatches) {
                    // Abandon the walk rather than finish it and throw the
                    // surplus away -- the traversal is the expensive part.
                    $capped = true;
                    break;
                }
            }
        }

        sort($matches);

        $skippedNames = array_keys($skipped);
        sort($skippedNames);

        return [
            'files' => $matches,
            'capped' => $capped,
            'pruned' => $skippedNames,
            'ignored' => $ignored,
            'symlinks' => $symlinks,
            'error' => null,
        ];
    }

    /**
     * @param list<string> $files
     *
     * @return array{files: list<string>, capped: bool, pruned: list<string>, ignored: int, symlinks: int, error: ?string}
     */
    private function capped(array $files, int $ignored = 0): array
    {
        // The non-recursive fast path is glob(), which never descends, so
        // there is nothing for it to have pruned and no symlink it could have
        // refused to follow.
        if ($this->maxMatches > 0 && count($files) > $this->maxMatches) {
            return [
                'files' => array_slice($files, 0, $this->maxMatches),
                'capped' => true,
                'pruned' => [],
                'ignored' => $ignored,
                'symlinks' => 0,
                'error' => null,
            ];
        }

        return [
            'files' => $files,
            'capped' => false,
            'pruned' => [],
            'ignored' => $ignored,
            'symlinks' => 0,
            'error' => null,
        ];
    }

    /**
     * Second half of the jail (see the `..` rejection in {@see execute()}).
     *
     * Rejecting `..` closes the textual escape; this closes the rest — a
     * symlink inside the workspace whose target is outside it resolves out of
     * the jail without any `..` appearing anywhere in the request.
     *
     * @param list<string> $files
     *
     * @return list<string>
     */
    private function withinRoot(array $files): array
    {
        if ($this->root === null) {
            return $files;
        }

        $rootReal = realpath($this->root);
        if ($rootReal === false) {
            return [];
        }

        return array_values(array_filter($files, static function (string $file) use ($rootReal): bool {
            $real = realpath($file);

            return $real !== false
                && ($real === $rootReal || str_starts_with($real, $rootReal . '/'));
        }));
    }

    /**
     * Does $regex compile?
     *
     * The handler swap is not belt-and-braces over `@`: `@` only lowers
     * error_reporting, so an ambient handler (PHPUnit's, or a host app's
     * convert-warnings-to-exceptions one) still sees the compilation failure
     * and can turn a well-formed error ToolResult into a crash. Probing for a
     * bad pattern must be silent by construction, not by convention.
     */
    private static function compiles(string $regex): bool
    {
        set_error_handler(static fn (): bool => true);
        try {
            return preg_match($regex, '') !== false;
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Which of $candidates survived a clip of the one-path-per-line list.
     *
     * A membership test over the clipped text's LINES rather than a
     * `str_contains()` over the whole blob: a path is a prefix of every path
     * below it, so `<root>/a` would count itself present whenever `<root>/a/b`
     * survived, and the instruction file of a directory the model never saw
     * would be spent on its behalf.
     *
     * THAT DEFECT IS UNREACHABLE TODAY, AND THIS STAYS ANYWAY — said out loud
     * because a guard whose docblock names a live bug, when the bug cannot
     * happen, reads as evidence of a risk that is not there. `sort($matches)`
     * runs before the clip and a prefix always sorts BEFORE the paths that
     * extend it, so a prefix survives a suffix-truncating clip whenever any of
     * its extensions does: there is no ordering in which `<root>/a/b` is in
     * the kept window and `<root>/a` is not. The only other window this method
     * can be handed — a first-line FRAGMENT, where the budget ran out before
     * the first newline — is SHORTER than the path it came from and so
     * contains no whole candidate at all. Mutating this to `str_contains()`
     * and sweeping a `d/f.php` ⊂ `d/f.php.dir/g.php` fixture across the caps
     * either side of the clip finds zero paths spent but unseen.
     *
     * It stays because the property it depends on lives in a different method:
     * one `sort()` moved or dropped, and the prefix collision is live again,
     * with a spent-but-unshown instruction file as the symptom and nothing in
     * between to catch it.
     *
     * @param list<string> $candidates
     * @return list<string>
     */
    private static function pathsIn(string $clipped, array $candidates): array
    {
        if ($clipped === '' || $candidates === []) {
            return [];
        }

        $lines = array_flip(explode("\n", $clipped));

        return array_values(array_filter($candidates, static fn (string $p): bool => isset($lines[$p])));
    }

    /**
     * Make $output safe to append an annotation line to.
     *
     * truncateOutput() ends on its marker with no trailing newline, so a note
     * appended raw is glued onto the end of that sentence.
     */
    private static function separated(string $output): string
    {
        return ($output !== '' && !str_ends_with($output, "\n")) ? $output . "\n" : $output;
    }

    /**
     * Read a boolean tool argument.
     *
     * Models routinely send `"true"` and `1` for a boolean, and a bare cast
     * turns the string `"false"` into true — which would silently disable the
     * very filter this flag exists to control.
     */
    private static function flag(mixed $value): bool
    {
        return filter_var($value ?? false, FILTER_VALIDATE_BOOL);
    }

    /** True when any `/`-separated segment of $pattern is exactly `..`. */
    private static function hasTraversalSegment(string $pattern): bool
    {
        return in_array('..', explode('/', str_replace('\\', '/', $pattern)), true);
    }

    /**
     * Which dot-prefixed names this pattern opts into.
     *
     * `names` holds the literal hidden segments the pattern actually spells
     * out (`.github/**\/*.yml` → `.github`). `all` is the fallback for a
     * pattern whose hidden segment is itself a wildcard (`.*\/config`), where
     * there is nothing concrete to whitelist.
     *
     * @return array{all: bool, names: array<string, true>}
     */
    private static function hiddenPolicy(string $pattern): array
    {
        $all = false;
        $names = [];

        foreach (explode('/', $pattern) as $segment) {
            if ($segment === '' || !str_starts_with($segment, '.')) {
                continue;
            }

            if (strpbrk($segment, '*?[') === false) {
                $names[$segment] = true;
                continue;
            }

            $all = true;
        }

        return ['all' => $all, 'names' => $names];
    }

    /**
     * The prune set in force for this call, as a lookup keyed by directory
     * name.
     *
     * The list is a default, not a prohibition. Naming a directory in the
     * pattern (`vendor/**\/*.php`) or searching from inside it (`path:
     * "vendor/foo"`) un-prunes it, on the same reasoning the hidden-segment
     * rule uses: spelling a directory out is an explicit request for it, and
     * a search that silently returns nothing for the thing you asked for by
     * name is worse than a slow one. A caller wanting no pruning at all
     * passes `prunedDirs: []` to the constructor.
     *
     * @return array<string, true>
     */
    /**
     * The prune list this instance was built with, for the schema text.
     *
     * Kept separate from {@see prunedDirs()} because the schema is written
     * once per session with no pattern in hand, whereas the per-call set
     * depends on what the pattern named.
     *
     * @return list<string>
     */
    private function prunedDirNames(): array
    {
        return array_values($this->prunedDirs ?? self::DEFAULT_PRUNED_DIRS);
    }

    /**
     * Which prune name to illustrate the opt-out with.
     *
     * A dot-prefixed example (`.git/**\/*.php`) teaches the syntax while
     * naming a directory nobody actually wants searched; the first ordinary
     * one is the case the hatch exists for.
     *
     * @param non-empty-list<string> $names
     */
    private static function pruneExample(array $names): string
    {
        foreach ($names as $name) {
            if (!str_starts_with($name, '.')) {
                return $name;
            }
        }

        return $names[0];
    }

    private function prunedDirs(string $pattern, string $baseDir): array
    {
        $named = array_flip(array_merge(
            explode('/', $pattern),
            explode('/', $baseDir),
        ));

        $pruned = [];
        foreach ($this->prunedDirs ?? self::DEFAULT_PRUNED_DIRS as $dir) {
            if (!isset($named[$dir])) {
                $pruned[$dir] = true;
            }
        }

        return $pruned;
    }

    /**
     * Compile a glob pattern into an anchored PCRE matched against a path
     * RELATIVE to the base directory.
     *
     * The globstar rule that matters: a doubled star followed by a slash
     * matches ZERO or more directory segments, so a recursive `.php` pattern
     * has to find `top.php` in the base directory as well as `a/b/deep.php`.
     * A plain `*` stays confined to one segment.
     *
     * Two deliberate deviations from bash's `globstar`, both kept because the
     * alternative is worse for a tool the model drives:
     *
     * 1. A `**` NOT followed by `/` compiles to `.*`, which crosses `/`. bash
     *    demotes it to a single-segment `*`, so `**.php` there means only
     *    `top.php`; here it also matches `a/b/c.php`. A model that writes
     *    `**.php` means "recursively", and silently answering the
     *    single-level question is the exact failure mode this tool was fixed
     *    to stop doing.
     * 2. `src/**` compiles to `#^src/.*$#` and so does not match `src`
     *    itself, where bash's globstar includes the directory. Listing the
     *    directory you named back to you is noise, and the base directory is
     *    already known to the caller.
     */
    private static function patternToRegex(string $pattern): string
    {
        $out = '';
        $len = strlen($pattern);

        for ($i = 0; $i < $len; $i++) {
            $char = $pattern[$i];

            if ($char === '*') {
                if (($pattern[$i + 1] ?? '') === '*') {
                    $i++;
                    if (($pattern[$i + 1] ?? '') === '/') {
                        $i++;
                        $out .= '(?:[^/]+/)*';
                    } else {
                        $out .= '.*';
                    }
                    continue;
                }

                $out .= '[^/]*';
                continue;
            }

            if ($char === '?') {
                $out .= '[^/]';
                continue;
            }

            if ($char === '[') {
                // POSIX puts a literal `]` first in the class, so `[]]` means
                // "a literal ]" and `[!]]` means "anything but ]". Searching
                // for the terminator from position 1 found that leading `]`
                // and produced the empty class `[]` -- invalid PCRE, and the
                // source of the warning storm. Start the search past it.
                $bodyStart = $i + 1;
                if (($pattern[$bodyStart] ?? '') === '!') {
                    $bodyStart++;
                }
                if (($pattern[$bodyStart] ?? '') === ']') {
                    $bodyStart++;
                }

                $close = strpos($pattern, ']', $bodyStart);
                if ($close !== false) {
                    $body = substr($pattern, $i + 1, $close - $i - 1);
                    // glob negates with `!`, PCRE with `^`.
                    if (str_starts_with($body, '!')) {
                        $body = '^' . substr($body, 1);
                    }
                    // `]` is escaped too: PCRE has no leading-`]` rule, so the
                    // POSIX idiom has to be spelled `[\]]` to survive.
                    // The `#` delimiter ends the pattern even inside a
                    // bracket expression, so it has to be escaped here too.
                    $out .= '[' . str_replace(['\\', '#', ']'], ['\\\\', '\\#', '\\]'], $body) . ']';
                    $i = $close;
                    continue;
                }

                // Unterminated `[` -- glob() treats it as a literal, so do we.
            }

            $out .= preg_quote($char, '#');
        }

        return '#^' . $out . '$#';
    }
}
