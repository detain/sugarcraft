<?php

declare(strict_types=1);

namespace SugarCraft\Git\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Git\Git;

/**
 * Structural guard for the git-spawn trust prelude (review m1).
 *
 * The clone-time execution doors (core.fsmonitor, diff.external, and the
 * merge-driver family neutralized on the merge path) all close because every
 * git child this library spawns is assembled through ONE funnel —
 * Git::gitBase() — which carries `-c core.quotepath=off -c
 * core.fsmonitor=false -C <cwd>`. Nothing in the behavioral suite stops a
 * future `Process::run(['git', '-C', ...])` from appearing beside the funnel
 * (the original isRepository/isBareRepository shape — harmless today, a K2
 * reopening vector tomorrow). This guard fails loudly if:
 *
 * - a Process::run() call site spawns argv not assembled from the funnel;
 * - the funnel itself loses a prelude overlay;
 * - the merge-capable dispatch table sheds a verb whose machinery can reach
 *   `merge.<name>.driver`;
 * - the per-invocation driver disarm stops emitting its `=false` overrides;
 * - the filter-capable dispatch table (the detect-and-announce channel) sheds
 *   a verb that fires clean/smudge/process drivers, the filter guard vanishes
 *   from the argv assembler, or the guard starts MUTATING argv — its contract
 *   is side effects only (refuse or one-shot notice), so the zero-driver
 *   common case stays byte-identical to the plain prelude.
 *
 * Roster genre, fleet-consistent (StderrEmitterCensusTest upstream): the
 * expected call-site COUNT is pinned so new spawn sites force a deliberate
 * revisit instead of slipping through a "some subset of sites is guarded"
 * reading. The walk is RECURSIVE over src/ (review m-2): a site planted in a
 * nested directory like src/Support/ must be censused too, and
 * testTheRecursiveWalkCatchesANestedRawSpawnSite pins that with a live plant.
 */
final class GitSpawnPreludeGuardTest extends TestCase
{
    /** Every Process::run() spawn site in src/ (recursively), by design. */
    private const EXPECTED_SPAWN_SITES = 6;

    public function testEveryGitSpawnIsAssembledFromThePreludeFunnel(): void
    {
        $sites = $this->spawnSites();

        self::assertCount(
            self::EXPECTED_SPAWN_SITES,
            $sites,
            'Process::run() call sites in src/ drifted; each must flow through gitBase()/argv()/argvWithDisarms(), '
            . 'and a genuinely new spawn path must be adjudicated into this roster, not added silently',
        );

        foreach ($sites as $where => $argvSlice) {
            $throughFunnel = str_contains($argvSlice, 'gitBase(')
                || str_contains($argvSlice, 'argv(')
                || str_contains($argvSlice, 'argvWithDisarms(');
            self::assertTrue($throughFunnel, "{$where}: Process::run() argv does not flow through the prelude funnel");

            // The funnel returns the literal 'git' program token; a call site
            // spelling argv itself would double- or bypass-prefix the prelude.
            self::assertStringNotContainsString(
                "'git'",
                $argvSlice,
                "{$where}: call site hand-assembles git argv instead of composing the funnel's",
            );
        }
    }

    /**
     * Non-vacuity of the recursive walk (review m-2): plant a throwaway file
     * under src/Support/ carrying exactly the evasion shape — a raw
     * `Process::run(['git', ...])` beside the funnel — and prove the census
     * sees it and the funnel predicate rejects it. The plant is deleted in
     * finally and never committed.
     */
    public function testTheRecursiveWalkCatchesANestedRawSpawnSite(): void
    {
        $dir   = dirname((string) (new \ReflectionClass(Git::class))->getFileName());
        $name  = 'GuardProbePlant' . bin2hex(random_bytes(4));
        $plant = $dir . '/Support/' . $name . '.php';

        try {
            file_put_contents($plant, "<?php\n\ndeclare(strict_types=1);\n\nnamespace SugarCraft\\Git\\Support;\n\nuse SugarCraft\\Git\\Process;\n\nfinal class {$name}\n{\n    public static function evaded(): array\n    {\n        return Process::run(['git', 'status'], null, 1.0);\n    }\n}\n");
            self::assertFileExists($plant);

            $sites = $this->spawnSites();
            $found = null;
            foreach ($sites as $where => $argvSlice) {
                if (str_starts_with((string) $where, $name . '.php::')) {
                    $found = $argvSlice;
                }
            }
            self::assertNotNull($found, 'a Process::run() site planted in src/Support/ evaded the census walk');

            // And it is exactly a violation under the same predicate
            // testEveryGitSpawnIsAssembledFromThePreludeFunnel applies:
            self::assertFalse(
                str_contains($found, 'gitBase(') || str_contains($found, 'argv(') || str_contains($found, 'argvWithDisarms('),
                'planted site unexpectedly looks funnel-routed — the predicate is vacuous',
            );
            self::assertStringContainsString("'git'", $found, 'planted site must carry the raw git token the predicate accuses');
        } finally {
            @unlink($plant);
        }
        self::assertFileDoesNotExist($plant, 'the plant must never survive the test, let alone be committable');
    }

