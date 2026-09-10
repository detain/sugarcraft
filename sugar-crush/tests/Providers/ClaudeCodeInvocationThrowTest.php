<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Providers\ClaudeCodeInvocation;
use SugarCraft\Crush\Providers\ProviderException;

/**
 * E664: ClaudeCodeInvocation::execute() is the SECOND Claude Code subprocess
 * site — the provider owns the first two, retyped to the structured
 * ProviderException under E27(a), and this file is the pin lane Q left open.
 *
 * The throws are now ProviderExceptions. The non-zero exit carries the child's
 * shell code on the exitCode PROPERTY so TransientFailure::statusCode() can
 * never misread it as an HTTP status, and the class stays a \RuntimeException
 * so every existing broad catch around the providers keeps its contract. The
 * per-code retry verdict remains deliberately unmade — that decision lives in
 * TransientFailure's allow-list, pinned in TransientFailureTest.
 */
final class ClaudeCodeInvocationThrowTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/crush_ccinv_' . uniqid('', true);
        mkdir($this->tempDir, 0o700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tempDir);
    }

    /**
     * An invocation whose `claude` binary is a stub built from $source.
     *
     * `claudePath` becomes argv[0] of an ARGV-form proc_open(), so the stub
     * needs a `#!` header — the kernel, not a shell, resolves the interpreter,
     * and the extra `--output-format json ...` argv the invocation appends is
     * simply ignored by a script that never reads its arguments.
     */
    private function invocationOver(string $source): ClaudeCodeInvocation
    {
        $script = $this->tempDir . '/claude';
        $withShebang = str_replace('<?php', '#!' . PHP_BINARY . "\n<?php", $source, $count);
        $this->assertSame(1, $count, 'the stub source must contain exactly one PHP opener to prefix');
        file_put_contents($script, $withShebang);
        chmod($script, 0o755);

        return new ClaudeCodeInvocation(claudePath: $script, configDir: $this->tempDir);
    }

    public function testANonZeroExitThrowsTheTypedProviderExceptionCarryingTheCodeAsStructure(): void
    {
        $invocation = $this->invocationOver(<<<'PHP'
            <?php
            fwrite(STDERR, 'ENOENT: model unavailable');
            exit(3);
            PHP);

        // `fail()` throws AssertionFailedError, which is-a \RuntimeException, so
        // any assert that must run BEFORE the catch belongs outside the try.
        // See {@see \SugarCraft\Crush\Tests\SwallowingCatchCensusTest}.
        $caught = null;

        try {
            $invocation->execute(['-p', 'hi']);
        } catch (\RuntimeException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, 'a non-zero exit must throw');
        $this->assertInstanceOf(
            ProviderException::class,
            $caught,
            'a non-zero exit must throw the typed provider exception',
        );
        $this->assertSame(3, $caught->exitCode, 'the exit code must ride as structure, not only prose');
        $this->assertSame(
            0,
            $caught->getCode(),
            'the exception code stays 0 — a shell exit is not an HTTP status and must not reach statusCode()',
        );
        $this->assertStringContainsString('exited with code 3', $caught->getMessage());
        $this->assertStringContainsString(
            'ENOENT: model unavailable',
            $caught->getMessage(),
            "the child's stderr is the only diagnostic on this path and must reach the message",
        );
    }

    /**
     * Drives the REAL spawn-failure branch — execute()'s `!is_resource($process)`
     * guard over an ARGV-form proc_open(). `claudePath` is argv[0], so a binary
     * the kernel cannot launch makes proc_open() emit an E_WARNING and return
     * false (verified on PHP 8.3/Linux; the string form would instead exec a
     * shell and always yield a resource). A test-local error handler swallows
     * that warning — PHPUnit's own warning-to-exception conversion would fire
     * before the branch throws — and restore_error_handler() runs in `finally`.
     * No assertion sits inside the try (capture-outside-try idiom,
     * {@see \SugarCraft\Crush\Tests\SwallowingCatchCensusTest}).
     *
     * Unlike a hand-constructed ProviderException, this pins production's own
     * throw: reverting the branch to a bare \RuntimeException reddens the
     * instanceof pin. The full shape is asserted on the caught instance —
     * typed class, null exitCode (child never spawned), code 0 (a spawn failure
     * is not an HTTP status), no previous, exact message.
     */
    public function testTheSpawnFailureThrowsTheTypedProviderExceptionFromTheRealBranch(): void
    {
        $invocation = new ClaudeCodeInvocation(
            claudePath: $this->tempDir . '/no-such-claude-binary',
            configDir: $this->tempDir,
        );

        $caught = null;
        set_error_handler(static fn (): bool => true);

        try {
            $invocation->execute(['-p', 'hi']);
        } catch (\RuntimeException $e) {
            $caught = $e;
        } finally {
            restore_error_handler();
        }

        $this->assertNotNull($caught, 'an unlaunchable claudePath must throw');
        $this->assertInstanceOf(
            ProviderException::class,
            $caught,
            'the spawn-failure branch must throw the typed provider exception, not a bare \\RuntimeException',
        );
        $this->assertSame(
            'Failed to start Claude Code process',
            $caught->getMessage(),
            'the message is the shared contract with the provider site lane Q retyped',
        );
        $this->assertNull($caught->exitCode, 'null exitCode means spawn failure, never "exit 0"');
        $this->assertSame(0, $caught->getCode());
        $this->assertNull($caught->getPrevious(), 'the branch throws causeless — nothing hidden to misread downstream');
    }
}
