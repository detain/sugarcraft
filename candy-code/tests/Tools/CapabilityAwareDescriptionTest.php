<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Tools\BuiltIn\Glob;
use SugarCraft\Code\Tools\BuiltIn\Grep;
use SugarCraft\Code\Tools\Tool;

/**
 * The boot-once `rg`/`fd` capability probe and the two descriptions it feeds
 * (plan P9.S2, rulings R-A..R-E).
 *
 * WHAT IS BEING HELD FIXED, in one sentence: a description is allowed to gain an
 * `rg`/`fd` clause ONLY when the instance was built with the capability true, so
 * the two frozen constants below are today's bytes and every existing pin in
 * `ToolDescriptionGuidanceTest`/`BuiltInToolTest` keeps rendering that same
 * absent half. They are asserted with `assertSame` on the WHOLE string, because
 * the clause is appended to prose that other tests match substrings against —
 * `assertStringContainsString` here would pass even if the absent half drifted.
 *
 * WHY THE SANDBOX PATH PARAMETER RATHER THAN `putenv('PATH', ...)`: the seam is
 * `DetectsCapabilities::capabilityPresent()`'s second argument, so the found and
 * absent answers are both derived from a directory this file creates and removes.
 * Mutating the process `PATH` instead would leak into every sibling test that
 * resolves a binary, and the memo would then hold a value the real host never
 * had. The bypass is asserted explicitly below: a sandbox answer must not move
 * the memoized host answer.
 *
 * WHY NOTHING HERE SPAWNS A PROCESS, WHICH IS ALSO WHY THIS FILE GOING GREEN IS
 * ITSELF EVIDENCE: the probe is a `stat()` walk, so it cannot warn on a host
 * without the binary; a `proc_open(['rg', '--version'])` probe would emit a PHP
 * warning, and `phpunit.xml` sets `failOnWarning`, so the suite would go red on
 * every container that has no `rg` — which is the CI image today. A false claim
 * in a description is the other failure mode this file is aimed at, so every
 * clause checked here says what the code does: `Grep::buildCommand()` compiles
 * a `grep -rn` line for the capability-absent instance and the SHL-4 rg line —
 * engine-conditional prose, both halves true of the branch that renders them —
 * while `Glob::match()` still walks with PHP iterators even when the model is
 * being told the shell tools exist.
 *
 * No tree walk: the roster in the last test comes from {@see Bootstrap::tools()},
 * and the probe file is located through reflection rather than path arithmetic.
 */
final class CapabilityAwareDescriptionTest extends TestCase
{
    private const GREP_ABSENT = <<<'GREP'
        Search for a pattern in files, recursively. The pattern is a GNU basic regular expression — this runs `grep -rn`, not PCRE — so `|`, `+`, `?`, `(`, `)`, `{` and `}` match themselves unless backslash-escaped. Use include to scope by filename glob (e.g. "*.php"). Finding nothing is a normal result, not an error; only grep itself failing is reported as one. Skips .git, vendor, node_modules, .phpunit.cache and anything the project's .gitignore excludes; pass include_ignored: true to search those too, except that a walk never enters .git and never opens .env, .env.* or .envrc files (the .env.example-style templates included; Read those directly) or private-key files (*.pem, *.key, id_rsa-style SSH keys; the .pub halves are searched). path may also name a saved tool-output file a truncated result pointed you to.
        GREP;

    private const GLOB_ABSENT = <<<'GLOB'
        Find files matching a glob pattern (e.g. "**/*.php") under a base directory. Reach for this instead of a shell `find`/`ls` when you know how the files are named but not where they live: `**` matches across directory levels, and matches come back one path per line, followed by notes naming anything pruned, gitignored, not followed or clipped. A recursive `**` walk skips .git, vendor, node_modules, .phpunit.cache by default; naming one of those directories in the pattern (e.g. "vendor/**/*.php") or pointing path inside it searches there instead.
        GLOB;

    /**
     * Directories created by a test; emptied in tearDown so a failure mid-test
     * cannot leave an executable named `rg` on disk for anybody else to find.
     */
    private array $sandbox = [];