    public function testTheFunnelCarriesTheFullTrustPreludeByteExactly(): void
    {
        $src  = $this->sourceOf(Git::class);
        $body = $this->functionBody($src, 'gitBase');

        self::assertStringContainsString(
            "['git', '-c', 'core.quotepath=off', '-c', 'core.fsmonitor=false', '-C', \$cwd]",
            $body,
        );
    }

    public function testMergeCapableDispatchTableNamesEveryDriverBearingVerb(): void
    {
        $constant = (new \ReflectionClass(Git::class))->getReflectionConstant('MERGE_CAPABLE_DISPATCH');
        self::assertNotFalse($constant, 'MERGE_CAPABLE_DISPATCH vanished from Git.php');
        /** @var array<string,?list<string>> $table */
        $table = $constant->getValue();

        // Keys are the argv heads that reach git's 3-way merge machinery.
        // Losing any entry reopens a merge-driver door on that path.
        self::assertSame(['merge', 'rebase', 'cherry-pick', 'stash'], array_keys($table));
        self::assertContains('--continue', $table['rebase']);
        self::assertContains('--skip', $table['rebase']);
        self::assertContains('apply', $table['stash']);
        self::assertContains('pop', $table['stash']);
    }

    public function testFilterCapableDispatchTableNamesEveryCleanSmudgeVerb(): void
    {
        $constant = (new \ReflectionClass(Git::class))->getReflectionConstant('FILTER_CAPABLE_DISPATCH');
        self::assertNotFalse($constant, 'FILTER_CAPABLE_DISPATCH vanished from Git.php');
        /** @var array<string,?list<string>> $table */
        $table = $constant->getValue();

        // Ordered key set: the measured firing surface on git 2.43 (add,
        // commit, branch-switching checkout, diff, restore, reset, merge,
        // cherry-pick, rebase, stash apply/pop, worktree add), with the
        // head-level conservative overs disclosed in the table's docblock.
        self::assertSame(
            ['add', 'checkout', 'commit', 'diff', 'restore', 'reset', 'merge', 'cherry-pick', 'rebase', 'stash', 'worktree'],
            array_keys($table),
        );
        self::assertSame(['__not_abort__'], $table['cherry-pick']);
        self::assertSame(['__not_abort__'], $table['rebase']);
        self::assertContains('apply', $table['stash']);
        self::assertContains('pop', $table['stash']);
        self::assertContains('add', $table['worktree']);
    }

    public function testTheFilterGuardIsWiredAndNeverMutatesArgv(): void
    {
        $src = $this->sourceOf(Git::class);

        // The assembler calls the guard for filter-capable verbs...
        self::assertStringContainsString('guardFilterChannel(', $this->functionBody($src, 'argvWithDisarms'));

        // ...and the guard is SIDE EFFECTS ONLY: it refuses (strict mode) or
        // announces (sticky one-shot notice), never edits the argv. The pin
        // that makes "default users see nothing / byte-identity with the old
        // prelude" structural rather than a one-time behavioral photo: an
        // `-c`-inventing guard is exactly the disarm-the-ruling rejected.
        $guard = $this->functionBody($src, 'guardFilterChannel');
        self::assertStringNotContainsString("'-c'", $guard, 'guardFilterChannel must never add argv overrides');
        self::assertStringNotContainsString('array_merge', $guard);

        // Detection uses the merged config scope (a --local probe would miss
        // include.path/config.worktree lanes) MINUS the user's global/system
        // baseline (the operator's own git-lfs must never be nagged or
        // refused), and the lanes parse the same key shape.
        $lanes = $this->functionBody($src, 'filterDriverLanes');
        self::assertStringContainsString('filterConfigProbe(', $lanes);
        self::assertStringContainsString("'--global'", $lanes);
        self::assertStringContainsString("'--system'", $lanes);
        self::assertStringNotContainsString("'--local'", $lanes);
        $probe = $this->functionBody($src, 'filterConfigProbe');
        self::assertStringContainsString("'config'", $probe);
        self::assertStringContainsString("'--get-regexp'", $probe);
        self::assertStringContainsString('.(clean|smudge|process)', $probe);
        self::assertStringNotContainsString("'--local'", $probe);

        // One-shot latch: the notice has an emitted-flag gate (per instance).
        self::assertStringContainsString('filterNoticeEmitted', $guard);
    }

