<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests;

use PHPUnit\Framework\TestCase;

/**
 * THE BOOTSTRAP ACTUALLY RUNS THE ROOT REFUSAL — pinned from the source side
 * (the call exists, is the first executable statement after the autoload, and
 * exits non-zero on a refusal) and from the behaviour side with the half this
 * suite can really drive: the `TMPDIR=/` arm fires at ANY uid, so a child
 * bootstrap with `TMPDIR=/` in a hand-built environment must exit 1 naming the
 * refusal, without this process or the child touching a byte of anything.
 *
 * The euid-0 arm is deliberately NOT simulated — no test in this tree runs
 * setuid machinery, and the polarities of the decision itself live in
 * {@see \SugarCraft\Code\Tests\Support\RootRunGuardTest}. What this file owns
 * is that the decision is WIRED: neuter the call in `tests/bootstrap.php` and
 * the child test here goes green-on-lie? No — it goes RED, because the child
 * proceeds to mkdir and render and never exits 1. That is the discrimination.
 *
 * The token-stream assertions mirror the house shape
 * ({@see \SugarCraft\Code\Tests\SuiteTempSandboxContractTest} walks the same
 * file for the same reason): comments naming the API cannot satisfy them.
 */
final class SuiteRootRunRefusalTest extends TestCase
{
    private function bootstrapTokens(): array
    {
        return token_get_all((string) file_get_contents( __DIR__ . '/bootstrap.php'));
    }

    /** Index of the first token whose text is $name (T_STRING only). */
    private function firstToken(array $tokens, string $name): ?int
    {
        foreach ($tokens as $i => $token) {
            if (\is_array($token) && $token[0] === T_STRING && $token[1] === $name) {
                return $i;
            }
        }

        return null;
    }

    /** Index of the first `'…'` literal whose CONTENT is $name. */
    private function firstStringToken(array $tokens, string $name): ?int
    {
        foreach ($tokens as $i => $token) {
            if (\is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING && $token[1] === "'" . $name . "'") {
                return $i;
            }
        }

        return null;
    }

    public function testTheBootstrapAsksTheGuardBeforeItCapturesTheLaunchHome(): void
    {
        $tokens = $this->bootstrapTokens();

        $guardAt = $this->firstToken($tokens, 'suiteRefusal');
        // The capture stores into a $GLOBALS STRING key, so the marker is a
        // string literal, not a T_STRING — a comment or a rename of the API
        // call cannot satisfy either half.
        $captureAt = $this->firstStringToken($tokens, '__candyCodeLaunchHomeEnv');
        $exportAt = $this->firstToken($tokens, 'putenv');

        self::assertIsInt($guardAt, 'tests/bootstrap.php no longer calls RootRunGuard::suiteRefusal() — the first executable statement after the autoload must be the root refusal');
        self::assertIsInt($captureAt, 'the ISS-125 launch-HOME capture is gone, which invalidates the ordering this test exists for');
        self::assertLessThan($captureAt, $guardAt, 'the root refusal must precede every environment capture and every filesystem touch');
        self::assertIsInt($exportAt);
        self::assertLessThan($exportAt, $guardAt, 'the root refusal must precede the first putenv');
    }

    public function testARefusalExitsTheProcessRatherThanWarning(): void
    {
        $source = (string) file_get_contents( __DIR__ . '/bootstrap.php');

        self::assertSame(
            1,
            preg_match('/RootRunGuard::suiteRefusal\(\);\s*\n\s*if \(\$rootRunRefusal !== null\) \{\s*\n\s*\\\\?fwrite\(\s*\\\\?STDERR,\s*\$rootRunRefusal\s*\);\s*\n\s*exit\(1\);/', $source),
            'the refusal must land on stderr and exit non-zero — a warning that carries on is a refusal nobody reads',
        );
    }

    public function testAChildBootstrapWithTmpdirRootRefusesTheLaunch(): void
    {
        // THE REAL BEHAVIOUR, driveable without root: TMPDIR=/ is refused at
        // every uid. The child is handed a minimal environment (absolute php,
        // absolute require, HOME and TMPDIR only) — nothing it reads exists
        // before the guard exits, so the probe cannot litter: if the guard is
        // neutered, the child CREATES /sc_suite_tmp_<uid> — wait, as non-root
        // that mkdir fails under the sticky bit, so a neutered guard shows up
        // as the run proceeding to render (exit != 1 with phpunit-side noise),
        // which is still exactly one red here.
        $code = 'require ' . var_export( __DIR__ . '/bootstrap.php', true) . '; echo "GUARD-DID-NOT-EXIT";';

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(
            [PHP_BINARY, '-r', $code],
            $descriptors,
            $pipes,
            \dirname(__DIR__, 2),
            [
                'PATH' => '/usr/bin:/bin',
                'HOME' => \dirname(__DIR__, 2),
                'TMPDIR' => '/',
            ],
        );

        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        self::assertSame(1, $status, 'a bootstrap handed TMPDIR=/ must exit 1, got ' . var_export($status, true) . " — stdout: {$stdout}");
        self::assertStringNotContainsString('GUARD-DID-NOT-EXIT', $stdout, 'the child ran past the refusal');
        self::assertStringContainsString('refuses to run with TMPDIR=/', $stderr);
    }

    public function testAChildBootstrapWithTheHatchStillRefusesTheTempRoot(): void
    {
        // The non-escapability of the `/` arm, driven through the REAL wrapper
        // rather than the pure static: the hatch is set, the temp root is set,
        // and the launch still stops.
        $code = 'require ' . var_export( __DIR__ . '/bootstrap.php', true) . '; echo "GUARD-DID-NOT-EXIT";';

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(
            [PHP_BINARY, '-r', $code],
            $descriptors,
            $pipes,
            \dirname(__DIR__, 2),
            [
                'PATH' => '/usr/bin:/bin',
                'HOME' => \dirname(__DIR__, 2),
                'TMPDIR' => '/',
                'CANDY_CODE_ALLOW_ROOT_TESTS' => '1',
            ],
        );

        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        self::assertSame(1, $status, 'CANDY_CODE_ALLOW_ROOT_TESTS=1 must not authorise a /-rooted sandbox');
        self::assertStringNotContainsString('GUARD-DID-NOT-EXIT', $stdout);
    }
}
