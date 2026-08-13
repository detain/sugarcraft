<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Renderer;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Renderer;
use SugarCraft\Crush\ToolResult;
use SugarCraft\Mosaic\Capability;
use SugarCraft\Mosaic\Mosaic;
use SugarCraft\Mosaic\Renderer\HalfBlockRenderer;
use SugarCraft\Mosaic\Renderer\Renderer as MosaicRenderer;
use SugarCraft\Mosaic\Renderer\SixelRenderer;
use SugarCraft\Mosaic\TmuxPassthroughDecorator;

/**
 * crush_feat.md §9 E5 — tmux passthrough for image-bearing tool results.
 *
 * E5's claim is that nothing needs implementing: candy-mosaic already wraps
 * the chosen renderer in {@see TmuxPassthroughDecorator} whenever `$TMUX` is
 * set, so sugar-crush inherits passthrough for free. This file is the
 * integration test that pins that claim down end-to-end, because the failure
 * it guards against is invisible to unit tests of either side: candy-mosaic
 * wrapping correctly and sugar-crush rendering correctly can still combine
 * into raw DCS bytes being handed to tmux (which swallows them, leaving a
 * blank box), if the two are composed wrongly at the seam.
 *
 * Two layers of coverage:
 *
 * 1. In-process, deterministic: {@see Renderer::renderView()} driven with a
 *    Mosaic wrapped exactly the way {@see Mosaic::probe()} wraps it under
 *    tmux, asserting on the real placement bytes.
 * 2. A real `tmux` session (skipped when no tmux binary is present) running
 *    the real {@see Mosaic::auto()} probe, proving that the `$TMUX` variable
 *    tmux itself exports is what flips the behaviour — not a test fixture.
 *
 * Fixtures expand the tool call deliberately: under F.IMGROW a collapsed
 * image is never decoded, encoded, or placed, so every byte-level assertion
 * here is about the expanded state by construction. The collapsed state gets
 * its own test asserting the notice instead.
 *
 * @see TmuxPassthroughDecorator
 * @see Renderer::renderView()
 */
final class ImageRenderingTest extends TestCase
{
    /** First Private-Use-Area codepoint candy-core's ImageOverlay uses as a marker. */
    private const MARKER = "\u{E000}";

    /** The tmux passthrough envelope's opening bytes: `ESC P tmux ;`. */
    private const TMUX_ENVELOPE = "\x1bPtmux;";

    /** Any DCS introducer: `ESC P`. Must never appear in the diffed text body. */
    private const DCS = "\x1bP";

    /** Cached JSON reports from the real-tmux subprocess runs, keyed by mode. */
    private static array $subprocessReports = [];

    protected function setUp(): void
    {
        if (!\extension_loaded('gd')) {
            $this->markTestSkipped('candy-mosaic decodes images through ext-gd');
        }
    }

    // =========================================================================
    // In-process: the composed byte stream
    // =========================================================================

    /**
     * The step-defining assertion: under tmux the Sixel blob that rides out on
     * the View's image layer is inside tmux's passthrough envelope, with every
     * inner ESC doubled, so tmux forwards it to the outer terminal instead of
     * eating it.
     */
    public function testTmuxWrappedSixelPlacementCarriesThePassthroughEnvelope(): void
    {
        $view = Renderer::renderView($this->chatWithImage($this->tmuxMosaic(new SixelRenderer())));
        $placement = array_values($view->images)[0];

        $this->assertStringStartsWith(self::TMUX_ENVELOPE, $placement->bytes);
        $this->assertStringEndsWith("\x1b\\", $placement->bytes, 'the envelope must be ST-terminated');
        $this->assertStringContainsString(
            "\x1b\x1bP",
            $placement->bytes,
            'tmux requires every ESC inside the payload to be doubled',
        );
    }

    /**
     * The differential that makes the test above mean something: the exact same
     * chat and the exact same bytes, rendered without the decorator, produce a
     * bare `ESC P …q` Sixel blob and no envelope at all.
     */
    public function testTheSamePlacementOutsideTmuxIsRawSixelWithNoEnvelope(): void
    {
        $view = Renderer::renderView($this->chatWithImage(Mosaic::sixel()));
        $placement = array_values($view->images)[0];

        $this->assertStringStartsWith(self::DCS, $placement->bytes);
        $this->assertStringNotContainsString(self::TMUX_ENVELOPE, $placement->bytes);
        $this->assertStringNotContainsString("\x1b\x1b", $placement->bytes);
    }

