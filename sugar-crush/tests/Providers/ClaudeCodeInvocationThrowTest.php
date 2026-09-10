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

    public function testTheSpawnFailureShapeKeepsTheRuntimeExceptionContract(): void
    {
        // The kernel-reachable spawn-failure branch (proc_open returning false)
        // is not reproducible without tripping PHPUnit's warning-to-error
        // handler, so the SHAPE it throws is pinned directly, mirroring how
        // TransientFailureTest pins the provider sites: null exitCode means the
        // child never spawned, and the class must stay a \RuntimeException so
        // the broad catches around the providers keep working.
        $spawnFailure = new ProviderException('Failed to start Claude Code process');

        $this->assertInstanceOf(\RuntimeException::class, $spawnFailure);
        $this->assertNull($spawnFailure->exitCode, 'null exitCode means spawn failure, never "exit 0"');
        $this->assertSame(0, $spawnFailure->getCode());
    }
}
