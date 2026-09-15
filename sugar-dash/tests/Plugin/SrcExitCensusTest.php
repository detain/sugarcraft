<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Plugin;

use PHPUnit\Framework\TestCase;

/**
 * E723 (round 82) — the library-vs-plugin-process boundary guard.
 *
 * SugarCraft convention: library code throws, and a `bin/` script is the
 * single exit() chokepoint. sugar-dash has no bin/, but it does ship a
 * plugin-process ENTRYPOINT of its own: `PluginSdk::run(callable): never`,
 * whose STDIN loop is the whole life of the child process that
 * ExternalModule spawns. The one tolerated exit() inside src/ is the
 * `exit(0)` at that loop's EOF terminus (E723 verdict: justified-keep).
 *
 * WHY IT STAYS exit(0) RATHER THAN A THROW (mirrors the candy-vcr E712
 * analysis, judged on this site's own merits):
 *  - `run()` consumes the process's STDIN for its entire life; a hypothetical
 *    in-host caller has already lost its stdin before any exit semantics
 *    matter, so the exit adds no new host-capture surface.
 *  - The `never` return type is a public signature (changing it is out of
 *    scope) and is load-bearing: normal completion of a never-typed function
 *    raises a TypeError, so merely deleting the exit turns the child's
 *    observable status from 0 into 255 with "Uncaught" on stderr.
 *  - The EOF ⇒ status 0 handshake is the cooperative half of ExternalModule's
 *    E366 grace rung: the host closes the pipes and the child leaves on its
 *    own, so no signal is ever sent to a well-behaved plugin.
 *
 * The census is fail-closed in both directions: a NEW exit() anywhere in
 * src/ reddens, and retiring the justified one without updating this roster
 * reddens too.
 */
final class SrcExitCensusTest extends TestCase
{
    /**
     * Roster of tolerated T_EXIT occurrences: src-relative path => count.
     * Keep in lockstep with the E723 verdict docblock at the site.
     *
     * @var array<string, int>
     */
    private const JUSTIFIED_EXITS = [
        'src/Plugin/PluginSdk.php' => 1,
    ];

    public function testEveryExitCallInSrcIsOnTheJustifiedRoster(): void
    {
        $found = [];
        foreach (self::srcPhpFiles() as $relative => $absolute) {
            $count = 0;
            foreach (\PhpToken::tokenize(\file_get_contents($absolute)) as $token) {
                if ($token->is(\T_EXIT)) {
                    $count++;
                }
            }
            if ($count > 0) {
                $found[$relative] = $count;
            }
        }
        \ksort($found);

        $expected = self::JUSTIFIED_EXITS;
        \ksort($expected);

        $this->assertSame(
            $expected,
            $found,
            'exit()/die() in src/ must equal the E723 roster — PluginSdk::run()\'s EOF terminus is the '
            . 'sole plugin-process entrypoint; every other src surface throws and lets the caller decide.',
        );
    }

    public function testTheJustifiedExitLivesInsideTheRunLoopTerminus(): void
    {
        $tokens = \PhpToken::tokenize(
            \file_get_contents(\dirname(__DIR__, 2) . '/src/Plugin/PluginSdk.php'),
        );

        $methodRange = self::methodBraceRange($tokens, 'run');
        $this->assertNotNull($methodRange, 'PluginSdk::run() must exist');

        $exitsOutside = 0;
        $exitsInside = 0;
        foreach ($tokens as $token) {
            if (!$token->is(\T_EXIT)) {
                continue;
            }
            if ($token->pos >= $methodRange[0] && $token->pos <= $methodRange[1]) {
                $exitsInside++;
            } else {
                $exitsOutside++;
            }
        }

        $this->assertSame(1, $exitsInside, 'exactly the documented terminus exit(0) belongs inside run()');
        $this->assertSame(0, $exitsOutside, 'no other exit() may exist in PluginSdk.php');
    }

