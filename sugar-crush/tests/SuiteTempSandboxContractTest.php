<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Support\ToolIpcFiles;

/**
 * What `tests/bootstrap.php`'s `TMPDIR` sandbox actually covers, pinned.
 *
 * These are stdlib behaviours rather than this tree's behaviour, so none of
 * them can be settled by reading `src/`, and CI runs PHP 8.4 as well as 8.3 —
 * a change in how the interpreter resolves its temporary directory would
 * silently move every child's temp files back onto the machine's real one.
 * Asserting them here is what makes 8.4 answer the question this box cannot.
 *
 *  1. `sys_get_temp_dir()` CACHES ON THE FIRST RESOLUTION. Once anything has
 *     asked, a later `putenv('TMPDIR=…')` cannot move it. True on every
 *     interpreter and every extension set.
 *  2. AND IT READS `getenv('TMPDIR')` AT THAT MOMENT, not at startup. So on an
 *     interpreter where nothing has resolved yet, `putenv('TMPDIR=…')` DOES
 *     move it. The two together mean the answer is decided by ORDER.
 *  3. A CHILD picks up an inherited `TMPDIR`, because it resolves the variable
 *     after inheriting it. Without this the sandbox does nothing at all, and
 *     claims 1 and 2 both read as a pass against a probe that cannot see a
 *     change.
 *  4. THEREFORE THE BOOTSTRAP'S STATEMENT ORDER IS THE CONTRACT: it resolves
 *     the real temp directory before it exports the sandbox, which warms the
 *     cache with the real one on ANY extension set.
 *
 * ## What this used to say, and why it was red on CI for days
 *
 * Claim 1 used to read "`putenv('TMPDIR=…')` does NOT move `sys_get_temp_dir()`
 * in the process that calls it", unconditionally, with a probe asserting it
 * even when the `putenv()` came BEFORE the first call. That is false, and this
 * test was correctly reporting it false — the failure was never flaky and never
 * environmental noise. MEASURED here, PHP 8.3.6, `TMPDIR=/tmp` in the launch
 * environment and `$T` a real directory:
 *
 *   php    -r 'putenv("TMPDIR=$T"); echo sys_get_temp_dir();'   -> /tmp
 *   php -n -r 'putenv("TMPDIR=$T"); echo sys_get_temp_dir();'   -> $T
 *
 * Bisected one `/etc/php/8.3/cli/conf.d/*.ini` at a time, the ONLY extension on
 * this box that produces the first answer is `swoole`, which resolves the temp
 * directory during module startup so user code always finds the cache warm.
 * Neither opcache nor pcov does it. CI's runner has no swoole, so its cache is
 * cold and the old claim 1 was false there and nowhere else. The old test was
 * not asserting a property of PHP; it was asserting a property of this box's
 * extension list, which is why nothing in `src/` could explain it.
 *
 * ## The consequence, which is why E242's proposed re-key was still not made
 *
 * {@see ToolIpcFiles::reserve()} names payloads under `sys_get_temp_dir()`, so
 * the suite's OWN in-process payloads never enter the sandbox at any key —
 * re-keying it by checkout would not have moved the file E242 saw two processes
 * collide on, because that path is `sys_get_temp_dir()`-based too.
 *
 * THAT CONCLUSION SURVIVES THE CORRECTION, but its reason changes and the new
 * reason is weaker in a way worth stating: it used to rest on claim 1 holding
 * unconditionally, and it now rests on claim 4 — an ordering the bootstrap
 * controls. It is true by construction on every build rather than by accident
 * on this one, which is strictly better, but it is now something a refactor can
 * break. {@see testTheBootstrapResolvesTheRealTempDirectoryBeforeItExportsTheSandbox()}
 * is why that refactor arrives red.
 */
