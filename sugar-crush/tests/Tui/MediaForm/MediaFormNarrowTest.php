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
 * The 60-column law (crush-media W3.5 narrow pass): W3.5 labels are longer
 * than anything the W3.4 tabs carried ("Resize seed from width", "Show extra
 * sampling method"), so this pins that the shell still fits a 60x24 band on
 * EVERY disclosure tab - labels, values, and the expanded Hires accordion.
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

    public function testEveryDisclosureTabFitsA60x24Band(): void
    {
        $form = $this->wideForm();

        for ($tab = 0; $tab < 5; $tab++) {
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

    // ---- fixtures -----------------------------------------------------------------

    /** The production five-tab registration, opened on a blank draft. */
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