    protected function tearDown(): void
    {
        foreach ($this->sandbox as $entry) {
            if (is_link($entry) || is_file($entry)) {
                @unlink($entry);

                continue;
            }

            if (is_dir($entry)) {
                @rmdir($entry);
            }
        }

        $this->sandbox = [];
    }

    public function testGrepConstructedBareRendersTheFrozenAbsentText(): void
    {
        $this->assertSame(self::GREP_ABSENT, (new Grep())->description());
        // R-B: absent is the CONSTRUCTOR's default, not a flag some harness
        // flips, so saying it out loud renders the identical bytes.
        $this->assertSame(self::GREP_ABSENT, (new Grep(rgAvailable: false))->description());
        $this->assertStringNotContainsString('`rg`', (new Grep())->description());
    }

    public function testGlobConstructedBareRendersTheFrozenAbsentText(): void
    {
        $this->assertSame(self::GLOB_ABSENT, (new Glob())->description());
        $this->assertSame(self::GLOB_ABSENT, (new Glob(fdAvailable: false))->description());
        $this->assertStringNotContainsString('`fd`', (new Glob())->description());
    }

    public function testGrepNamesRgOnlyWhenToldTheHostHasItAndKeepsEveryOtherFact(): void
    {
        $present = (new Grep(rgAvailable: true))->description();

        $this->assertStringContainsString('`rg` is on PATH on this host', $present);
        $this->assertStringContainsString('this tool runs it natively', $present);
        // SHL-4 REVERSED the polarity of the old prefix pin: the dialect
        // sentence is now a property of the engine, so the absent text is no
        // longer a strict prefix of the present text — the lead clause, the
        // skips/secret tail and the spill clause are what stay byte-stable
        // across both renderings, and they are asserted in both.
        $this->assertStringStartsWith('Search for a pattern in files, recursively. The pattern is ', $present);
        $this->assertStringContainsString('extended regular expression', $present);
        $this->assertStringContainsString('the ripgrep engine, not PCRE', $present);
        $this->assertStringContainsString('are operators as written', $present);
        $this->assertStringContainsString('GNU basic', $present);
        $this->assertStringContainsString('Finding nothing is a normal result', $present);
        $this->assertStringContainsString('include_ignored: true', $present);
        $this->assertStringContainsString('never opens .env', $present);
        $this->assertStringContainsString('path may also name a saved tool-output file', $present);
        // The honest half of the claim changed shape with the engine switch:
        // the tool names the engine IT uses AND names the other-dialect
        // fallback, so a pattern written against this text reads correctly on
        // a host without rg too.
        $this->assertStringContainsString('falls back to `grep -rn`', $present);
        $this->assertStringNotContainsString('This tool still runs `grep`', $present);
        // The pre-SHL-4 advisory sent the model to run rg itself through Bash;
        // with the tool AS the rg engine that advice would mislead.
        $this->assertStringNotContainsString('you can run `rg` yourself through Bash', $present);
        // Each tool advertises only its own capability.
        $this->assertStringNotContainsString('`fd`', $present);
    }

    public function testGlobNamesFdOnlyWhenToldTheHostHasItAndKeepsEveryOtherFact(): void
    {
        $present = (new Glob(fdAvailable: true))->description();

        $this->assertStringStartsWith('Find files matching a glob pattern', $present);
        $this->assertStringContainsString('`fd` is on PATH on this host', $present);
        $this->assertStringContainsString('`**` matches across directory levels', $present);
        $this->assertStringContainsString('one path per line', $present);
        $this->assertStringContainsString('instead of a shell `find`/`ls`', $present);
        $this->assertStringContainsString('A recursive `**` walk skips', $present);
        // The honest half: the fd offer is a shell command, not a switch.
        $this->assertStringContainsString('This tool itself walks the tree with PHP iterators', $present);
        $this->assertStringNotContainsString('`rg`', $present);
        // R-B, third time: the prune-off render must not gain the word either.
        $this->assertStringNotContainsString('skips', (new Glob(prunedDirs: [], fdAvailable: false))->description());
    }