    public function testTheEofTerminusDiesWithZeroStatusAndCleanStderr(): void
    {
        // Observable contract of the justified exit(): drive the real child
        // fixture exactly the way ExternalModule does — one init request,
        // then close stdin (the grace-rung door) — and check what the kernel
        // says. NOT 255 (the TypeError shape a future "make it throw"/
        // delete-the-exit refactor would silently produce), NOT a hang
        // (reads are timeout-bounded and the test kills+fails instead of
        // wedging the runner), and the init response must have been served
        // before the exit (the loop must not die early).
        $child = \dirname(__DIR__, 2) . '/tests/Plugin/Support/sdk-eof-exit-child.php';
        $this->assertFileExists($child);

        $spec = [
            // Child-perspective modes, as every other proc_open in this lib:
            // the PARENT gets the write end of fd 0 and read ends of 1/2.
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = \proc_open([\PHP_BINARY, $child], $spec, $pipes, \dirname(__DIR__, 2));
        $this->assertIsResource($process, 'child must spawn');

        \stream_set_timeout($pipes[1], 10);
        \stream_set_timeout($pipes[2], 10);

        \fwrite($pipes[0], '{"type":"init","data":{}}' . "\n");
        \fflush($pipes[0]);
        $responseLine = \fgets($pipes[1]);

        // The E366 door: closing fd 0 is the polite shutdown request.
        \fclose($pipes[0]);

        $stdoutRest = \stream_get_contents($pipes[1]);
        $stderr = \stream_get_contents($pipes[2]);
        $closedWhileDraining = (bool) (\proc_get_status($process)['running'] ?? false);
        if ($closedWhileDraining) {
            // A stream_get_contents() that returned while the child still
            // runs means the timeout fired — the EOF door regressed into a
            // hang. Kill it, and fail loudly rather than inherit the leash.
            \proc_terminate($process, 9);
        }

        \fclose($pipes[1]);
        \fclose($pipes[2]);
        $status = \proc_close($process);

        $this->assertFalse($closedWhileDraining, 'the child must exit on stdin EOF, not need killing');

        $decoded = \json_decode((string) $responseLine, true);
        $this->assertIsArray($decoded, 'the init request must be served before the EOF terminus');
        $this->assertSame('init', $decoded['type'] ?? null);
        $this->assertSame('', (string) $stdoutRest, 'stdout must drain to EOF exactly at the exit');
        $this->assertStringNotContainsString('Uncaught', (string) $stderr, 'a throw instead of exit() would fatal here');
        $this->assertSame(0, $status, 'the EOF terminus must keep the child on observable status 0');
    }

    /**
     * Brace-match the body of the named method/function.
     *
     * @param list<\PhpToken> $tokens
     * @return array{0:int,1:int}|null  [startOffset, endOffset] of the body incl. braces
     */
    private static function methodBraceRange(array $tokens, string $name): ?array
    {
        foreach ($tokens as $index => $token) {
            if (!$token->is(\T_FUNCTION)) {
                continue;
            }
            // Next whitespace-skipped token must be the name.
            $cursor = $index + 1;
            while (isset($tokens[$cursor]) && $tokens[$cursor]->is([\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT])) {
                $cursor++;
            }
            if (!isset($tokens[$cursor]) || $tokens[$cursor]->text !== $name) {
                continue;
            }
            // Advance to the body's opening brace.
            while (isset($tokens[$cursor]) && $tokens[$cursor]->text !== '{') {
                $cursor++;
            }
            if (!isset($tokens[$cursor])) {
                return null;
            }
            $depth = 0;
            $start = $tokens[$cursor]->pos;
            for (; isset($tokens[$cursor]); $cursor++) {
                if ($tokens[$cursor]->text === '{') {
                    $depth++;
                } elseif ($tokens[$cursor]->text === '}') {
                    $depth--;
                    if ($depth === 0) {
                        return [$start, $tokens[$cursor]->pos + \strlen($tokens[$cursor]->text) - 1];
                    }
                }
            }
            return null;
        }
        return null;
    }

    /**
     * Every .php file under src/, keyed by `src/`-relative path.
     *
     * @return array<string, string>
     */
    private static function srcPhpFiles(): array
    {
        $root = \dirname(__DIR__, 2) . '/src';
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[\substr($file->getPathname(), \strlen(\dirname($root)) + 1)] = $file->getPathname();
            }
        }
        return $files;
    }
}
