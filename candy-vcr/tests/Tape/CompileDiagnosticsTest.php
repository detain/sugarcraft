<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Tape;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SugarCraft\Vcr\Cassette;
use SugarCraft\Vcr\Cli\Application;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Tape\Compiler;
use SugarCraft\Vcr\Tape\Lexer;
use SugarCraft\Vcr\Tape\Parser;
use SugarCraft\Vcr\Tests\Support\Stream;

/**
 * The compile diagnostics channel (findings 024 / 025 / 100 / 105 / 107):
 * nothing a tape asked for may vanish without a name. Every drop — unknown
 * directives, unencodable chars, cycle/depth-skipped includes, the include's
 * own parse errors — lands in warnings() AND the content-losing
 * droppedDiagnostics() list, the CLI prints them instead of a clean success,
 * and --strict (render AND --dry-run alike) aborts on the first one.
 */
final class CompileDiagnosticsTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/vcr-diagnostics-' . getmypid() . '-' . bin2hex(random_bytes(3));
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmpDir);
    }

    private function write(string $name, string $content): string
    {
        $path = $this->tmpDir . '/' . $name;
        file_put_contents($path, $content);

        return $path;
    }

    /**
     * @return array{0: Cassette, 1: Compiler}
     */
    private function compileTape(string $tapePath, bool $strict = false): array
    {
        $ast = (new Parser())->parse((new Lexer())->tokenize(Stream::read($tapePath)));
        $compiler = new Compiler();
        $cassette = $compiler->compile($ast, $tapePath, $strict);

        return [$cassette, $compiler];
    }

    public function testTopLevelUnknownDirectiveIsBookedInDroppedChannel(): void
    {
        $tape = $this->write('broken.tape', "Type \"ok\"\nsleep 1s\n");
        [$cassette, $compiler] = $this->compileTape($tape);

        $this->assertCount(1, $compiler->droppedDiagnostics());
        $this->assertStringContainsString('Unknown directive: sleep', $compiler->droppedDiagnostics()[0]);
        $this->assertStringContainsString('Parse error at line 2', $compiler->warnings()[0]);
        $this->assertSame('ok', $this->typedBytes($cassette), 'the valid directive still compiled');
    }

    public function testUnencodableTypeCharIsNamedNotEvaporated(): void
    {
        // \x9b (C1 control) sits inside the quoted run and used to disappear
        // mid-Type with the rest of the line continuing as if nothing was
        // lost (finding 107).
        $tape = $this->write('c1.tape', "Type \"a\x9bb\"\n");
        [$cassette, $compiler] = $this->compileTape($tape);

        $this->assertCount(1, $compiler->droppedDiagnostics());
        $this->assertStringContainsString('dropped 1 unencodable character(s)', $compiler->droppedDiagnostics()[0]);
        $this->assertSame('ab', $this->typedBytes($cassette), 'the run continues around the named drop');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unencodable');
        $this->compileTape($tape, true);
    }

    public function testMutualSourceCycleReportsInsteadOfSkippingSilently(): void
    {
        $this->write('a.tape', "Type \"A\"\nSource b.tape\n");
        $this->write('b.tape', "Type \"B\"\nSource a.tape\n");

        [, $compiler] = $this->compileTape($this->tmpDir . '/a.tape');
        $cycle = null;
        foreach ($compiler->droppedDiagnostics() as $message) {
            if (str_contains($message, 'cycle detected — skipped')) {
                $cycle = $message;
            }
        }
        $this->assertNotNull($cycle, 'the second hop of a mutual Source pair must name itself (finding 105)');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cycle detected');
        $this->compileTape($this->tmpDir . '/a.tape', true);
    }

    public function testSubIncludeParseErrorsSurfaceNotEvaporate(): void
    {
        $this->write('host.tape', "Type \"h\"\nSource guest.tape\n");
        $this->write('guest.tape', "nonsense 1s\nType \"g\"\n");

        [$cassette, $compiler] = $this->compileTape($this->tmpDir . '/host.tape');
        $this->assertCount(1, $compiler->droppedDiagnostics(), 'the include’s own broken line is booked');
        $this->assertStringContainsString('Source include', $compiler->droppedDiagnostics()[0]);
        $this->assertStringContainsString('Unknown directive: nonsense', $compiler->droppedDiagnostics()[0]);
        $this->assertSame('hg', $this->typedBytes($cassette), 'the valid lines of BOTH files compile');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown directive: nonsense');
        $this->compileTape($this->tmpDir . '/host.tape', true);
    }

    public function testDryRunDefaultPrintsWarningAndStillEmitsStream(): void
    {
        $tape = $this->write('dr.tape', "Type \"ok\"\nsleep 1s\n");
        [$exit, $stdout, $stderr] = $this->runCli(['render-tape', $tape, '--dry-run']);

        $this->assertSame(0, $exit, 'default mode completes — with a voice: ' . $stderr);
        $this->assertStringContainsString('Unknown directive: sleep', $stderr, 'the drop prints on stderr, never inside the JSONL stream');
        $this->assertStringNotContainsString('Warning', $stdout, 'stdout stays machine-readable');
        $this->assertStringContainsString('"b":"o"', $stdout, 'the valid Type still emits its per-char events');
    }

    public function testDryRunStrictExitsNonZeroOnUnknownClass(): void
    {
        $tape = $this->write('drs.tape', "Type \"ok\"\nsleep 1s\n");
        [$exit, $stdout, $stderr] = $this->runCli(['render-tape', $tape, '--dry-run', '--strict']);

        $this->assertSame(1, $exit, '--dry-run --strict must abort where plain --dry-run warns');
        $this->assertStringContainsString('Failed:', $stdout . $stderr);
        $this->assertStringContainsString('sleep', $stdout . $stderr);
    }

    public function testRenderDefaultSurfacesDroppedDirectiveAsWarning(): void
    {
        $tape = $this->write('rd.tape', "Type \"ok\"\nsleep 1s\n");
        [$exit, $stdout, $stderr] = $this->runCli(['render-tape', $tape, '-o', $this->tmpDir . '/out.gif']);

        // The GIF may fail for environmental reasons (no GD/fonts); either
        // way a directive was dropped, so a clean silent success is the lie
        // under test — find the warning in whatever channel answered.
        $combined = $stdout . $stderr;
        if ($exit === 0) {
            $this->assertStringContainsString('Unknown directive: sleep', $combined);
        } else {
            $this->assertStringContainsString('sleep', $combined, 'a failing render still names the dropped directive');
        }
    }

    /**
     * @param list<string> $args
     * @return array{0: int, 1: string, 2: string}
     */
    private function runCli(array $args): array
    {
        $stdout = Stream::memory('w+');
        $stderr = Stream::memory('w+');
        $this->assertNotFalse($stdout);
        $this->assertNotFalse($stderr);

        $exit = (new Application())->run(array_merge(['candy-vcr'], $args), $stdout, $stderr);

        rewind($stdout);
        rewind($stderr);

        return [$exit, (string) stream_get_contents($stdout), (string) stream_get_contents($stderr)];
    }

    private function typedBytes(Cassette $cassette): string
    {
        $bytes = '';
        foreach ($cassette->events as $event) {
            if ($event->kind === EventKind::Input) {
                $bytes .= (string) $event->payload['b'];
            }
        }

        return $bytes;
    }
}