    public function testTheProbeAnswersTheSameQuestionTwiceWithTheSameBytes(): void
    {
        // Deliberately no assertion about WHICH answer the host gives: this file
        // must pass on a container without `rg` and on a workstation with it.
        $rg = Bootstrap::capabilityPresent('rg');
        $fd = Bootstrap::capabilityPresent('fd');

        $this->assertSame($rg, Bootstrap::capabilityPresent('rg'));
        $this->assertSame($fd, Bootstrap::capabilityPresent('fd'));
        $this->assertIsBool($rg);
        $this->assertIsBool($fd);
    }

    public function testTheProbeFindsAnExecutableInAnExplicitPathList(): void
    {
        $dir = $this->sandbox('found');
        $this->makeExecutable($dir . '/rg');

        $this->assertTrue(Bootstrap::capabilityPresent('rg', $dir));
        $this->assertFalse(Bootstrap::capabilityPresent('fd', $dir), 'a directory holding only rg does not have fd');

        // The walk continues past a directory that does not hold the name.
        $other = $this->sandbox('other');
        $this->makeExecutable($other . '/fd');
        $this->assertTrue(Bootstrap::capabilityPresent('fd', $dir . PATH_SEPARATOR . $other));
    }

    public function testTheProbeCallsAbsentWhatAnExecutableIsNot(): void
    {
        $dir = $this->sandbox('edges');
        $this->makeFile($dir . '/plain', 0644);
        mkdir($dir . '/rg');
        $this->sandbox[] = $dir . '/rg';
        $missing = $dir . '-no-such-dir';

        $this->assertFalse(Bootstrap::capabilityPresent('plain', $dir), 'not executable is not installed');
        $this->assertFalse(Bootstrap::capabilityPresent('rg', $dir), 'a directory named rg is not the binary');
        $this->assertFalse(Bootstrap::capabilityPresent('rg', $missing), 'a PATH entry that does not exist');
        $this->assertFalse(Bootstrap::capabilityPresent('rg', ''), 'an empty PATH has nothing on it');
        $this->assertFalse(Bootstrap::capabilityPresent('', $dir), 'the empty name is no binary');
        // An empty entry means "current directory" in POSIX; this walk declines
        // it, so the answer cannot depend on where the process happens to sit.
        $this->assertFalse(Bootstrap::capabilityPresent('rg', $dir . PATH_SEPARATOR . ''));
    }

    public function testASandboxAnswerLeavesTheHostAnswerAlone(): void
    {
        $before = Bootstrap::capabilityPresent('rg');
        $dir = $this->sandbox('memo');
        $this->makeExecutable($dir . '/rg');

        $this->assertTrue(Bootstrap::capabilityPresent('rg', $dir));
        $this->assertSame($before, Bootstrap::capabilityPresent('rg'));
    }

    public function testASecondHostAskIsAMemoHitEvenAfterTheBinaryVanishes(): void
    {
        // Pins the trait's claim that "every later ask reads this array" — mutate
        // the `??=` on the memo line to a plain `=` (recompute every call) and
        // the still-true assertion below goes red.
        //
        // WHY a path-shaped NAME instead of putenv('PATH') or a reflection peek:
        // the trait documents a path-shaped argument as a first-class host-form
        // question ("it is a path, and is answered as one, exactly as the
        // precedent does"), so it walks no PATH, reads no injected environment,
        // and lands on the very line whose memoization is claimed. The sandbox
        // `$pathList` form cannot be used for the pin because it deliberately
        // bypasses the memo; a ReflectionProperty on `$hostCapabilities` would
        // pin the array's name and shape instead of its behaviour. The name is
        // unique per run, so the one memo entry this creates can never be asked
        // by any sibling test.
        $dir = $this->sandbox('bootmemo');
        $binary = 'p9s2-memo-' . getmypid() . '-' . bin2hex(random_bytes(6));
        $this->makeExecutable($dir . '/' . $binary);

        // Positive control: a fresh walk sees the file (this never touches the memo).
        $this->assertTrue(Bootstrap::capabilityPresent($binary, $dir));

        // First host ask: no $pathList, so the answer comes from the memo line —
        // a stat walk, and the entry is stored under this unique key.
        $this->assertTrue(Bootstrap::capabilityPresent($dir . '/' . $binary));

        if (!@unlink($dir . '/' . $binary)) {
            $this->fail("could not delete the probe target at {$dir}/{$binary}");
        }

        // Second host ask: the file is gone, so any answer from a stat says false.
        // True can only come out of `self::$hostCapabilities` — the hash lookup,
        // not a second walk.
        $this->assertTrue(
            Bootstrap::capabilityPresent($dir . '/' . $binary),
            'the second host ask must read the boot memo, not re-walk the (now deleted) target'
        );

        // The sandbox form on the same deleted file says false: proves the true
        // above came from the memo rather than a stale stat, and that the
        // documented bypass discipline still holds — a $pathList answer is always
        // freshly walked, never memo-served.
        $this->assertFalse(
            Bootstrap::capabilityPresent($binary, $dir),
            'the sandbox form must not read the host memo'
        );
    }

