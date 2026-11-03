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
 * - the per-invocation driver disarm stops emitting its `=false` overrides.
 *
 * Roster genre, fleet-consistent (StderrEmitterCensusTest upstream): the
 * expected call-site COUNT is pinned so new spawn sites force a deliberate
 * revisit instead of slipping through a "some subset of sites is guarded"
 * reading.
 */
final class GitSpawnPreludeGuardTest extends TestCase
{
    /** Every Process::run() spawn site in src/, by design. */
    private const EXPECTED_SPAWN_SITES = 5;

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

    public function testRunAndRunPatchRouteThroughTheDisarmingAssembler(): void
    {
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

    /** @return list<string> */
    private function sourceFiles(): array
    {
        $dir   = dirname((string) (new \ReflectionClass(Git::class))->getFileName()) . '/';
        $files = glob($dir . '*.php');
        self::assertIsArray($files);
        self::assertNotEmpty($files, 'src/ walk found nothing');

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
