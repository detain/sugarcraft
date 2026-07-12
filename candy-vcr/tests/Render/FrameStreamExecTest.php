<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Render;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Player;
use SugarCraft\Vcr\Render\FrameStream;
use SugarCraft\Vcr\Tape\Compiler;
use SugarCraft\Vcr\Tape\Lexer;
use SugarCraft\Vcr\Tape\Parser;
use SugarCraft\Vcr\Tests\Support\RequiresWorkingPty;
use SugarCraft\Vt\Snapshot;
use SugarCraft\Vt\Terminal;

/**
 * Exec-mode proof: with `Set Shell`, FrameStream must actually RUN the typed
 * command under a real PTY and capture the program's OUTPUT into the frames —
 * not merely rasterize the keystrokes. This is what makes candy-vcr a real
 * `vhs` replacement for program-output demos.
 *
 * Gated on a working PTY layer (ext-ffi + /dev/ptmx), so FFI-less CI skips it —
 * the shell-less goldens still render there.
 *
 * Revert-proof: neuter the write/drain in {@see FrameStream::processInput()} /
 * {@see FrameStream::drainExec()} and the `vcr-ran-42` OUTPUT line disappears
 * (nothing evaluates the `$((6*7))`), failing this test.
 */
final class FrameStreamExecTest extends TestCase
{
    use RequiresWorkingPty;

    private const TAPE = __DIR__ . '/../fixtures/exec-echo.tape';

    public function testSetShellParsesToCompilerShell(): void
    {
        // Echo path is unaffected: parsing succeeds and the shell surfaces on the
        // compiler accessor (not on the serialized header). No PTY needed here.
        [, $compiler] = $this->compileTape();
        self::assertSame('sh', $compiler->shell());
    }

    public function testTapeWithoutSetShellStaysEcho(): void
    {
        // The opt-in boundary: a tape with no `Set Shell` compiles to shell()===
        // null, so the render takes the byte-identical echo path (no subprocess).
        // This is what keeps the committed goldens unchanged.
        $src = "Set Width 700\nSet Height 240\nSet FontSize 14\nType \"hi\"\nEnter\n";
        $compiler = new Compiler();
        $compiler->compile((new Parser())->parse((new Lexer())->tokenize($src)), 'x.tape');
        self::assertNull($compiler->shell());
    }

    public function testExecModeRendersProgramOutputIntoFrames(): void
    {
        $this->requirePtySyscalls();

        [$cassette, $compiler] = $this->compileTape();
        $shell = $compiler->shell();
        self::assertNotNull($shell);

        $terminal = Terminal::new($cassette->header->cols, $cassette->header->rows);
        $stream = new FrameStream(new Player($cassette), $terminal, 30.0, $shell);

        // Collect the distinct, non-blank line texts seen across ALL frames — the
        // program output arrives on the frames inside the trailing `Sleep 1s`.
        $lines = [];
        /** @var Snapshot $snapshot */
        foreach ($stream as $snapshot) {
            foreach ($this->frameLines($snapshot) as $line) {
                $lines[$line] = true;
            }
        }
        $seen = array_keys($lines);
        $dump = implode("\n", $seen);

        // The shell EXECUTED the command: only a shell that ran it evaluates the
        // arithmetic and prints `vcr-ran-42`. Echo-only rendering renders the
        // typed keystrokes literally (`...$((6*7))`) and can NEVER produce the
        // evaluated `42`, so this contiguous output line is unforgeable proof the
        // command RAN — the whole point of exec mode. (The echoed keystrokes are
        // not asserted: the interactive prompt interleaves with them on the frame
        // they're captured, which is timing-dependent; the output line is not.)
        self::assertTrue(
            $this->anyLineContains($seen, 'vcr-ran-42'),
            "expected the shell's OUTPUT `vcr-ran-42` (evaluated \$((6*7))); saw:\n{$dump}",
        );
    }

    /**
     * Compile the exec fixture, returning both the cassette and the compiler so
     * callers can read the resolved {@see Compiler::shell()} off a non-null local.
     *
     * @return array{0: \SugarCraft\Vcr\Cassette, 1: Compiler}
     */
    private function compileTape(): array
    {
        $source = file_get_contents(self::TAPE);
        self::assertIsString($source);
        $tokens = (new Lexer())->tokenize($source);
        $ast = (new Parser())->parse($tokens);
        $compiler = new Compiler();
        return [$compiler->compile($ast, self::TAPE), $compiler];
    }

    /**
     * Render one snapshot's cell grid to trimmed, non-blank line strings.
     *
     * @return list<string>
     */
    private function frameLines(Snapshot $snapshot): array
    {
        $grid = $snapshot->grid;
        $lines = [];
        for ($r = 0; $r < $grid->rows; $r++) {
            $text = '';
            for ($c = 0; $c < $grid->cols; $c++) {
                $char = $grid->get($r, $c)->char;
                $text .= ($char === "\0" || $char === '') ? ' ' : $char;
            }
            $text = rtrim($text);
            if ($text !== '') {
                $lines[] = $text;
            }
        }
        return $lines;
    }

    /**
     * @param list<string> $lines
     */
    private function anyLineContains(array $lines, string $needle): bool
    {
        foreach ($lines as $line) {
            if (str_contains($line, $needle)) {
                return true;
            }
        }
        return false;
    }
}