final class SuiteTempSandboxContractTest extends TestCase
{
    /**
     * Claim 1 — the half that IS an unconditional interpreter guarantee.
     *
     * Asserted on the ambient interpreter AND on `-n`, and required to agree,
     * because "deterministic on any extension set" is exactly what the old
     * version of this test failed to be.
     */
    public function testResolutionIsCachedOnTheFirstCallSoALaterPutenvCannotMoveIt(): void
    {
        $target = $this->scratchDirectory('late');

        try {
            $code = '$before = sys_get_temp_dir();'
                . 'putenv("TMPDIR=" . ' . \var_export($target, true) . ');'
                . 'echo json_encode(["before" => $before, "after" => sys_get_temp_dir()]);';

            foreach (['ambient' => [], 'cold (-n)' => ['-n']] as $label => $flags) {
                $probe = $this->phpProbe($code, null, $flags);

                $this->assertSame(
                    $probe['before'],
                    $probe['after'],
                    $label . ': putenv() moved sys_get_temp_dir() after it had already been resolved',
                );
                $this->assertNotSame($target, $probe['after'], $label);
            }

            // KNOWN-POSITIVE for the same probe: the variable set in the
            // child's LAUNCH environment does move it. Without this, the
            // assertions above are satisfied by a probe that cannot see a
            // change at all.
            $inherited = $this->phpProbe('echo json_encode(["tmp" => sys_get_temp_dir()]);', ['TMPDIR' => $target]);
            $this->assertSame($target, $inherited['tmp'], 'the probe cannot observe a moved temp directory at all');
        } finally {
            @\rmdir($target);
        }
    }

    /**
     * Claims 2 and 4 — the mechanism, and why the bootstrap's order is load-bearing.
     *
     * Both children run the bootstrap's own two statements, one in each order,
     * on the SAME interpreter configuration. They must DISAGREE: that
     * disagreement is the entire reason `tests/bootstrap.php` may not be
     * reordered, and it is invisible on a warm interpreter.
     *
     * `-n` IS THE COLD CONFIGURATION and is chosen deliberately rather than
     * probed for: it is CI's shape, and it is a fixed flag rather than a
     * reading of whatever this box happens to load. On a hypothetical build
     * that statically links a warmer this reds — correctly, because the
     * bootstrap's comment block would then need re-measuring.
     */
    public function testOnAColdInterpreterTheOrderOfResolveAndExportDecidesTheAnswer(): void
    {
        $launch = $this->scratchDirectory('launch');
        $target = $this->scratchDirectory('target');

        try {
            $quoted = \var_export($target, true);
            $env = ['TMPDIR' => $launch];

            // The bootstrap's order: resolve, THEN export.
            $ordered = $this->phpProbe(
                '$real = sys_get_temp_dir();'
                . 'putenv("TMPDIR=" . ' . $quoted . ');'
                . 'echo json_encode(["real" => $real, "after" => sys_get_temp_dir()]);',
                $env,
                ['-n'],
            );

            $this->assertSame($launch, $ordered['real'], 'the cold child did not resolve its inherited TMPDIR');
            $this->assertSame(
                $launch,
                $ordered['after'],
                'resolve-then-export did not hold the resolved directory, so claim 1 is broken on a cold cache',
            );

            // Reversed: export, THEN resolve. This is what the bootstrap would
            // do if the putenv were hoisted, and what CI's interpreter does.
            $reversed = $this->phpProbe(
                'putenv("TMPDIR=" . ' . $quoted . ');'
                . 'echo json_encode(["after" => sys_get_temp_dir()]);',
                $env,
                ['-n'],
            );

            $this->assertSame(
                $target,
                $reversed['after'],
                'export-then-resolve did NOT move sys_get_temp_dir() on a cold interpreter. Either PHP stopped '
                    . 'reading getenv("TMPDIR") at resolution time, or this build warms the cache before user code '
                    . 'even under -n (on this box only swoole does that, and -n disables it) — re-measure the '
                    . 'ordering block in tests/bootstrap.php before touching this test',
            );

            $this->assertNotSame(
                $ordered['after'],
                $reversed['after'],
                'the two orderings agree, so this test cannot see the hazard the bootstrap order exists to avoid',
            );
        } finally {
            @\rmdir($launch);
            @\rmdir($target);
        }
    }

