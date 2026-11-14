<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;
use SugarCraft\Input\Driver\StreamInputDriver;
use SugarCraft\Readline\ConfirmationPrompt;
use SugarCraft\Readline\MultiSelectPrompt;
use SugarCraft\Readline\Readline;
use SugarCraft\Readline\SelectionPrompt;
use SugarCraft\Readline\TextareaPrompt;
use SugarCraft\Readline\TextPrompt;

/**
 * S-03 (finding 002): the four prompts that used to lack a display-width
 * surface now carry withDisplayWidth()/displayWidth() and clip every row
 * they render, so Readline::run() width adoption engages for all shipped
 * prompt shapes and no repaint row can wrap into the staircase.
 */
final class PromptWidthClampTest extends TestCase
{
    private const LONG_LABEL = 'Please pick the deployment target environment from this long label: ';
    private const LONG_CHOICE = '選択肢ラベルが非常に長い名前です、これは表示幅を確実に超えます';
    private const LONG_FILTER = 'abc-de-muy-largo-filtro-que-sigue-creciendo';

    /** @return array{0:resource,1:resource,2:resource,3:resource} in-write, in-read, out-write, out-read */
    private function makePairs(): array
    {
        $in = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $out = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        return [$in[1], $in[0], $out[1], $out[0]];
    }

    private function assertAllRowsFit(string $view, int $bound, string $context): void
    {
        foreach (explode("\n", $view) as $row) {
            $this->assertLessThanOrEqual(
                $bound,
                Width::of($row),
                $context . ': row exceeds bound (' . Width::of($row) . " > {$bound}): " . preg_replace('/\x1b\[[0-9;]*m/', '', $row)
            );
        }
    }

    // =========================================================================
    // SelectionPrompt
    // =========================================================================

    public function testSelectionRowsClippedToBound(): void
    {
        $p = SelectionPrompt::new(self::LONG_LABEL, [self::LONG_CHOICE, 'beta'])
            ->withDisplayWidth(40)
            ->withFilter(self::LONG_FILTER);
        $view = $p->view();
        $this->assertAllRowsFit($view, 40, 'selection bound 40');
        $this->assertStringContainsString('…', $view, 'over-long rows must mark the cut');
    }

    public function testSelectionCursorRowKeepsStyleInsideBound(): void
    {
        $p = SelectionPrompt::new('> ', [self::LONG_CHOICE])
            ->withDisplayWidth(20);
        $view = $p->view();
        $this->assertAllRowsFit($view, 20, 'selection bound 20');
        $this->assertStringContainsString("\x1b[7m❯ ", $view, 'cursor row keeps its reverse style when fitted');
    }

    public function testSelectionUnsetBoundRendersUnclamped(): void
    {
        $p = SelectionPrompt::new(self::LONG_LABEL, ['a']);
        $this->assertNull($p->displayWidth());
        $first = explode("\n", $p->view())[0];
        $this->assertGreaterThan(40, Width::of($first), 'no bound = legacy unbounded render');
        $this->assertStringNotContainsString('…', $p->view());
    }

    public function testSelectionExplicitZeroBoundUnbounded(): void
    {
        $p = SelectionPrompt::new(self::LONG_LABEL, [self::LONG_CHOICE])->withDisplayWidth(0);
        $this->assertSame(0, $p->displayWidth());
        $this->assertAllRowsFit($p->view(), PHP_INT_MAX, 'selection explicit 0');
        $this->assertStringNotContainsString('…', $p->view(), 'explicit 0 never clips');
    }

    // =========================================================================
    // MultiSelectPrompt
    // =========================================================================

    public function testMultiSelectRowsClippedToBound(): void
    {
        $p = MultiSelectPrompt::new(self::LONG_LABEL, [self::LONG_CHOICE, 'beta'])
            ->withDisplayWidth(30)
            ->withFilter(self::LONG_FILTER)
            ->withMinSelections(1)
            ->withMaxSelections(3);
        $view = $p->view();
        $this->assertAllRowsFit($view, 30, 'multi-select bound 30 (label/hint/bar/choices/footer)');
        $this->assertStringContainsString('[multi-select] selected:', $view, 'hint row still present, just fitted');
    }

    // =========================================================================
    // ConfirmationPrompt
    // =========================================================================

    public function testConfirmationRowClippedToBound(): void
    {
        $p = ConfirmationPrompt::new(self::LONG_LABEL . ' Really long question text', true)
            ->withDisplayWidth(24);
        $view = $p->view();
        $this->assertAllRowsFit($view, 24, 'confirmation bound 24');
        $this->assertStringContainsString('…', $view);
    }

    public function testConfirmationDropsTailSegmentsRatherThanWrapping(): void
    {
        $p = ConfirmationPrompt::new('Deploy?', true)
            ->withDisplayWidth(9);
        $view = $p->view();
        $this->assertAllRowsFit($view, 9, 'confirmation bound 9');
        $this->assertStringNotContainsString('[y/n]', $view, 'budget exhausted after label: hint segment dropped');
    }

    // =========================================================================
    // TextareaPrompt
    // =========================================================================