    /**
     * Wrapping must not change WHERE the blob goes. The envelope is still an
     * out-of-band DCS sequence, so it stays on the image layer and the text
     * frame keeps only the one-cell marker block — concatenating it into the
     * body would corrupt candy-core's line diff exactly as the unwrapped blob
     * would.
     */
    public function testTheEnvelopeStaysOnTheImageLayerAndOutOfTheDiffedBody(): void
    {
        $view = Renderer::renderView($this->chatWithImage($this->tmuxMosaic(new SixelRenderer())));

        $this->assertCount(1, $view->images);
        $this->assertStringContainsString(self::MARKER, $view->body);
        $this->assertStringNotContainsString(self::DCS, $view->body);
        $this->assertStringNotContainsString('tmux;', $view->body);
    }

    /**
     * The decorator wraps DCS/APC/OSC and nothing else, so an inline renderer
     * under tmux is untouched: half-block cells are ordinary SGR-styled text
     * that tmux already forwards, and they must keep going straight into the
     * frame with no image layer and no envelope.
     */
    public function testInlineHalfBlockCellsAreLeftUnwrappedUnderTmux(): void
    {
        $mosaic = $this->tmuxMosaic(new HalfBlockRenderer(), Capability::unknown(null, true));
        $view = Renderer::renderView($this->chatWithImage($mosaic));

        $this->assertTrue($mosaic->isInline(), 'the decorator must delegate isInline() to its inner renderer');
        $this->assertSame([], $view->images, 'inline cells need no image layer');
        $this->assertStringContainsString('▀', $view->body);
        $this->assertStringNotContainsString('tmux;', $view->body);
        $this->assertStringNotContainsString(self::DCS, $view->body);
    }

    /**
     * F.IMGROW's collapse policy outranks the protocol: a collapsed picture is
     * not decoded, not encoded and not wrapped, so a transcript full of
     * screenshots costs one faint line each per frame even inside tmux.
     */
    public function testCollapsedImageUnderTmuxIsNeverEncodedOrWrapped(): void
    {
        $view = Renderer::renderView($this->chatWithImage($this->tmuxMosaic(new SixelRenderer()), expanded: false));

        $this->assertSame([], $view->images);
        $this->assertStringContainsString('image hidden', $view->body);
        $this->assertStringNotContainsString('tmux;', $view->body);
        $this->assertStringNotContainsString(self::DCS, $view->body);
    }

    /**
     * Regression guard on the encode memo. {@see Renderer} caches encoded
     * pictures across frames keyed by bytes + box + protocol; drop the protocol
     * from that key and a session that renders the same image bare and then
     * under tmux (or vice versa) serves the wrong variant from cache — the
     * user sees a blank box and nothing in either library is at fault.
     */
    public function testTheEncodeCacheNeverServesBareSixelBytesToATmuxSession(): void
    {
        $bytes = $this->pngBytes(24, 12);

        $bare = array_values(Renderer::renderView($this->chatWithImage(Mosaic::sixel(), bytes: $bytes))->images)[0];
        $wrapped = array_values(
            Renderer::renderView($this->chatWithImage($this->tmuxMosaic(new SixelRenderer()), bytes: $bytes))->images,
        )[0];

        $this->assertStringNotContainsString(self::TMUX_ENVELOPE, $bare->bytes);
        $this->assertStringStartsWith(self::TMUX_ENVELOPE, $wrapped->bytes);
        $this->assertSame($bare->widthCells, $wrapped->widthCells, 'wrapping must not change the cell footprint');
        $this->assertSame($bare->heightCells, $wrapped->heightCells);
    }

    // =========================================================================
    // Real tmux: the probe, not a fixture, is what flips the behaviour
    // =========================================================================

    /**
     * End-to-end inside a real `tmux` server: sugar-crush's own render path,
     * driven by the real {@see Mosaic::auto()} probe, with `$TMUX` exported by
     * tmux itself. Nothing in the child process knows it is being tested.
     */
    public function testRealTmuxSessionMakesMosaicAutoSelectThePassthroughDecorator(): void
    {
        $report = $this->subprocessReport(inTmux: true);

        $this->assertTrue($report['tmux'], 'tmux must export $TMUX into the pane');
        $this->assertStringStartsWith('tmux(', $report['protocol']);
        $this->assertStringEndsWith(')', $report['protocol']);
    }

