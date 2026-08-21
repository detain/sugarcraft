<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Commands;

use PHPUnit\Framework\TestCase;

/**
 * Commands whose stdout is folded into the TUI transcript must not colour
 * themselves with raw escape sequences.
 *
 * `/mcp` and `/share` are CLI-shaped classes that `echo` their output, and
 * {@see \SugarCraft\Crush\Chat} captures that with `ob_start()` and appends it
 * to the chat. They styled themselves with `\033[33m…\033[0m`, which surfaced
 * in the chat as a literal `[33m` — the escape byte was consumed somewhere on
 * the way in, leaving the parameter bytes as visible text.
 *
 * Deliberately asserted at the SOURCE rather than fixed by stripping escapes
 * on the way into the transcript: a blanket strip would hide the next
 * offender instead of surfacing it. The TUI styles its own text, so these
 * classes have no business emitting escapes at all.
 *
 * KNOW WHAT THIS DOES NOT CATCH. The assertion is a regex over the file, so
 * it sees a `"\033["` LITERAL and nothing else. Escapes acquired at RUNTIME
 * — a `Style` applied by a table's `styleFunc()`, a theme colour resolved
 * from config — reintroduce exactly the `[33m` defect while passing here
 * cleanly. The rendered-output half of the guard is
 * {@see CommandTableRenderingTest}, which executes these commands and reads
 * the bytes they actually emit; neither test replaces the other.
 *
 * THE CENSUS IS DERIVED TWO WAYS, and the first cut had only half of one.
 * It globbed `src/Commands/*.php` for `/^\s*echo\s/m` and called that
 * "declares a class and echoes at statement level". MEASURED, that domain is
 * actually "has `echo` as the first token on a LINE": a probe command
 * emitting raw ANSI through `printf()`, through `print`, through
 * `fwrite(STDOUT, …)`, or through an `echo` that is not line-initial passed
 * GREEN — the file never entered the provider, so neither the ANSI assertion
 * nor the census guard fired. A census whose domain is narrower than its
 * doc-block claims is worse than a hand-written list, because it looks
 * exhaustive.
 *
 *  - {@see capturedCommandClasses()} reads the REAL domain off
 *    {@see \SugarCraft\Crush\Chat}: every class constructed between an
 *    `ob_start()` and its `ob_get_clean()`. That is the definition of "this
 *    class's stdout becomes transcript", not a proxy for it.
 *  - {@see stdoutEmittingCommandFiles()} scans `src/Commands` RECURSIVELY with
 *    `token_get_all()` for every way a statement reaches stdout — `echo`,
 *    `print`, `printf`/`vprintf`, `fwrite`/`fputs` on `STDOUT` or
 *    `php://stdout`, `readfile`, `fpassthru`, `var_dump`, `print_r`. This is
 *    the wider net, and it catches a command that emits before anything wires
 *    it into `Chat`.
 *
 * The two are asserted to agree ({@see testEveryCapturedCommandAlsoEmitsToStdout()}),
 * so a capture site added without an emitter — or an emitter that stops being
 * captured — reds rather than silently shrinking the set under test.
 */
final class NoRawAnsiInTranscriptTest extends TestCase
{
    /**
     * DERIVED, not listed. The provider used to name `McpAuthCommand` and
     * `ShareCommand` by hand — and `AgentsCommand` and `WebSearchCommand`,
     * which reach the transcript through the identical
     * `ob_start()`/`execute()`/`ob_get_clean()` route in `Chat`, were simply
     * never added. A hand-written provider cannot fail for a case it omits:
     * MEASURED, deleting a row from the old list left the suite green, which
     * makes the omission invisible in exactly the direction that matters.
     *
     * The census is the UNION of the two derivations the class doc-block
     * describes, so a file only has to be caught by one of them.
     *
     * @return array<string, array{0: string}>
     */
    public static function transcriptCommandProvider(): array
    {
        $cases = [];
        foreach (self::stdoutEmittingCommandFiles() as $path) {
            $cases[basename($path, '.php')] = [$path];
        }

        foreach (self::capturedCommandClasses() as $class) {
            $path = self::commandsDir() . '/' . $class . '.php';
            if (is_file($path)) {
                $cases[$class] = [$path];
            }
        }

        // Does not emit, and is listed anyway: it is where these commands'
        // column layout is now built, so it is where a `Style` or a
        // `styleFunc()` would be written.
        $cases['TranscriptTable'] = [self::commandsDir() . '/TranscriptTable.php'];

        ksort($cases);

        return $cases;
    }

    /**
     * Every class `Chat` constructs inside an `ob_start()` … `ob_get_clean()`
     * pair — the exact set whose stdout becomes transcript text.
     *
     * Scanned rather than listed for the same reason everything else here is:
     * a fifth capture site is a one-line change in a 10,000-line file, and the
     * failure mode of missing it is a silent narrowing of what gets checked.
     *
     * @return list<string>
     */
    private static function capturedCommandClasses(): array
    {
        $chat = (string) file_get_contents(__DIR__ . '/../../src/Chat.php');

        $classes = [];
        $offset = 0;
        while (($start = strpos($chat, 'ob_start()', $offset)) !== false) {
            $end = strpos($chat, 'ob_get_clean()', $start);
            $offset = $start + 10;
            if ($end === false) {
                continue;
            }
            $region = substr($chat, $start, $end - $start);
            $pattern = '/new\s+[\\\\A-Za-z0-9_]*?([A-Za-z_][A-Za-z0-9_]*Command)\s*\(/';
            if (preg_match_all($pattern, $region, $m) > 0) {
                foreach ($m[1] as $class) {
                    $classes[$class] = true;
                }
            }
        }

        $names = array_keys($classes);
        sort($names);

        return $names;
    }

