<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui\MediaForm;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Width;
use SugarCraft\Crush\Lang;
use SugarCraft\Crush\Media\MediaRequest;
use SugarCraft\Crush\Tui\MediaForm\GenFormKeys;
use SugarCraft\Crush\Tui\MediaForm\MediaForm;
use SugarCraft\Crush\Tui\MediaForm\Sections\HiresSection;
use SugarCraft\Crush\Theme;
use SugarCraft\Forms\Field\Confirm;

/**
 * The 60-column law (crush-media W3.5/W3.7 narrow pass): the disclosure and
 * gated labels are longer than anything the W3.4 tabs carried ("Resize seed
 * from width", "Only masked padding, pixels"), so this pins that the shell
 * still fits a 60x24 band on EVERY tab - including the seven-tab strip and
 * the expanded Hires accordion.
 *
 * Ruling (recorded, per the geometry law): at narrow widths the label column
 * truncates before wrapping - MediaForm::cell() clips every line to the
 * inner width, so "no row overflows cols" holds unconditionally and long
 * labels simply ellipsis. No vertical label/value stacking until a real
 * clipped-value complaint; the gate cells keep the full affordance.
 *
 * Regenerate with `UPDATE_GOLDENS=1` after an intended visual change.
 */
final class MediaFormNarrowTest extends TestCase
{
    private const NARROW_GOLDEN_DIR = __DIR__ . '/golden';

    /** Production registration order: Prompt, Params, Extra, Hires, Refiner, Image, Inpaint, Scripts, Advanced. */
    private const TAB_COUNT = 9;

    public function testEveryTabFitsA60x24Band(): void
    {
        $form = $this->wideForm();

        for ($tab = 0; $tab < self::TAB_COUNT; $tab++) {
            $lines = explode("\n", Ansi::strip($form->click(MediaForm::TAB_ZONE . $tab)->view(Theme::default(), 60, 24)));
            $this->assertCount(24, $lines, "frame height at tab {$tab}");

            foreach ($lines as $i => $line) {
                $this->assertLessThanOrEqual(
                    60,
                    Width::string($line),
                    "row {$i} overflows 60 columns on tab {$tab}",
                );
            }
        }
    }

    public function testTheCollapsedExtraTabAt60ColumnsMatchesTheGolden(): void
    {
        $this->assertNarrowGolden(
            'extra-60x24.txt',
            Ansi::strip($this->wideForm()->click(MediaForm::TAB_ZONE . '2')->view(Theme::default(), 60, 24)),
        );
    }

    public function testTheExpandedHiresAccordionAt60ColumnsMatchesTheGolden(): void
    {
        $sections = $this->wideForm()->sections();
        $sections[3] = $sections[3]->withField(Confirm::new(HiresSection::ROW_ENABLE, true));

        $frame = Ansi::strip(
            MediaForm::fromSections($sections, MediaRequest::new())
                ->click(MediaForm::TAB_ZONE . '3')
                ->view(Theme::default(), 60, 24),
        );

        $this->assertNarrowGolden('hires-open-60x24.txt', $frame);
        $this->assertStringContainsString(Lang::t(GenFormKeys::HIRES_UPSCALE_BY), $frame);
        $this->assertStringContainsString('1024x1024', $frame, 'the §2.4 preview row survives the squeeze');
    }

    public function testTheOpenedImageTabAt60ColumnsLeadsWithTheSourceRows(): void
    {
        // W4.1 flipped the img2img half of the gate open: tab 5 is no longer
        // one quiet note row — the attach row leads and the §2.6 lattice rows
        // follow. The gate sentence must NOT appear here anymore.
        $frame = Ansi::strip($this->wideForm()->click(MediaForm::TAB_ZONE . '5')->view(Theme::default(), 60, 24));

        $this->assertNarrowGolden('image-open-60x24.txt', $frame);
        $this->assertStringContainsString(Lang::t(GenFormKeys::IMAGE_SOURCE), $frame);
        $this->assertStringNotContainsString(
            substr(Lang::t(GenFormKeys::GATE_WAVE4), 0, 12),
            $frame,
            'the img2img tab is open; only the inpaint tab still carries the gate sentence',
        );
    }

    // ---- fixtures -----------------------------------------------------------------

    /** The production seven-tab registration, opened on a blank draft. */
    private function wideForm(): MediaForm
    {
        return MediaForm::open(MediaRequest::new());
    }

    private function assertNarrowGolden(string $name, string $actual): void
    {
        $path = self::NARROW_GOLDEN_DIR . '/' . $name;
        if (getenv('UPDATE_GOLDENS') === '1') {
            file_put_contents($path, $actual . "\n");
        }

        $this->assertFileExists($path, "no golden at {$path}; run with UPDATE_GOLDENS=1 to create it");
        $this->assertSame((string) file_get_contents($path), $actual . "\n", "layout drifted from {$name}");
    }
}
