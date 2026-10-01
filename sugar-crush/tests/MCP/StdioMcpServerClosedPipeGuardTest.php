<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\MCP;

use PHPUnit\Framework\TestCase;

/**
 * A CLOSED PIPE RESOURCE IS AN EXCEPTION WAITING TO HAPPEN, AND `@` DOES NOT
 * CATCH IT.
 *
 * {@see \SugarCraft\Crush\LSP\LspConnection} guards every pipe access with
 * `is_resource()` and carries the reasoning at
 * {@see \SugarCraft\Crush\LSP\LspConnection::drainStderr()}. The stdio MCP
 * transport grew the same guards after this file measured the hazard; the
 * transport now lives in `sugarcraft/sugar-mcp` (phase-2a), and what this
 * file OWNS — the thing LspConnection's comment cites — is the host-science
 * the guards rest on: on PHP 8.3.6 / Linux 6.8, closed `proc_open()` pipes
 * raise, from the five call shapes a pipe reader uses, the exceptions spelled
 * out below, and WHICH of the two `stream_select()` raises is decided by
 * whether a SELECTABLE descriptor survives PHP's filter.
 *
 * That measurement cannot move into the library's suite, because it is a claim
 * about THIS product's runtime contract with LspConnection: both framers may
 * hold a closed resource mid-teardown — `stop()` closes the pipes FIRST on
 * purpose (the EOF on fd 0 is what lets a well-behaved server leave without
 * paying the SIGTERM escalation) and only nulls the field after the reap — so
 * every reader on either side needs the `is_resource()` guard, and a guard
 * written to catch one exception class by name would miss the other.
 *
 * This file used to carry five more rows driving the transport's own guarded
 * methods through that window; the methods moved with the transport and the
 * rows went with them (façade law — one suite per implementation).
 */
final class StdioMcpServerClosedPipeGuardTest extends TestCase
{
    /** @var list<array{0: resource, 1: array<int, resource>}> companion children, reaped in tearDown() */
    private array $companions = [];

    protected function tearDown(): void
    {
        // Only the children this test spawned, by handle — never a pattern
        // sweep. Leaking a process out of a suite is how a sibling run inherits
        // a stranger.
        foreach ($this->companions as [$handle, $pipes]) {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_terminate($handle, 9);
            proc_close($handle);
        }
        $this->companions = [];

        parent::tearDown();
    }

    /**
     * THE KNOWN-POSITIVE CONTROL, and the file's whole surviving point: every
     * guard-assertion elsewhere ("this returned rather than throwing") is only
     * worth something because THIS row proves closed pipes throw on the host
     * the guards defend.
     *
     * A row that says "this returned rather than throwing" is satisfied by a PHP
     * in which closed pipes are harmless and by a fixture whose pipes were never
     * closed. This pushes freshly-closed `proc_open()` pipes through the SAME
     * five call shapes the guards wrap, unguarded, and requires every one of
     * them to throw. If this row goes green-by-silence the guards it cites are
     * defending a hazard that no longer exists, and the reader of THAT red is
     * pointed here first.
     */
    public function testTheClosedPipeHazardIsRealOnThisHostAndPhpVersion(): void
    {
        [$pipes] = $this->closedPipeSet();

        foreach ([0, 1, 2] as $fd) {
            $this->assertFalse(
                is_resource($pipes[$fd]),
                "fd {$fd} is still open, so the fixture did not do what this file assumes",
            );
        }

        $this->assertThrows(
            \ValueError::class,
            static function () use ($pipes): void {
                $read = [];
                $write = [$pipes[0]];
                $except = [];
                @stream_select($read, $write, $except, 0, 1000);
            },
            'stream_select() with the closed fd as the only entry',
        );

        // ⚠️ THE COMPANION HAS TO BE A REAL, SELECTABLE DESCRIPTOR, and
        // finding that out is part of what this row is for. MEASURED, PHP 8.3.6,
        // three consecutive takes each, a closed pipe in the write set beside:
        //
        //     another proc_open() pipe  ->  TypeError
        //     `STDIN` on a plain CLI    ->  TypeError
        //     `STDIN` UNDER THIS SUITE  ->  ValueError
        //     a `php://memory` stream   ->  ValueError
        //
        // So the discriminator is not "a valid resource survived the filter"
        // — which is how the sibling's doc-block reads — but "a SELECTABLE
        // one did". A memory stream has no descriptor and is dropped like the
        // closed pipe, leaving every array empty, which is the ValueError
        // path; and this suite's stdin is not selectable either, so the
        // obvious companion silently produced the wrong polarity here.
        $companion = $this->liveCompanionPipe();

        $this->assertThrows(
            \TypeError::class,
            static function () use ($pipes, $companion): void {
                $read = [$companion];
                $write = [$pipes[0]];
                $except = [];
                @stream_select($read, $write, $except, 0, 1000);
            },
            'stream_select() with the closed fd beside an open one',
        );

        $this->assertThrows(\TypeError::class, static fn () => @fread($pipes[2], 8192), 'fread()');
        $this->assertThrows(\TypeError::class, static fn () => @feof($pipes[1]), 'feof()');
        $this->assertThrows(\TypeError::class, static fn () => @fwrite($pipes[0], 'x'), 'fwrite()');
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * A live child's three pipes, closed, with the handle kept for the record —
     * the exact shape `stop()` leaves behind mid-teardown: closed resources in
     * a populated field.
     *
     * @return array{0: array<int, resource>, 1: resource}
     */
    private function closedPipeSet(): array
    {
        $handle = proc_open(
            [PHP_BINARY, '-r', 'usleep(3000000);'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($handle, 'could not spawn the pipe-holder child');
        $this->companions[] = [$handle, $pipes];

        foreach ($pipes as $pipe) {
            fclose($pipe);
        }

        return [$pipes, $handle];
    }

    private function liveCompanionPipe()
    {
        $handle = proc_open(
            [PHP_BINARY, '-r', 'usleep(3000000);'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($handle, 'could not spawn the companion child');
        $this->companions[] = [$handle, $pipes];

        return $pipes[2];
    }

    /** @param callable(): mixed $run */
    private function assertThrows(string $class, callable $run, string $what): void
    {
        try {
            $run();
        } catch (\Throwable $e) {
            $this->assertInstanceOf(
                $class,
                $e,
                $what . ' raised ' . get_class($e) . ' rather than ' . $class . ': ' . $e->getMessage(),
            );

            return;
        }

        $this->fail(
            $what . ' on a closed pipe did not throw on ' . \PHP_VERSION . '. Either this host no '
            . 'longer needs the guards this file cites, or the pipes were not closed — check the '
            . 'is_resource() assertions above before touching the guards.',
        );
    }
}
