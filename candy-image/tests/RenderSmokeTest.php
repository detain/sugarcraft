<?php

/**
 * Golden-free render smokes for candy-image — a generated fixture goes
 * through the real CLI and the bytes land on the caller's stdout stream.
 * No terminal is faked: with STDOUT piped (every phpunit run), detection
 * answers from the env table and Mosaic's always-available floor renders,
 * so these pins assert the honest shape (exit 0, non-empty ANSI bytes) and
 * the animate pass-through wiring via the $play seam.
 *
 * Hermeticity note: {@see setUp()} pins the env keys the detection ladder
 * consults (see {@see HERMETIC_ENV_KEYS}) because a tmux-hosted runner's
 * exported TERM_PROGRAM/TERM_PROGRAM_VERSION alone would steer auto-format
 * onto the sixel passthrough regardless of TMUX or the piped STDOUT.
 */

declare(strict_types=1);

namespace SugarCraft\CandyImage\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Animation\Player;
use SugarCraft\Animation\Renderer;
use SugarCraft\CandyImage\Cli;

final class RenderSmokeTest extends TestCase
{
    /**
     * Env keys whose ambient values steer the auto-format ladder: TMUX and
     * TERM gate Detect's direct getenv() reads, and TERM_PROGRAM /
     * TERM_PROGRAM_VERSION are consulted by the F-01 TermDb table
     * ({@see \SugarCraft\Image\TermDb::snapshotEnv()}).
     *
     * WHY THESE FOUR: a tmux-hosted runner exports TERM_PROGRAM=tmux and
     * TERM_PROGRAM_VERSION into every pane, and the mux table rows answer
     * sixel from that pair even when TMUX is unset — so the naive
     * `env -u TMUX` shape still lands auto-format on the sixel passthrough
     * (the l11 audit's "not hermetic on tmux hosts" finding). Pinning the
     * whole consulted set to the documented no-graphics shape (TERM=dumb,
     * everything else absent) makes the ANSI floor deterministic without
     * touching the detector.
     */
    private const HERMETIC_ENV_KEYS = ['TERM', 'TERM_PROGRAM', 'TERM_PROGRAM_VERSION', 'TMUX'];

    /** @var array<string, string|false> pre-test getenv() snapshots, false = unset */
    private array $ambientEnv = [];

    /** @var list<string> */
    private array $artifacts = [];

    protected function setUp(): void
    {
        foreach (self::HERMETIC_ENV_KEYS as $key) {
            $this->ambientEnv[$key] = getenv($key);
        }
        putenv('TERM_PROGRAM');
        putenv('TERM_PROGRAM_VERSION');
        putenv('TMUX');
        putenv('TERM=dumb');
    }

    protected function tearDown(): void
    {
        foreach ($this->ambientEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
            } else {
                putenv($key . '=' . $value);
            }
        }
        $this->ambientEnv = [];

        foreach ($this->artifacts as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->artifacts = [];
    }

    /**
     * One-component temp fixture path, created exclusively.
     *
     * The old `tempnam(...) . '.png'` shape created the tempnam base file
     * AND a suffixed one, then tracked only the suffix — every suite run
     * orphaned a zero-byte `sc_candyimage_*` base in /tmp (candy-image-012).
     * The examples/ fixture is the canonical cure: random single name, 'x'
     * mode so a planted path is refused, registered for tearDown cleanup
     * before any failure-prone step can strand it.
     */
    private function uniqueTempPath(string $suffix): string
    {
        $path = sys_get_temp_dir() . '/candy-image-test-' . bin2hex(random_bytes(8)) . $suffix;
        $handle = fopen($path, 'x');
        self::assertIsResource($handle, "fixture path {$path} must be creatable exclusively");
        fclose($handle);
        $this->artifacts[] = $path;

        return $path;
    }

    /** @return array{resource, resource} fresh in-memory out/err pair */
    private static function pipes(): array
    {
        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');
        self::assertIsResource($stdout);
        self::assertIsResource($stderr);

        return [$stdout, $stderr];
    }

    /** @param resource $stream */
    private static function drain($stream): string
    {
        rewind($stream);

        return (string) stream_get_contents($stream);
    }