    /**
     * The same script, same env, same image — run outside tmux. The only
     * difference between the two runs is `$TMUX`, which is precisely E5's
     * claim: passthrough is already handled, and it is handled by the probe.
     */
    public function testRealTmuxWrapsThePlacementWhileTheIdenticalRunOutsideDoesNot(): void
    {
        $inside = $this->subprocessReport(inTmux: true);
        $outside = $this->subprocessReport(inTmux: false);

        $this->assertSame(1, $inside['placements']);
        $this->assertSame(1, $outside['placements']);

        $this->assertTrue($inside['wrapped'], 'inside tmux the placement must carry the passthrough envelope');
        $this->assertTrue($inside['doubled'], 'inside tmux the inner ESC bytes must be doubled');
        $this->assertFalse($outside['wrapped']);
        $this->assertFalse($outside['doubled']);

        // Whichever side of the fence, the blob never lands in the text frame.
        foreach ([$inside, $outside] as $report) {
            $this->assertTrue($report['marker'], 'the frame must reserve the image box with a PUA marker');
            $this->assertFalse($report['bodyDcs'], 'no DCS blob may reach the line-diffed body');
        }
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    /**
     * A Mosaic composed exactly the way {@see Mosaic::probe()} composes one
     * when `Capability::inTmux` is true — the decorator around the protocol
     * renderer, nothing else changed.
     */
    private function tmuxMosaic(MosaicRenderer $inner, ?Capability $capability = null): Mosaic
    {
        return new Mosaic(
            new TmuxPassthroughDecorator($inner),
            $capability ?? Capability::sixel(null, true),
            null,
            null,
            null,
        );
    }

    /** A real, decodable PNG — candy-mosaic rejects anything it cannot decode. */
    private function pngBytes(int $width = 20, int $height = 10): string
    {
        $gd = imagecreatetruecolor($width, $height);
        imagefilledrectangle($gd, 0, 0, $width - 1, $height - 1, (int) imagecolorallocate($gd, 200, 30, 30));
        ob_start();
        imagepng($gd);

        return (string) ob_get_clean();
    }

    private function chatWithImage(Mosaic $mosaic, bool $expanded = true, ?string $bytes = null): Chat
    {
        $result = new ToolResult(
            name: 'Doctor',
            result: 'terminal capability report',
            id: 'call_img',
            imageBytes: $bytes ?? $this->pngBytes(),
        );

        return new Chat(
            history: [Message::user('/doctor'), Message::assistant('')->withToolResults([$result])],
            rows: 40,
            cols: 80,
            expanded: $expanded ? ['call_img' => true] : [],
            mosaic: $mosaic,
        );
    }

    // =========================================================================
    // Real-tmux harness
    // =========================================================================

    /**
     * Render one image-bearing frame in a child process — inside a private
     * tmux server, or plainly — and return its JSON self-report.
     *
     * Memoized per mode: spawning tmux is the expensive part and both real-tmux
     * tests read the same two reports.
     *
     * @return array{tmux: bool, protocol: string, placements: int, wrapped: bool, doubled: bool, marker: bool, bodyDcs: bool}
     */
    private function subprocessReport(bool $inTmux): array
    {
        $mode = $inTmux ? 'tmux' : 'bare';
        if (isset(self::$subprocessReports[$mode])) {
            return self::$subprocessReports[$mode];
        }

        $this->requireTmuxSyscalls();

        $script = (string) tempnam(sys_get_temp_dir(), 'crush-img-script-');
        $out = (string) tempnam(sys_get_temp_dir(), 'crush-img-report-');
        file_put_contents($script, $this->childScript());

        try {
            $inTmux ? $this->runInsideTmux($script, $out) : $this->runOutsideTmux($script, $out);

            $raw = (string) file_get_contents($out);
            $report = json_decode($raw, true);
            $this->assertIsArray($report, "child process wrote no report; raw output: {$raw}");
        } finally {
            @unlink($script);
            @unlink($out);
        }

        return self::$subprocessReports[$mode] = $report;
    }

    /**
     * The child process: build an image-bearing tool result, probe with the
     * real {@see Mosaic::auto()}, render one frame through sugar-crush's own
     * {@see Renderer::renderView()}, and report on the composed bytes.
     *
     * `TERM`/`XTERM_VERSION` pin the probe to Sixel so the run is comparable
     * across hosts; nothing about `$TMUX` is set here — that is tmux's job, and
     * the whole point of the test.
     */
    private function childScript(): string
    {
        $autoload = var_export(\dirname(__DIR__, 2) . '/vendor/autoload.php', true);

        return <<<PHP
            <?php

            declare(strict_types=1);

            require {$autoload};

            \$gd = imagecreatetruecolor(20, 10);
            imagefilledrectangle(\$gd, 0, 0, 19, 9, (int) imagecolorallocate(\$gd, 200, 30, 30));
            ob_start();
            imagepng(\$gd);
            \$bytes = (string) ob_get_clean();

            \$mosaic = \SugarCraft\Mosaic\Mosaic::auto();
            \$result = new \SugarCraft\Crush\ToolResult(
                name: 'Doctor',
                result: 'terminal capability report',
                id: 'call_img',
                imageBytes: \$bytes,
            );
            \$chat = new \SugarCraft\Crush\Chat(
                history: [\SugarCraft\Crush\Message::assistant('')->withToolResults([\$result])],
                rows: 40,
                cols: 80,
                expanded: ['call_img' => true],
                mosaic: \$mosaic,
            );

            \$view = \SugarCraft\Crush\Renderer::renderView(\$chat);
            \$placement = array_values(\$view->images)[0] ?? null;

            file_put_contents((string) getenv('CRUSH_IMAGE_REPORT'), (string) json_encode([
                'tmux' => getenv('TMUX') !== false,
                'protocol' => \$mosaic->protocol(),
                'placements' => count(\$view->images),
                'wrapped' => \$placement !== null && str_starts_with(\$placement->bytes, "\\x1bPtmux;"),
                'doubled' => \$placement !== null && str_contains(\$placement->bytes, "\\x1b\\x1bP"),
                'marker' => str_contains(\$view->body, "\\u{E000}"),
                'bodyDcs' => str_contains(\$view->body, "\\x1bP"),
            ]));
            PHP;
    }

    /**
     * Run the child in a detached pane on a private tmux server, so an existing
     * user/CI tmux server is neither joined nor killed.
     *
     * stdin is /dev/null and stdout a file on purpose: that makes candy-mosaic's
     * `Detect` treat the process as non-interactive and skip its DA1/XTWINOPS
     * TTY round-trips, which inside a pane would be answered by tmux itself and
     * make the run both slow and host-dependent. `$TMUX` is untouched.
     */
    private function runInsideTmux(string $script, string $out): void
    {
        $socket = 'crush-w5b-' . getmypid();
        $command = 'env ' . implode(' ', array_map(escapeshellarg(...), [
            'TERM=xterm',
            'XTERM_VERSION=XTerm(370)',
            'CRUSH_IMAGE_REPORT=' . $out,
        ])) . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' < /dev/null > /dev/null 2>&1';

        try {
            $this->runQuietly([
                $this->tmuxBinary(), '-L', $socket, '-f', '/dev/null',
                'new-session', '-d', '-s', 'crush-image', $command,
            ]);
            $this->awaitReport($out);
        } finally {
            $this->runQuietly([$this->tmuxBinary(), '-L', $socket, 'kill-server']);
        }
    }

    /** The control run: same script, same env, explicitly no `$TMUX`. */
    private function runOutsideTmux(string $script, string $out): void
    {
        $env = getenv();
        unset($env['TMUX']);
        $env['TERM'] = 'xterm';
        $env['XTERM_VERSION'] = 'XTerm(370)';
        $env['CRUSH_IMAGE_REPORT'] = $out;

        $this->runQuietly([PHP_BINARY, $script], $env);
        $this->awaitReport($out);
    }

    /**
     * Wait for the child to finish writing its report.
     *
     * `tmux new-session -d` returns as soon as the pane exists, so the pane's
     * command is still running when it does; there is nothing to wait() on.
     */
    private function awaitReport(string $out): void
    {
        for ($i = 0; $i < 600; $i++) {
            if (filesize($out) > 0) {
                return;
            }
            clearstatcache(true, $out);
            usleep(50_000);
        }

        $this->fail('child process produced no image report within 30s');
    }

    /**
     * @param list<string>              $command
     * @param array<string,string>|null $env
     */
    private function runQuietly(array $command, ?array $env = null): void
    {
        $descriptors = [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']];
        $process = @proc_open($command, $descriptors, $pipes, null, $env);

        if (\is_resource($process)) {
            proc_close($process);
        }
    }

    /** Skip rather than assert something weaker when this host has no usable tmux. */
    private function requireTmuxSyscalls(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('tmux passthrough is a POSIX-terminal concern.');
        }
        if (!\function_exists('proc_open')) {
            $this->markTestSkipped('proc_open() is disabled; cannot spawn a tmux session.');
        }
        if ($this->tmuxBinary() === null) {
            $this->markTestSkipped('no tmux binary on this host; the real-multiplexer leg cannot run.');
        }
        if (!is_writable(sys_get_temp_dir())) {
            $this->markTestSkipped('the temp dir is unwritable; the child has nowhere to report.');
        }
    }

    private function tmuxBinary(): ?string
    {
        foreach (['/usr/bin/tmux', '/usr/local/bin/tmux', '/bin/tmux', '/opt/homebrew/bin/tmux'] as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