    public function testToolsThreadsEachFlagToItsOwnToolAndDefaultsToNeither(): void
    {
        $root = $this->sandbox('wiring');

        $both = $this->descriptions(Bootstrap::tools($root, null, null, null, true, true));
        $this->assertStringContainsString('`rg` is on PATH', $both['Grep']);
        $this->assertStringContainsString('`fd` is on PATH', $both['Glob']);

        $neither = $this->descriptions(Bootstrap::tools($root));
        $this->assertStringNotContainsString('`rg`', $neither['Grep']);
        $this->assertStringNotContainsString('`fd`', $neither['Glob']);
        $this->assertSame(self::GREP_ABSENT, $neither['Grep']);

        $onlyRg = $this->descriptions(Bootstrap::tools($root, null, null, null, true, false));
        $this->assertStringContainsString('`rg` is on PATH', $onlyRg['Grep']);
        $this->assertStringNotContainsString('`fd`', $onlyRg['Glob']);
    }

    public function testTheProbeReadsNoEnvironmentVariableBeyondPathAndSpawnsNothing(): void
    {
        // R-A/R-B: a new `CANDY_CODE_`-prefixed read would need an
        // `EnvRosterDriftTest` row and a docs/ENVIRONMENT.md entry — both out of
        // this step's file ceiling, so the ceiling is pinned here instead.
        $file = (new ReflectionMethod(Bootstrap::class, 'capabilityPresent'))->getFileName();
        $this->assertIsString($file);
        $code = $this->strippedOfComments((string) file_get_contents((string) $file));

        $this->assertSame(1, substr_count($code, 'getenv('), 'PATH is the only environment read');
        $this->assertStringContainsString("getenv('PATH')", $code);
        $this->assertStringNotContainsString('CANDY_CODE_', $code);
        foreach (['proc_open', 'exec(', 'shell_exec', 'popen', 'passthru', 'pcntl_fork'] as $spawn) {
            $this->assertStringNotContainsString($spawn, $code, "the probe must not reach {$spawn}");
        }
    }

    /**
     * Concatenate every token that is not a comment.
     *
     * The scan above is about what the file DOES, and this trait's whole point is
     * a doc-block that spells out the `proc_open` hazard it refuses to use — so a
     * raw substring check would fail on the warning rather than on the call.
     */
    private function strippedOfComments(string $source): string
    {
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    /**
     * @param list<Tool> $tools
     *
     * @return array<string, string>
     */
    private function descriptions(array $tools): array
    {
        $byName = [];
        foreach ($tools as $tool) {
            if ($tool->name() === 'Grep' || $tool->name() === 'Glob') {
                $byName[$tool->name()] = $tool->description();
            }
        }

        $this->assertSame(['Glob', 'Grep'], array_keys($byName), 'both tools must be in the wired set');

        return $byName;
    }

    private function sandbox(string $label): string
    {
        $dir = sys_get_temp_dir() . '/sc-p9s2-' . $label . '-' . bin2hex(random_bytes(6));
        if (!mkdir($dir, 0777) && !is_dir($dir)) {
            $this->fail("could not create the {$label} sandbox at {$dir}");
        }

        $this->sandbox[] = $dir;

        return $dir;
    }

    private function makeFile(string $path, int $mode): void
    {
        file_put_contents($path, "#!/bin/sh\nexit 0\n");
        chmod($path, $mode);
        $this->sandbox[] = $path;
    }

    private function makeExecutable(string $path): void
    {
        $this->makeFile($path, 0755);
    }
}