    private function pngFixture(): string
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('ext-gd is required to decode image fixtures.');
        }

        $path = $this->uniqueTempPath('.png');

        $im = imagecreatetruecolor(16, 8);
        for ($x = 0; $x < 16; ++$x) {
            for ($y = 0; $y < 8; ++$y) {
                imagesetpixel($im, $x, $y, imagecolorallocate($im, $x * 16, $y * 32, 128));
            }
        }
        imagepng($im, $path);
        imagedestroy($im);

        return $path;
    }

    private function gifFixture(): string
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('ext-gd is required to decode the fixture GIF.');
        }

        $path = $this->uniqueTempPath('.gif');

        $im = imagecreatetruecolor(4, 4);
        imagefill($im, 0, 0, imagecolorallocate($im, 10, 20, 30));
        imagegif($im, $path);
        imagedestroy($im);

        return $path;
    }

    public function testAutoFormatRendersAnsiBytesToStdout(): void
    {
        $png = $this->pngFixture();
        [$stdout, $stderr] = self::pipes();

        $exit = Cli::main(['candy-image', $png], $stdout, $stderr);

        $this->assertSame(Cli::EXIT_OK, $exit, self::drain($stderr));
        $bytes = self::drain($stdout);
        $this->assertNotSame('', $bytes);
        $this->assertStringContainsString("\x1b[", $bytes, 'the render must carry SGR styling');
        $this->assertStringEndsWith("\n", $bytes, 'stream output always ends on a fresh line');
        $this->assertSame('', self::drain($stderr));
    }

    public function testExplicitHalfAndQuarterFormatsRenderAtRequestedWidth(): void
    {
        $png = $this->pngFixture();

        // candy-image-014: bytes merely existed before. The box dial must
        // actually reach the renderer — measured truth on the 16x8 fixture:
        // width 8 stacks two cell rows, width 16 spreads to four (fit keeps
        // the aspect), and the two renders cannot be the same bytes.
        foreach (['half', 'quarter'] as $format) {
            $narrow = $this->renderBytes(['--format=' . $format, '--width=8', $png], $format . ' w8');
            $wide = $this->renderBytes(['--format=' . $format, '--width=16', $png], $format . ' w16');

            $this->assertNotSame($narrow, $wide, $format . ': --width must move the bytes');
            $this->assertCount(2, explode("\n", rtrim($narrow, "\n")), $format . ': width 8 renders two cell rows');
            $this->assertCount(4, explode("\n", rtrim($wide, "\n")), $format . ': width 16 renders four cell rows');
        }
    }

    /**
     * candy-image-007: the protocol wire formats were never rendered at all
     * in-repo. Each must emit its own preamble (and its closer) on the
     * caller's stdout even with TERM=dumb — an explicit --format commands
     * the wire, the env floor only steers auto.
     */
    public function testEveryWireFormatEmitsItsProtocolPreambleAndCloser(): void
    {
        $png = $this->pngFixture();

        $pins = [
            'sixel' => ["\x1bP0;1;0q", "\x1b\\\n"],
            'kitty' => ["\x1b_G", "\x1b\\\n"],
            'iterm2' => ["\x1b]1337", "\x07\n"],
            'ansi16' => ["\x1b[", "m\n"],
            'ansi256' => ["\x1b[", "m\n"],
            'truecolor' => ["\x1b[", "m\n"],
        ];

        foreach ($pins as $format => [$preamble, $closer]) {
            $bytes = $this->renderBytes(['--format=' . $format, $png], $format);
            $this->assertStringStartsWith($preamble, $bytes, $format . ': protocol preamble');
            $this->assertStringEndsWith($closer, $bytes, $format . ': protocol closer + final newline');
        }
    }

    /**
     * The doc's alias column is a promise: each alias must render byte for
     * byte the same as its canonical spelling.
     */
    public function testFormatAliasesRenderByteIdenticalToTheirCanons(): void
    {
        $png = $this->pngFixture();

        foreach ([['half', 'halfblock'], ['quarter', 'quarterblock'], ['symbols', 'symbol'], ['symbols', 'sym']] as [$canon, $alias]) {
            $a = $this->renderBytes(['--format=' . $canon, '--width=8', $png], $canon);
            $b = $this->renderBytes(['--format=' . $alias, '--width=8', $png], $alias);
            $this->assertSame($a, $b, "--format=$alias must equal --format=$canon verbatim");
        }
    }

    /** @param list<string> $args */
    private function renderBytes(array $args, string $label): string
    {
        [$stdout, $stderr] = self::pipes();
        $exit = Cli::main(array_merge(['candy-image'], $args), $stdout, $stderr);
        $this->assertSame(Cli::EXIT_OK, $exit, $label . ': ' . self::drain($stderr));
        $bytes = self::drain($stdout);
        $this->assertNotSame('', $bytes, $label);

        return $bytes;
    }

    public function testAsciiFormatRendersCellTextWithoutTruncatingTheImageBox(): void
    {
        $png = $this->pngFixture();
        [$stdout, $stderr] = self::pipes();

        $exit = Cli::main(['candy-image', '--format=ascii', '--width=8', '--height=4', $png], $stdout, $stderr);

        $this->assertSame(Cli::EXIT_OK, $exit, self::drain($stderr));
        $bytes = self::drain($stdout);
        $rows = explode("\n", rtrim($bytes, "\n"));
        // candy-image-008: the old >=2 floor survived any truncation. The
        // requested 8x4 box must arrive exactly: four rows of eight cells.
        $this->assertCount(4, $rows, 'the requested height must reach the text box verbatim');
        $printable = [];
        foreach ($rows as $i => $row) {
            $stripped = preg_replace('/\e\[[0-9;?]*[a-zA-Z]/', '', (string) $row);
            $this->assertLessThanOrEqual(8, mb_strwidth($stripped), "row $i exceeds the requested width");
            $printable[] = $stripped;
        }
        // The gradient fixture guarantees contrast bins, so a healthy render
        // is not one flat glyph repeated four times.
        $this->assertGreaterThanOrEqual(2, count(array_unique($printable)), 'ascii must draw distinct glyphs, not one flat character');
    }

    public function testSymbolsTierRendersAndTheReachA04DialsMoveTheBytes(): void
    {
        $png = $this->pngFixture();

        $render = static function (array $args) use ($png): string {
            [$stdout, $stderr] = self::pipes();
            $exit = Cli::main(array_merge(['candy-image'], $args, [$png]), $stdout, $stderr);
            self::assertSame(Cli::EXIT_OK, $exit, self::drain($stderr));
            $bytes = self::drain($stdout);
            self::assertStringContainsString("\x1b[", $bytes, 'symbols output carries SGR styling');

            return $bytes;
        };

        $default = $render(['--format=symbols', '--width=6']);
        $selected = $render(['--format=symbols', '--width=6', '--symbols=braille']);
        $quantized = $render(['--format=symbols', '--width=6', '--canvas=ansi16']);

        // The work dial is pinned at an explicit 6x3 box, NOT at the auto-derived
        // 6x2 box of this fixture: a per-candidate observer probe (2026-11-10)
        // showed that since the tag-algebra selection re-ordering, the greedy
        // top-k search already lands on the exhaustive optimum in every cell of
        // the 6x2 geometry — identical winning symbols AND identical errors at
        // --work=1 vs --work=9 — so a correct search cannot move bytes there.
        // At 6x3 the budget genuinely binds (greedy error 22272 vs exhaustive
        // 19840), which is what proves the flag reaches the renderer's search.
        $budgetBase = $render(['--format=symbols', '--width=6', '--height=3']);
        $exhaustive = $render(['--format=symbols', '--width=6', '--height=3', '--work=9']);

        $this->assertNotSame($default, $selected, '--symbols=braille must change the corpus');
        $this->assertNotSame($default, $quantized, '--canvas=ansi16 must change the pens');
        $this->assertNotSame($budgetBase, $exhaustive, '--work=9 must reach the exhaustive search');
    }

    /**
     * candy-image-015: accepting --scale is not the contract — the dial must
     * move the rendered bytes. On the 2:1 fixture inside a 6x2 box,
     * stretch and none cannot agree (distortion vs padding), and fit and
     * fill cannot agree (contain vs cover).
     */
    public function testScaleFlagMovesTheRenderedBytesOnTheRenderPath(): void
    {
        $png = $this->pngFixture();

        $render = fn (string $scale): string => $this->renderBytes(
            ['--format=half', '--scale=' . $scale, '--size=6x2', $png],
            'scale=' . $scale,
        );

        $this->assertNotSame($render('stretch'), $render('none'), '--scale=stretch must differ from --scale=none');
        $this->assertNotSame($render('fit'), $render('fill'), '--scale=fit must differ from --scale=fill on a 2:1 fixture in a 6x2 box');
    }

    public function testAnimatePassThroughWiresThePlayerThroughTheSeam(): void
    {
        $gif = $this->gifFixture();
        [$stdout, $stderr] = self::pipes();

        $seen = null;
        $exit = Cli::main(
            ['candy-image', '--animate', $gif],
            $stdout,
            $stderr,
            static function (Player $player) use (&$seen): int {
                $seen = $player;

                return 0;
            },
        );

        $this->assertSame(Cli::EXIT_OK, $exit, self::drain($stderr));
        $this->assertInstanceOf(Player::class, $seen);
        $this->assertNotEmpty($seen->frames, 'the fixture GIF must decode to at least one frame');
        $this->assertInstanceOf(Renderer::class, $seen->renderer);
        $this->assertSame('', self::drain($stdout), 'the play seam never writes to stdout');
    }

    /**
     * candy-image-016: the animate box orientation contract. `--size=WxH`
     * names cells-wide × cells-high, while Renderer::withConstraints takes
     * its pair ROWS-first — the one transposition the whole suite could not
     * see. 30x10 must land as 10 rows × 30 columns on the wired renderer.
     */
    public function testAnimateSizeReachesTheRendererRowsFirst(): void
    {
        $gif = $this->gifFixture();
        [$stdout, $stderr] = self::pipes();

        $player = null;
        $exit = Cli::main(
            ['candy-image', '--animate', '--size=30x10', $gif],
            $stdout,
            $stderr,
            static function (Player $p) use (&$player): int {
                $player = $p;

                return 0;
            },
        );

        $this->assertSame(Cli::EXIT_OK, $exit, self::drain($stderr));
        $this->assertInstanceOf(Player::class, $player);

        $rows = (new \ReflectionProperty($player->renderer, 'adaptiveRows'))->getValue($player->renderer);
        $cols = (new \ReflectionProperty($player->renderer, 'adaptiveCols'))->getValue($player->renderer);
        $this->assertSame(10, $rows, 'the H leg of --size becomes the row constraint');
        $this->assertSame(30, $cols, 'the W leg of --size becomes the column constraint');
    }
}
