<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Support\ToolIpcFiles;

/**
 * What `tests/bootstrap.php`'s `TMPDIR` sandbox actually covers, pinned.
 *
 * The sandbox comment rests on two claims about the interpreter, and E242
 * proposed re-keying the directory on the strength of a third that follows
 * from them. Both are stdlib behaviour rather than this tree's behaviour, so
 * neither can be settled by reading `src/`, and one of them CANNOT BE
 * MEASURED ON THIS BOX AT ALL: CI runs PHP 8.4 as well as 8.3, and a change in
 * how the interpreter resolves its temporary directory would silently move
 * every child's temp files back onto the machine's real one. Asserting them
 * here is what makes 8.4 answer the question this box cannot.
 *
 *  1. `putenv('TMPDIR=…')` does NOT move `sys_get_temp_dir()` in the process
 *     that calls it. The bootstrap says so and depends on it: it is why the
 *     suite keeps building its own sandboxes under the real temp directory
 *     while children get the sandbox.
 *  2. A CHILD does pick it up, because it resolves the variable after
 *     inheriting it. Without this the sandbox does nothing at all, and claim 1
 *     alone reads as a pass.
 *
 * The consequence, which is why E242's proposed re-key was not made:
 * {@see ToolIpcFiles::reserve()} names payloads under `sys_get_temp_dir()`, so
 * by claim 1 the suite's OWN in-process payloads never enter the sandbox at
 * any key. Re-keying it by checkout would not have moved the file E242 saw two
 * processes collide on — that path is `sys_get_temp_dir()`-based too.
 */
final class SuiteTempSandboxContractTest extends TestCase
{
    public function testPutenvDoesNotMoveTheCallingProcessesTempDirectory(): void
    {
        // A third directory: distinct from the real temp dir AND from the
        // sandbox the child already inherits, or "it did not move" is true for
        // the wrong reason.
        $target = \sys_get_temp_dir() . '/sc_tmpdir_probe_' . \getmypid() . '_' . \uniqid((string) \getmypid(), true);
        \mkdir($target, 0700, true);

        try {
            $quoted = \var_export($target, true);

            // putenv AFTER the first resolution.
            $late = $this->phpProbe(
                '$before = sys_get_temp_dir();'
                . 'putenv("TMPDIR=" . ' . $quoted . ');'
                . 'echo json_encode(["before" => $before, "after" => sys_get_temp_dir()]);',
            );
            $this->assertSame($late['before'], $late['after'], 'putenv() moved sys_get_temp_dir() mid-process');
            $this->assertNotSame($target, $late['after']);

            // putenv BEFORE anything has asked. Still no: PHP resolves its
            // temporary directory from the environment it was started with.
            $early = $this->phpProbe(
                'putenv("TMPDIR=" . ' . $quoted . ');'
                . 'echo json_encode(["tmp" => sys_get_temp_dir()]);',
            );
            $this->assertNotSame(
                $target,
                $early['tmp'],
                'putenv() before the first call DID move sys_get_temp_dir(); the bootstrap comment is now wrong '
                    . 'and every in-process temp path in this suite has moved with it',
            );

            // KNOWN-POSITIVE for the same probe: the variable set in the
            // child's LAUNCH environment does move it. Without this, both
            // assertions above are satisfied by a probe that cannot see a
            // change at all.
            $inherited = $this->phpProbe('echo json_encode(["tmp" => sys_get_temp_dir()]);', ['TMPDIR' => $target]);
            $this->assertSame($target, $inherited['tmp'], 'the probe cannot observe a moved temp directory at all');
        } finally {
            @\rmdir($target);
        }
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
     * @param array<string, string>|null $env launch environment, or null to inherit
     * @return array<string, string>
     */
    private function phpProbe(string $code, ?array $env = null): array
    {
        $process = proc_open(
            [PHP_BINARY, '-r', $code],
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