    /**
     * Claim 4, asserted from both ends.
     *
     * In-process, because that is the property the suite actually depends on;
     * and over the bootstrap's own token stream, because on a WARM interpreter
     * — which is what a developer on this box runs — the in-process half stays
     * green even if the `putenv()` is hoisted above the resolution. Only the
     * source-order half reds here, and only the cold-cache test above explains
     * why it must.
     */
    public function testTheBootstrapResolvesTheRealTempDirectoryBeforeItExportsTheSandbox(): void
    {
        // 1. In-process: the value the bootstrap resolved BEFORE it exported is
        //    still this process's answer, and it is not the sandbox.
        $captured = $GLOBALS['__sugarcrushRealTempDir'] ?? null;
        $this->assertIsString($captured, 'the bootstrap did not publish the temp directory it resolved');
        $this->assertSame(
            $captured,
            \sys_get_temp_dir(),
            'this process\'s temp directory is no longer the one the bootstrap resolved before exporting TMPDIR',
        );
        $this->assertNotSame(
            (string) getenv('TMPDIR'),
            \sys_get_temp_dir(),
            'the export moved this process onto the sandbox: every in-process temp path has moved with it',
        );

        // AN ABSOLUTE ANCHOR, because the two assertions above are only
        // SELF-CONSISTENT. MEASURED while building this test: a bootstrap that
        // exports before it resolves corrupts `$captured` and
        // `sys_get_temp_dir()` together and derives the sandbox from the
        // corrupted value, so all three still agree with each other and both
        // assertions above stay green on a cold interpreter.
        //
        // So reconstruct what this process WOULD have resolved at launch, in a
        // child handed the launch environment's TMPDIR and nothing else. That
        // value is recoverable after the fact because `/proc/self/environ` is
        // the launch environment (and `$_SERVER`, the fallback, is populated
        // once at startup) — neither follows a `putenv()`.
        $launch = $this->launchTmpdir();
        $atLaunch = $this->phpProbe(
            'echo json_encode(["tmp" => sys_get_temp_dir()]);',
            $launch === null ? [] : ['TMPDIR' => $launch],
        );
        $this->assertSame(
            $atLaunch['tmp'],
            \sys_get_temp_dir(),
            'this process resolved a DIFFERENT temp directory than its launch environment names, which means '
                . 'the bootstrap\'s export reached the resolution. Every in-process sys_get_temp_dir() path in '
                . 'the suite — ToolIpcFiles::reserve() names included — has moved into the sandbox.',
        );

        // 2. Source order, over the bootstrap's real token stream — comments
        //    and doc-blocks are separate token types, so prose naming either
        //    call cannot satisfy this.
        $tokens = \token_get_all((string) \file_get_contents(__DIR__ . '/bootstrap.php'));

        $resolveAt = null;
        $exportAt = null;

        foreach ($tokens as $i => $token) {
            if (!\is_array($token) || $token[0] !== \T_STRING) {
                continue;
            }

            if ($resolveAt === null && $token[1] === 'sys_get_temp_dir') {
                $resolveAt = $i;
            }

            if ($exportAt === null && $token[1] === 'putenv' && $this->firstArgumentBeginsWithTmpdir($tokens, $i)) {
                $exportAt = $i;
            }
        }

        $this->assertIsInt($resolveAt, 'no sys_get_temp_dir() call in tests/bootstrap.php');
        $this->assertIsInt($exportAt, 'no putenv("TMPDIR=…") call in tests/bootstrap.php');
        $this->assertLessThan(
            $exportAt,
            $resolveAt,
            'tests/bootstrap.php exports TMPDIR before it resolves sys_get_temp_dir(). On this box swoole hides '
                . 'the consequence; on CI, which has no swoole, the whole suite\'s in-process temp paths move into '
                . 'the sandbox. See testOnAColdInterpreterTheOrderOfResolveAndExportDecidesTheAnswer().',
        );
    }

    /**
     * The positive half. Claim 1 on its own is also what a dead probe reports,
     * and a sandbox nothing picks up would satisfy it perfectly.
     */
    public function testAChildProcessDoesResolveTheInheritedSandbox(): void
    {
        $sandbox = (string) getenv('TMPDIR');
        $this->assertNotSame('', $sandbox, 'the bootstrap did not export a sandbox at all');
        $this->assertDirectoryExists($sandbox);
        $this->assertNotSame(
            \sys_get_temp_dir(),
            $sandbox,
            'the sandbox IS the real temp directory, so this test cannot tell them apart',
        );

        // proc_open with no $env inherits this process's environment, putenv
        // included - the same route every spawning test takes.
        $child = $this->phpProbe('echo json_encode(["tmp" => sys_get_temp_dir()]);');

        $this->assertSame($sandbox, $child['tmp'], 'a spawned child did not inherit the sandbox');
    }