    public function testTextareaLinesClippedToBound(): void
    {
        $long = str_repeat('abcdefghij', 8); // 80 cells
        $p = TextareaPrompt::new(self::LONG_LABEL)
            ->withDefault($long . "\nsecond line " . str_repeat('ü', 40))
            ->withDisplayWidth(35);
        $view = $p->view();
        $this->assertAllRowsFit($view, 35, 'textarea bound 35 (label + both lines, cursor row)');
        $this->assertStringContainsString('…', $view);
    }

    // =========================================================================
    // Readline::run() adoption now engages for all five prompts
    // =========================================================================

    public function testRunAdoptsWidthForAllShippedPrompts(): void
    {
        $previous = getenv('COLUMNS');
        putenv('COLUMNS=32');
        try {
            $prompts = [
                'TextPrompt' => TextPrompt::new('> '),
                'SelectionPrompt' => SelectionPrompt::new('> ', ['a', 'b']),
                'MultiSelectPrompt' => MultiSelectPrompt::new('> ', ['a', 'b']),
                'ConfirmationPrompt' => ConfirmationPrompt::new('> '),
                'TextareaPrompt' => TextareaPrompt::new('> '),
            ];
            foreach ($prompts as $name => $prompt) {
                [$iw, $ir, $ow, $or] = $this->makePairs();
                fwrite($iw, "\r");
                fclose($iw);
                $driver = new StreamInputDriver($ir);
                $final = (new Readline($driver))->run($prompt, $ow);
                $this->assertSame(32, $final->displayWidth(), "{$name} must adopt COLUMNS via run()");
                fclose($ir);
                fclose($ow);
                fclose($or);
            }
        } finally {
            if ($previous === false) {
                putenv('COLUMNS');
            } else {
                putenv('COLUMNS=' . $previous);
            }
        }
    }

    public function testForeignPromptWithoutWidthSurfaceStillPassesThrough(): void
    {
        // The is_callable guard stays a BC seam: an object without the pair
        // must neither gain a bound nor break the run.
        $prompt = new class {
            public bool $submitted = false;

            public function view(): string
            {
                return 'foreign prompt ' . str_repeat('x', 80);
            }

            public function handleKey(string $key): self
            {
                if ($key === 'enter' || $key === "\r" || $key === "\n") {
                    $this->submitted = true;
                }
                return $this;
            }

            public function isSubmitted(): bool
            {
                return $this->submitted;
            }
        };

        [$iw, $ir, $ow, $or] = $this->makePairs();
        $previous = getenv('COLUMNS');
        putenv('COLUMNS=32');
        try {
            fwrite($iw, "\r");
            fclose($iw);
            $driver = new StreamInputDriver($ir);
            $final = (new Readline($driver))->run($prompt, $ow);
            $this->assertTrue($final->submitted);
            $this->assertFalse(method_exists($prompt, 'displayWidth'));
        } finally {
            if ($previous === false) {
                putenv('COLUMNS');
            } else {
                putenv('COLUMNS=' . $previous);
            }
            fclose($ir);
            fclose($ow);
            fclose($or);
        }
    }

    /**
     * Multi-paint desync regression: typing a 60-cell filter into a
     * SelectionPrompt bounded at 40 must keep EVERY painted frame row
     * within the bound — one wrapped row used to poison the CSI nA erase
     * maths for all later repaints.
     */
    public function testRepaintFramesNeverExceedBoundWhileTyping(): void
    {
        [$iw, $ir, $ow, $or] = $this->makePairs();
        $previous = getenv('COLUMNS');
        putenv('COLUMNS=40');
        try {
            fwrite($iw, self::LONG_FILTER . "\r");
            fclose($iw);
            $driver = new StreamInputDriver($ir);
            // First choice contains the filter as a prefix so every keystroke
            // keeps at least one match (a 0-match filter vetoes submit()).
            $prompt = SelectionPrompt::new('> ', [self::LONG_FILTER . '-tail', self::LONG_CHOICE])->withDisplayWidth(40);
            $final = (new Readline($driver))->run($prompt, $ow);
            $this->assertSame(40, $final->displayWidth());

            fclose($ow);
            $captured = '';
            while (false !== ($chunk = fread($or, 65536)) && $chunk !== '') {
                $captured .= $chunk;
            }
            fclose($ir);
            fclose($or);

            $frames = explode("\x1b[0J", $captured);
            $this->assertGreaterThan(2, count($frames), 'multiple repaint frames expected while typing');
            foreach (array_slice($frames, 1) as $frameNo => $frame) {
                foreach (explode("\n", $frame) as $row) {
                    $this->assertLessThanOrEqual(
                        40,
                        Width::of($row),
                        'frame #' . $frameNo . ' row exceeds the bound: ' . preg_replace('/\x1b\[[0-9;]*m/', '', $row)
                    );
                }
            }
            $this->assertStringContainsString('…', $captured, 'clipped frames carry the marker');
        } finally {
            if ($previous === false) {
                putenv('COLUMNS');
            } else {
                putenv('COLUMNS=' . $previous);
            }
        }
    }
}
