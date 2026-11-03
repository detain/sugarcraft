<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\GitServer\Support\BoundedCommand;

/**
 * The shared bounded runner behind every short src subprocess.
 *
 * These pins are what make the exec() retirement safe: argv reaches the
 * child verbatim, the deadline converts a wedge into a fast failure,
 * stderr stays out of successful parses, and no bare exec()/shell call
 * may sneak back into src/.
 *
 * @covers \SugarCraft\GitServer\Support\BoundedCommand
 */
final class BoundedCommandTest extends TestCase
{
    public function testStdoutOnlyOnSuccessCarriesTheLines(): void
    {
        $result = BoundedCommand::run(['printf', 'a\nb\n']);

        self::assertSame(0, $result['code']);
        self::assertSame(['a', 'b'], $result['output']);
    }

    public function testEmptyOutputIsAnEmptyLineListNotASingleEmptyString(): void
    {
        $result = BoundedCommand::run(['/bin/true']);

        self::assertSame(0, $result['code']);
        self::assertSame([], $result['output']);
    }

    public function testStderrStaysOutOfSuccessfulOutputButMergesOnFailure(): void
    {
        $quiet = BoundedCommand::run(['/bin/sh', '-c', 'echo out; echo noise >&2']);
        self::assertSame(0, $quiet['code']);
        self::assertSame(['out'], $quiet['output'], 'progress noise on stderr must not pollute success parses');

        $failed = BoundedCommand::run(['/bin/sh', '-c', 'echo out; echo reason >&2; exit 3']);
        self::assertSame(3, $failed['code']);
        self::assertContains('out', $failed['output']);
        self::assertContains('reason', $failed['output'], 'failure context must survive the stream split');
    }

    public function testWedgedChildIsTerminatedWithinTheDeadlineNotForever(): void
    {
        $started = \microtime(true);
        $result = BoundedCommand::run(['/bin/sh', '-c', 'trap "" TERM; exec sleep 30'], 0.3);
        $elapsed = \microtime(true) - $started;

        self::assertLessThan(8.0, $elapsed, 'TERM-ignoring child must escalate to KILL, bounded');
        self::assertNotSame(0, $result['code']);
        self::assertStringContainsString('command timed out', \implode(' ', $result['output']));
    }

    public function testExtraEnvReachesTheChildAlongsideTheInheritedPath(): void
    {
        $result = BoundedCommand::run(['printenv', 'CANDY_TEST_FLAG'], 5.0, ['CANDY_TEST_FLAG' => 'on']);
        self::assertSame(0, $result['code']);
        self::assertSame(['on'], $result['output']);

        $path = BoundedCommand::run(['printenv', 'PATH']);
        self::assertSame(0, $path['code'], 'the merged environment must still carry PATH');
        self::assertNotSame([], $path['output']);
    }

    public function testMissingBinaryFailsFastWithoutThrowing(): void
    {
        $result = BoundedCommand::run(['/nonexistent/candy-git-server-probe-binary']);

        self::assertNotSame(0, $result['code']);
    }

    public function testMetacharactersAreArgumentsNotShellSyntax(): void
    {
        $marker = $this->markerPath();
        $result = BoundedCommand::run(['touch', $marker . ';echo-pwned']);

        self::assertSame(0, $result['code']);
        self::assertFileExists($marker . ';echo-pwned', 'the literal name is the file — no shell was involved');
        self::assertFileDoesNotExist($marker);
        @\unlink($marker . ';echo-pwned');
    }

    public function testNoBareExecCallSurvivesInSrc(): void
    {
        // Structural anti-regression: the shell-string vocabulary is gone
        // from src/ for good. A `$this->exec)(`-style seam call is not a
        // match; the pattern targets \exec( / exec( invocations only.
        $root = \dirname(__DIR__, 2) . '/src';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $code = (string) \file_get_contents($file->getPathname());
            // strip comments so prose about the old exec() sites stays legal
            $code = \preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $code) ?? $code;
            self::assertDoesNotMatchRegularExpression(
                '/(?<![\$>\w])\bexec\s*\(/',
                $code,
                'bare exec() spawn crept into ' . $file->getPathname() . ' — use BoundedCommand::run(argv)'
            );
            self::assertStringNotContainsString('escapeshellarg', $code,
                'shell quoting reappears in ' . $file->getPathname() . ' — argv vectors need no escaper');
        }
    }

    private function markerPath(): string
    {
        return \sys_get_temp_dir() . '/candy-bounded-' . \uniqid('', true);
    }
}