    /**
     * The suite's own IPC payloads are named under the real temp directory,
     * not the sandbox — so the sandbox's key cannot be what keeps two
     * concurrent suites from colliding on one of them. Their uniqueness comes
     * from `random_bytes()` in the name instead.
     */
    public function testInProcessPayloadReservationsDoNotLandInTheSandbox(): void
    {
        $path = ToolIpcFiles::reserve('sc_chat_tool_', 'json');

        $this->assertStringStartsWith(\sys_get_temp_dir() . '/', $path);
        $this->assertStringStartsNotWith((string) getenv('TMPDIR') . '/', $path);
        // Nothing is created by reserve(); assert that too, so a future change
        // that starts touching disk here is caught rather than leaking files.
        $this->assertFileDoesNotExist($path);
    }

    /**
     * `TMPDIR` as this process was LAUNCHED with it, or null if it was not in
     * the launch environment at all. Deliberately not `getenv()`, which the
     * bootstrap has already overwritten by the time any test runs.
     */
    private function launchTmpdir(): ?string
    {
        $environ = @\file_get_contents('/proc/self/environ');

        if (\is_string($environ) && $environ !== '') {
            foreach (\explode("\0", $environ) as $entry) {
                if (\str_starts_with($entry, 'TMPDIR=')) {
                    return \substr($entry, 7);
                }
            }

            return null;
        }

        // No procfs. `$_SERVER` is populated once at startup and does not
        // follow a `putenv()` either — but only when `variables_order`
        // contains `S`, so prove it is populated rather than reading an empty
        // array as "TMPDIR was unset at launch".
        self::assertArrayHasKey(
            'argv',
            $_SERVER,
            'neither /proc/self/environ nor a populated $_SERVER is available, so the launch environment '
                . 'cannot be recovered and this assertion would silently compare against the wrong value',
        );

        return isset($_SERVER['TMPDIR']) ? (string) $_SERVER['TMPDIR'] : null;
    }

    /**
     * Distinct from the real temp dir AND from the sandbox the child already
     * inherits, or "it did not move" is true for the wrong reason.
     */
    private function scratchDirectory(string $tag): string
    {
        $path = \sys_get_temp_dir() . '/sc_tmpdir_probe_' . $tag . '_' . \getmypid()
            . '_' . \uniqid((string) \getmypid(), true);
        self::assertTrue(\mkdir($path, 0700, true), 'could not create the probe directory ' . $path);

        return $path;
    }

    /**
     * Does the `putenv(` whose name token is at $at open with a `'TMPDIR=…'`
     * literal? Fails the run rather than guessing if the argument is not a
     * plain string — a computed name would mean this census can no longer see
     * the export it exists to locate.
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function firstArgumentBeginsWithTmpdir(array $tokens, int $at): bool
    {
        $count = \count($tokens);

        for ($i = $at + 1; $i < $count; $i++) {
            $token = $tokens[$i];

            if (\is_array($token) && \in_array($token[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }

            if ($token !== '(') {
                return false;
            }

            for ($j = $i + 1; $j < $count; $j++) {
                $arg = $tokens[$j];

                if (\is_array($arg) && \in_array($arg[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                    continue;
                }

                return \is_array($arg)
                    && $arg[0] === \T_CONSTANT_ENCAPSED_STRING
                    && \str_starts_with(\trim($arg[1], "'\""), 'TMPDIR=');
            }

            return false;
        }

        return false;
    }

    /**
     * @param array<string, string>|null $env   launch environment, or null to inherit
     * @param list<string>               $flags interpreter flags, before `-r`
     * @return array<string, string>
     */
    private function phpProbe(string $code, ?array $env = null, array $flags = []): array
    {
        $process = proc_open(
            [PHP_BINARY, ...$flags, '-r', $code],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env,
        );
        self::assertIsResource($process);

        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $decoded = json_decode($out, true);
        self::assertIsArray($decoded, "the probe reported nothing usable:\n" . $out . $err);

        /** @var array<string, string> $decoded */
        return $decoded;
    }
}
