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

    public function testNoRawLangKeyLeaksIntoAFrame(): void
    {
        // crush-media W3.10 vicious pin: every label the frame paints rides
        // Lang::t. A key added to src without its en.php entry (or a typo in
        // either side) leaks the raw 'tui.genform.…' text into the band -
        // this reddens on the spot instead of shipping a frame of dotted
        // breadcrumbs. Covers the initial frame and a seeded one.
        $frames = [
            self::plain($this->form()->view(Theme::default(), 100, 40)),
            self::plain(MediaForm::open(
                MediaRequest::new()->fromArray(['prompt' => 'a cat', 'steps' => 21]),
            )->view(Theme::default(), 100, 40)),
        ];

        foreach ($frames as $frame) {
            $this->assertStringNotContainsString('tui.', $frame, 'a raw lang key leaked into a rendered frame');
        }
    }

    public function testASeedPrintsItsValuesIntoTheRows(): void
    {
        // W3.4 moved the dials onto their own tab: the Prompt frame stays
        // text-only, and stepping right reveals the seeded numbers.
        $frame = self::plain($this->form()->view(
            Theme::default(),
            100,
            40,
        ));
        $this->assertStringNotContainsString('21', $frame, 'an empty prompt frame shows placeholders only');

        [$seeded] = MediaForm::open(
            MediaRequest::new()->fromArray(['prompt' => 'a cat', 'steps' => 21]),
        )->update(new KeyMsg(KeyType::Right));
        $params = self::plain($seeded->view(Theme::default(), 100, 40));
        $this->assertStringContainsString('21', $params);

        [$promptTab] = $seeded->update(new KeyMsg(KeyType::Left));
        $this->assertStringContainsString('a cat', self::plain($promptTab->view(Theme::default(), 100, 40)));
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
        //
        // crush-media W3.4: the schema-era fold test that let a blank draft
        // emit nothing is superseded - every visible core row collects, so
        // the wire carries the §2.1 defaults alongside the typed prompt
        // (the webUI's always-post-defaults behaviour; unset optionals ride
        // as explicit nulls, matching what the form knows).
        $form = $this->form();
        [$form] = $form->update(new KeyMsg(KeyType::Enter));

        foreach (['a', ' ', 'c', 'a', 't'] as $rune) {
            [$form] = $form->update(new KeyMsg(KeyType::Char, rune: $rune));
        }

        [$form] = $form->update(new KeyMsg(KeyType::Enter));
        [, $action] = $form->update(new KeyMsg(KeyType::Char, rune: 'g'));

        $this->assertNotNull($action);
        $this->assertSame([
            'prompt' => 'a cat',
            'negative_prompt' => null,
            'styles' => null,
            'seed' => -1,
            'sampler_name' => 'Euler',
            'scheduler' => 'Automatic',
            'batch_size' => 1,
            'n_iter' => 1,
            'steps' => 20,
            'cfg_scale' => 7.0,
            'width' => 512,
            'height' => 512,
            // W4.1: the img2img half of Wave4Gate opened, so §2.6 defaults
            // fold onto the wire like every other visible core row.
            'denoising_strength' => 0.75,
            'resize_mode' => 0,
            // W4.3 opened the inpaint half: §2.7 defaults fold the same way.
            'mask_blur' => 4,
            'inpainting_fill' => 1,
            'inpaint_full_res' => false,
            'inpaint_full_res_padding' => 32,
            'inpainting_mask_invert' => 0,
        ], $action->request?->toArray());
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
