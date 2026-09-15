<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Cli;

use PHPUnit\Framework\TestCase;

/**
 * E712 (round 82) — the library-vs-CLI boundary guard.
 *
 * SugarCraft convention: library code throws, and `bin/candy-vcr` is the
 * single exit() chokepoint (`exit(Application::run(...))`, Command::run
 * returns int). The one tolerated exit() inside src/ is the async pcntl
 * fallback in RecordCommand::handleRescueSignal(): a signal frame is NOT
 * on the return-int channel — throwing from it would unwind mid-pump into
 * run()'s catch(\Throwable) (turning 128+signo into 1) or escape uncaught
 * after run() returned (255). exit(128 + signo) is the only way to die
 * with the conventional status from there; the posix-available normal path
 * expresses the same death via SIG_DFL re-raise instead.
 *
 * The census is fail-closed in both directions: a NEW exit() anywhere in
 * src/ reddens, and retiring the justified one without updating this
 * roster reddens too.
 */
final class SrcExitCensusTest extends TestCase
{
    /**
     * Roster of tolerated T_EXIT occurrences: src-relative path => count.
     * Keep in lockstep with the E712 verdict comment at the site.
     *
     * @var array<string, int>
     */
    private const JUSTIFIED_EXITS = [
        'src/Cli/RecordCommand.php' => 1,
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
            'exit()/die() in src/ must equal the E712 roster — bin/candy-vcr is the only chokepoint; '
            . 'a signal-handler frame is the sole justified exception.',
        );
    }

    public function testTheJustifiedExitLivesInsideHandleRescueSignal(): void
    {
        $tokens = \PhpToken::tokenize(
            \file_get_contents(\dirname(__DIR__, 2) . '/src/Cli/RecordCommand.php'),
        );

        $methodRange = self::methodBraceRange($tokens, 'handleRescueSignal');
        $this->assertNotNull($methodRange, 'handleRescueSignal() must exist');

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

        $this->assertSame(1, $exitsInside, 'exactly the documented fallback exit() belongs in handleRescueSignal()');
        $this->assertSame(0, $exitsOutside, 'no other exit() may exist in RecordCommand.php');
    }

    public function testTheFallbackDiesWithConventionalSignalStatus(): void
    {
        // Observable contract of the justified exit(): with ext-posix's
        // re-raise helpers disabled (the fallback's real-world trigger:
        // pcntl present, posix absent), the process must exit with
        // 128 + signo — NOT die by signal, NOT 255 (an uncaught throw —
        // this is the pin that keeps a future "make it throw" refactor
        // from silently changing the exit code), NOT fall through.
        $child = \dirname(__DIR__, 2) . '/tests/Cli/Support/rescue-signal-fallback-child.php';
        $this->assertFileExists($child);

        $spec = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = \proc_open(
            [\PHP_BINARY, '-d', 'disable_functions=posix_kill,posix_getpid', $child, '15'],
            $spec,
            $pipes,
            \dirname(__DIR__, 2),
        );
        $this->assertIsResource($process, 'child must spawn');

        $stdout = \stream_get_contents($pipes[1]);
        \fclose($pipes[1]);
        $stderr = \stream_get_contents($pipes[2]);
        \fclose($pipes[2]);
        $status = \proc_close($process);

        $this->assertSame('', (string) $stdout, 'the fallback must never fall through past handleRescueSignal()');
        $this->assertStringNotContainsString('Uncaught', (string) $stderr, 'a throw instead of exit() would fatal here');
        $this->assertSame(143, $status, 'observable exit code must stay 128 + SIGTERM (15)');
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
                        return [$start, $tokens[$cursor]->pos + strlen($tokens[$cursor]->text) - 1];
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