    public function testRunAndRunPatchRouteThroughTheDisarmingAssembler(): void    {
        $src = $this->sourceOf(Git::class);

        self::assertStringContainsString('Process::run($this->argvWithDisarms($args), null, $this->timeout)', $src);
        self::assertStringContainsString('Process::run($this->argvWithDisarms($args), $hunk, $this->timeout)', $src);
        // The disarm value is `false`, never an empty string (git noise lane)
        // and never `true` (silent keep-ours data loss) — pin the override
        // shape at its construction site.
        self::assertStringContainsString("'.driver=false'", $src);
        // Enumeration scope must be the merged view git itself consults;
        // --local would miss config.worktree (probed on git 2.43).
        self::assertStringNotContainsString("'--local'", $this->functionBody($src, 'mergeDriverDisarms'));
    }

    // ------------------------------------------------------------------ internals

    /**
     * @return array<string,string> "File.php::line" => first-argument slice
     */
    private function spawnSites(): array
    {
        $sites = [];
        foreach ($this->sourceFiles() as $file) {
            /** @var list<\PhpToken> $tokens */
            $tokens = \PhpToken::tokenize((string) file_get_contents($file), TOKEN_PARSE);
            $n      = count($tokens);
            for ($i = 0; $i < $n; $i++) {
                if ($tokens[$i]->id !== T_STRING || $tokens[$i]->text !== 'Process') {
                    continue;
                }
                $j = $this->skipWhitespace($tokens, $i + 1);
                if ($j >= $n || $tokens[$j]->id !== T_DOUBLE_COLON) {
                    continue;
                }
                $j = $this->skipWhitespace($tokens, $j + 1);
                if ($j >= $n || $tokens[$j]->text !== 'run') {
                    continue;
                }
                $sites[basename($file) . '::' . $tokens[$i]->line] = $this->firstArgumentSlice($tokens, $j + 1);
            }
        }

        return $sites;
    }

    /** @param list<\PhpToken> $tokens */
    private function skipWhitespace(array $tokens, int $from): int
    {
        $n = count($tokens);
        while ($from < $n && $tokens[$from]->id === T_WHITESPACE) {
            $from++;
        }

        return $from;
    }

    /**
     * Text of the argument that starts at/after $from's '(' up to the first
     * top-level ',' or the call's closing ')' — nested parens/brackets are
     * followed so inner commas do not cut the slice.
     *
     * @param list<\PhpToken> $tokens
     */
    private function firstArgumentSlice(array $tokens, int $from): string
    {
        $n     = count($tokens);
        $slice = '';
        $depth = 0;
        $seen  = false;
        for ($i = $from; $i < $n; $i++) {
            $text = $tokens[$i]->text;
            if (!$seen) {
                if ($text === '(') {
                    $seen = true;
                }
                continue;
            }
            if ($text === '(' || $text === '[' || $text === '{') {
                $depth++;
            } elseif ($text === ')' || $text === ']' || $text === '}') {
                if ($depth === 0) {
                    break; // the call's own closing paren
                }
                $depth--;
            } elseif ($text === ',' && $depth === 0) {
                break;
            }
            $slice .= $text;
        }

        return $slice;
    }

    /** @return list<string> every .php under src/, recursively */
    private function sourceFiles(): array
    {
        $dir = dirname((string) (new \ReflectionClass(Git::class))->getFileName());
        /** @var list<string> $files */
        $files = [];
        $walk  = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($walk as $entry) {
            if ($entry->isFile() && strtolower($entry->getExtension()) === 'php') {
                $files[] = $entry->getPathname();
            }
        }
        sort($files);
        self::assertNotEmpty($files, 'src/ walk found nothing');
        // The walk must actually reach nested directories (review m-2): a
        // top-level-only glob reads like a pass while src/Support/** is
        // invisible to it. Pin that a known nested source file is enumerated.
        self::assertContains(
            $dir . '/Support/Version.php',
            $files,
            'the src/ walk is not recursive — nested spawn sites would evade the roster',
        );

        return $files;
    }

    /** @param class-string $class */
    private function sourceOf(string $class): string
    {
        return (string) file_get_contents((new \ReflectionClass($class))->getFileName());
    }

    /** Brace-balanced body of one named method, from its opening brace. */
    private function functionBody(string $src, string $name): string
    {
        $at = strpos($src, 'function ' . $name);
        self::assertNotFalse($at, "method {$name} vanished from Git.php — the guard roster must be revisited");
        $open = strpos($src, '{', (int) $at);
        self::assertNotFalse($open);
        $depth = 0;
        $len   = strlen($src);
        for ($i = (int) $open; $i < $len; $i++) {
            if ($src[$i] === '{') {
                $depth++;
            } elseif ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($src, (int) $open, $i - (int) $open + 1);
                }
            }
        }
        self::fail("unbalanced braces reading {$name}");
    }
}
