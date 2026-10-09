<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui\MediaForm;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Width;
use SugarCraft\Crush\Media\MediaRequest;
use SugarCraft\Crush\Theme;
use SugarCraft\Crush\Tui\MediaForm\MediaForm;

/**
 * The media form shell's frame (crush-media W3.1, Shape A): a full band of
 * its own drawn by the App renderer — the Veil composite chain in
 * src/Renderer.php is never entered, so its painted==routed law (and
 * KeyHelpTest with it) holds untouched while the form is up.
 *
 * Regenerate with `UPDATE_GOLDENS=1` after an intended visual change.
 */
final class MediaFormRenderTest extends TestCase
{
    private const MEDIA_GOLDEN_DIR = __DIR__ . '/golden';

    public function testTheInitialFrameAt100x40MatchesTheGolden(): void
    {
        $this->assertGolden('form-100x40.txt', self::plain($this->form()->view(Theme::default(), 100, 40)));
    }

    public function testASeedPrintsItsValuesIntoTheSummaryRows(): void
    {
        $frame = self::plain($this->form()->view(
            Theme::default(),
            100,
            40,
        ));
        $this->assertStringNotContainsString('21', $frame, 'an empty draft shows placeholders only');

        $seeded = self::plain(MediaForm::open(
            MediaRequest::new()->fromArray(['prompt' => 'a cat', 'steps' => 21]),
        )->view(Theme::default(), 100, 40));
        $this->assertStringContainsString('21', $seeded);
        $this->assertStringContainsString('a cat', $seeded);
    }

    public function testNarrowFramesKeepEveryRowWithinTheColumnBudget(): void
    {
        // W3.4 fills out the per-field rendering; the shell's invariant is
        // already load-bearing: no row ever overflows the band, and the
        // frame is exactly `rows` tall.
        foreach ([60, 80] as $cols) {
            $lines = explode("\n", Ansi::strip($this->form()->view(Theme::default(), $cols, 20)));
            $this->assertCount(20, $lines, "frame at {$cols} columns");

            foreach ($lines as $i => $line) {
                $this->assertLessThanOrEqual($cols, Width::string($line), "row {$i} overflows at {$cols} columns");
            }
        }
    }

    public function testTheGenerateActionCarriesTheCollectedDraft(): void
    {
        // Model-level carry: typed text committed into the field reaches the
        // action's request; the App E2E seam (same command pipeline, zero
        // real dials) is pinned in MediaFormKeysTest.
        $form = $this->form();
        [$form] = $form->update(new KeyMsg(KeyType::Enter));

        foreach (['a', ' ', 'c', 'a', 't'] as $rune) {
            [$form] = $form->update(new KeyMsg(KeyType::Char, rune: $rune));
        }

        [$form] = $form->update(new KeyMsg(KeyType::Enter));
        [, $action] = $form->update(new KeyMsg(KeyType::Char, rune: 'g'));

        $this->assertNotNull($action);
        $this->assertSame(['prompt' => 'a cat'], $action->request?->toArray());
    }

    // ---- fixtures -----------------------------------------------------------------

    private function form(): MediaForm
    {
        return MediaForm::open(MediaRequest::new());
    }

    private static function plain(string $rendered): string
    {
        return Ansi::strip($rendered);
    }

    private function assertGolden(string $name, string $actual): void
    {
        $path = self::MEDIA_GOLDEN_DIR . '/' . $name;
        if (getenv('UPDATE_GOLDENS') === '1') {
            if (!is_dir(self::MEDIA_GOLDEN_DIR)) {
                mkdir(self::MEDIA_GOLDEN_DIR, 0o755, true);
            }
            file_put_contents($path, $actual . "\n");
        }

        $this->assertFileExists($path, "no golden at {$path}; run with UPDATE_GOLDENS=1 to create it");
        $this->assertSame((string) file_get_contents($path), $actual . "\n", "layout drifted from {$name}");
    }
}