    /**
     * Every file under `src/Commands` with a statement that reaches stdout.
     *
     * Token-based and RECURSIVE, which is the whole difference from the
     * `/^\s*echo\s/m` glob this replaced. `T_ECHO`/`T_PRINT` cover the two
     * language constructs wherever they sit on a line; the function list
     * covers the library routes. `fwrite`/`fputs` are only counted when the
     * same statement names `STDOUT` or `php://stdout`, since writing to a file
     * handle is not a transcript concern.
     *
     * @return list<string>
     */
    private static function stdoutEmittingCommandFiles(): array
    {
        $writers = ['printf', 'vprintf', 'readfile', 'fpassthru', 'var_dump', 'print_r'];
        $handleWriters = ['fwrite', 'fputs', 'file_put_contents'];

        $files = [];
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::commandsDir(), \FilesystemIterator::SKIP_DOTS)
        ) as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();
            $tokens = token_get_all((string) file_get_contents($path));
            $emits = false;

            foreach ($tokens as $i => $token) {
                if (!\is_array($token)) {
                    continue;
                }

                if ($token[0] === T_ECHO || $token[0] === T_PRINT) {
                    $emits = true;
                    break;
                }

                if ($token[0] !== T_STRING) {
                    continue;
                }

                $name = strtolower($token[1]);
                if (\in_array($name, $writers, true)) {
                    $emits = true;
                    break;
                }

                if (\in_array($name, $handleWriters, true)) {
                    $argv = self::callArguments($tokens, $i);
                    if (str_contains($argv, 'STDOUT') || str_contains($argv, 'php://stdout')) {
                        $emits = true;
                        break;
                    }
                }
            }

            if ($emits) {
                $files[] = $path;
            }
        }
        sort($files);

        return $files;
    }

    /**
     * The raw text of the argument list of the call whose name token is at
     * $index, for the `STDOUT` check above. Balanced-paren scan rather than a
     * regex, so a nested call in the first argument cannot end it early.
     */
    private static function callArguments(array $tokens, int $index): string
    {
        $text = '';
        $depth = 0;
        $started = false;

        for ($i = $index + 1, $n = \count($tokens); $i < $n; $i++) {
            $piece = \is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
            if ($piece === '(') {
                $depth++;
                $started = true;
            } elseif ($piece === ')') {
                if (--$depth <= 0) {
                    break;
                }
            } elseif (!$started && trim($piece) !== '') {
                // Not a call at all (a `use` import, a class-constant fetch).
                break;
            }
            $text .= $piece;
        }

        return $text;
    }

    private static function commandsDir(): string
    {
        return __DIR__ . '/../../src/Commands';
    }

    /**
     * The census is only trustworthy if it is checked. A new stdout-shaped
     * command must show up here — and be looked at — rather than quietly
     * joining a set nobody counted.
     */
    public function testTheCensusFindsEveryKnownStdoutShapedCommand(): void
    {
        $names = array_map(
            static fn (string $path): string => basename($path, '.php'),
            self::stdoutEmittingCommandFiles(),
        );

        $this->assertSame(
            ['AgentsCommand', 'McpAuthCommand', 'ShareCommand', 'WebSearchCommand'],
            $names,
            'a command that writes to stdout was added or removed; '
                . 'confirm its output is escape-free and update this list',
        );
    }

    /**
     * The two derivations must agree. A class captured by `Chat` that this
     * scan does not see as an emitter means the scan has a hole; the reverse
     * is fine — an emitter nobody captures yet is exactly what the wider net
     * is for.
     */
    public function testEveryCapturedCommandAlsoEmitsToStdout(): void
    {
        $captured = self::capturedCommandClasses();

        $this->assertSame(
            ['AgentsCommand', 'McpAuthCommand', 'ShareCommand', 'WebSearchCommand'],
            $captured,
            'Chat captures a different set of commands than this test believes; '
                . 'a new ob_start() site means a new class whose stdout is transcript',
        );

        $emitters = array_map(
            static fn (string $path): string => basename($path, '.php'),
            self::stdoutEmittingCommandFiles(),
        );

        foreach ($captured as $class) {
            $this->assertContains(
                $class,
                $emitters,
                $class . ' is captured by Chat but the stdout scan does not see it emitting — '
                    . 'the scan has a blind spot',
            );
        }
    }

    /**
     * @dataProvider transcriptCommandProvider
     */
    public function testCommandEmitsNoRawEscapeSequences(string $path): void
    {
        $this->assertFileExists($path);
        $source = (string) file_get_contents($path);

        $this->assertSame(
            0,
            preg_match_all('/\\\\033\[|\\\\e\[|\\\\x1b\[/i', $source),
            basename($path) . ' emits raw ANSI; its output is folded into the TUI transcript, '
                . 'where escapes surface as literal text like "[33m"',
        );
    }
}
