<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tui\MediaForm;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Media\ImageIngress;
use SugarCraft\Code\Media\MaskIngest;
use SugarCraft\Code\Media\MediaRequest;
use SugarCraft\Code\Media\Sd\Client;
use SugarCraft\Code\Tui\MediaForm\ChoiceFeed;
use SugarCraft\Code\Tui\MediaForm\GenFormKeys;
use SugarCraft\Code\Tui\MediaForm\Sections\ImageSection;
use SugarCraft\Code\Tui\MediaForm\Sections\InpaintSection;
use SugarCraft\Forms\Field\Confirm;
use SugarCraft\Forms\Field\FilePicker;

/**
 * Plan W4.3 mask-ingress law on the image tab: the mask is a single-slot
 * FilePicker row on ImageSection (NOT the inpaint tab), the admitted file
 * rides the wire as raw base64 under `mask`, refusals print on the row
 * without taking the slot, and the §2.7 classification and §2.6
 * transparency vocabulary are display-only — never serialized. Attaching
 * a second mask replaces the first (single-mask law).
 */
final class ImageSectionMaskTest extends TestCase
{
    private const ALPHA = __DIR__ . '/../../fixtures/img/mask-alpha.png';
    private const LUMA = __DIR__ . '/../../fixtures/img/mask-luma.png';
    private const NEITHER = __DIR__ . '/../../fixtures/img/mask-neither.png';

    /** @var list<string> */
    private array $dirs = [];

    protected function setUp(): void
    {
        putenv(Client::BASE_URL_ENV);
        ChoiceFeed::resetForTesting();
    }

    protected function tearDown(): void
    {
        putenv(Client::BASE_URL_ENV);
        ChoiceFeed::resetForTesting();
        foreach ($this->dirs as $dir) {
            exec('rm -rf ' . escapeshellarg($dir) . ' 2>&1');
        }
        $this->dirs = [];
    }

    public function testProductionMintsTheMaskAttachRowRightAfterTheSourcePicker(): void
    {
        $section = ImageSection::forDraft(MediaRequest::new());

        $rows = $section->rows();
        $this->assertSame(Lang::t(GenFormKeys::IMAGE_SOURCE), $rows[0]);
        $this->assertSame(Lang::t(GenFormKeys::MASK_ATTACH), $rows[1], 'mask row leads the block after the img2img attach');
        $this->assertInstanceOf(FilePicker::class, $section->fieldAt(1));
        $this->assertStringNotContainsString(Lang::t(GenFormKeys::MASK_CLEAR), implode("\n", $rows), 'no clear row before a mask exists');
        $this->assertNull($section->mask());
    }

    public function testTheBlankOpenDraftNeverMintsTheMaskWireKey(): void
    {
        $wire = ImageSection::forDraft(MediaRequest::new())->collect(MediaRequest::new())->toArray();

        $this->assertArrayNotHasKey('mask', $wire);
    }

    public function testAClosedInpaintGateMintsNoMaskRowsAndNoMaskKey(): void
    {
        $section = ImageSection::forDraft(MediaRequest::new(), null, true, false);

        $this->assertNotSame(
            Lang::t(GenFormKeys::MASK_ATTACH),
            $section->rows()[1],
            'with the inpaint half closed the mask row does not exist',
        );
        $this->assertArrayNotHasKey('mask', $section->collect(MediaRequest::new())->toArray());
    }

    public function testAnAttachedAlphaMaskRidesTheWireAsRawBase64WithItsClassCell(): void
    {
        $section = $this->attach(ImageSection::forDraft(MediaRequest::new()), self::ALPHA);

        $mask = $section->mask();
        $this->assertInstanceOf(MaskIngest::class, $mask);
        $this->assertSame(MaskIngest::MODE_ALPHA, $mask->mode);
        $this->assertSame($this->classCell('8×8', MaskIngest::MODE_ALPHA), $section->valueAt($this->noteRow($section)));

        $wire = $section->collect(MediaRequest::new())->toArray();
        $this->assertSame(
            base64_encode((string) file_get_contents(self::ALPHA)),
            $wire['mask'],
            'the exact source bytes, re-encoded — never a GD round-trip',
        );
    }

    public function testARefusedMaskTakesNoSlotAndPrintsItsMessageOnTheRow(): void
    {
        $section = $this->attach(ImageSection::forDraft(MediaRequest::new()), self::NEITHER);

        $this->assertNull($section->mask());
        $attachCell = $section->valueAt(1);
        $this->assertStringStartsWith('! ', $attachCell);
        $this->assertStringContainsString(MaskIngest::NEITHER_REFUSAL, $attachCell);
        $this->assertArrayNotHasKey('mask', $section->collect(MediaRequest::new())->toArray());
    }

    public function testASecondAttachReplacesTheFirstMaskOutright(): void
    {
        $section = $this->attach(ImageSection::forDraft(MediaRequest::new()), self::LUMA);
        $this->assertNotNull($section->mask());

        $section = $this->attach($section, self::ALPHA);

        $this->assertInstanceOf(MaskIngest::class, $section->mask());
        $this->assertSame(MaskIngest::MODE_ALPHA, $section->mask()->mode, 'single-mask law: replace, never append');
        $wire = $section->collect(MediaRequest::new())->toArray();
        $this->assertIsString($wire['mask'], 'still one scalar slot, not a list');
    }

    public function testTheClearRowDropsTheMaskAndItsWireKey(): void
    {
        $section = $this->attach(ImageSection::forDraft(MediaRequest::new()), self::ALPHA);
        $this->assertArrayHasKey('mask', $section->collect(MediaRequest::new())->toArray());

        $cleared = $section->withField(Confirm::new(ImageSection::ROW_MASK_CLEAR, false));
        $this->assertInstanceOf(ImageSection::class, $cleared);

        $this->assertNull($cleared->mask());
        $this->assertArrayNotHasKey('mask', $cleared->collect(MediaRequest::new())->toArray());
        $this->assertStringNotContainsString(
            Lang::t(GenFormKeys::MASK_CLEAR),
            implode("\n", $cleared->rows()),
            'the clear row leaves with the mask',
        );
    }

    public function testTheMaskRidesTheExactSectionFortyThreeInpaintFieldMap(): void
    {
        $draft = $this->attach(ImageSection::forDraft(MediaRequest::new()), self::ALPHA)
            ->collect(MediaRequest::new());
        $wire = InpaintSection::forDraft($draft, null, true)->collect($draft)->toArray();

        $this->assertArrayHasKey('mask', $wire);
        $inpaintKeys = array_values(array_filter(
            array_keys($wire),
            static fn (string $key): bool => $key === 'mask_blur' || str_starts_with($key, 'inpaint'),
        ));
        $expectedKeys = ['inpaint_full_res', 'inpaint_full_res_padding', 'inpainting_fill', 'inpainting_mask_invert', 'mask_blur'];
        sort($inpaintKeys);
        sort($expectedKeys);
        $this->assertSame($expectedKeys, $inpaintKeys, 'exactly the five §4.3 inpaint fields ride with the mask');
        $this->assertSame(4, $wire['mask_blur'], 'single blur field (§2.7 law), default 4');
        $this->assertSame(0, $wire['inpainting_mask_invert']);
        $this->assertSame(1, $wire['inpainting_fill'], 'default "original"');
        $this->assertFalse($wire['inpaint_full_res'], 'the radio bridges to a bool, not an int');
        $this->assertSame(32, $wire['inpaint_full_res_padding']);
    }

    public function testClassificationAndTheSectionTwentySixTransparencyRowAreNeverSerialized(): void
    {
        $draft = $this->attach(ImageSection::forDraft(MediaRequest::new()), self::ALPHA)
            ->collect(MediaRequest::new());
        $wire = InpaintSection::forDraft($draft, null, true)->collect($draft)->toArray();

        foreach (array_keys($wire) as $key) {
            $this->assertStringNotContainsString('transparency', (string) $key, '§2.6 mask-transparency is editor-only');
            $this->assertStringNotContainsString('advisory', (string) $key);
            $this->assertStringNotContainsString('class', (string) $key);
        }
        $this->assertArrayNotHasKey('mask_mode', $wire, 'masked-content vocab (§2.6) never reaches the wire');
        $this->assertArrayNotHasKey('mask_transparency_note', $wire);
        // The mask slot itself is pure base64 — no mode words hiding inside.
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9+\/=]+$/', (string) $wire['mask']);
    }

    public function testTheInertNoteRowSwallowsItsKeyWithoutMovingAnything(): void
    {
        $section = $this->attach(ImageSection::forDraft(MediaRequest::new()), self::ALPHA);
        $note = $this->noteRow($section);

        $again = $section->withField(Confirm::new(ImageSection::ROW_MASK, false));
        $this->assertInstanceOf(ImageSection::class, $again);
        $this->assertSame($section->mask()?->mode, $again->mask()?->mode, 'the note row has no press behaviour');
        $this->assertNull($section->fieldAt($note), 'a note row has no widget');
    }

    public function testAUniformFlatWhiteMaskShowsTheCoversEverythingAdvisoryOnItsCell(): void
    {
        $im = \imagecreate(2, 2) ?: $this->fail('GD unavailable');
        \imagefill($im, 0, 0, \imagecolorallocate($im, 255, 255, 255));
        \ob_start();
        \imagepng($im);
        $bytes = (string) \ob_get_clean();
        \imagedestroy($im);

        $dir = $this->dirWith(['flat.png' => $bytes]);
        $section = $this->attachIn(ImageSection::forDraft(MediaRequest::new()), $dir);

        $cell = $section->valueAt($this->noteRow($section));
        $this->assertStringContainsString(Lang::t(GenFormKeys::MASK_CLASS_LUMINANCE), $cell);
        $this->assertStringContainsString(Lang::t(GenFormKeys::MASK_ADV_EVERYTHING), $cell);
    }

    public function testAMaskWithoutSourcesStaysCollectibleForTheServerDoorToJudge(): void
    {
        // Whether inpaint without init_images is legal is the tool-side
        // zero-dial refusal's call (lane a), not the section's — the form
        // layer just reports what the user attached.
        $wire = $this->attach(ImageSection::forDraft(MediaRequest::new()), self::ALPHA)
            ->collect(MediaRequest::new())
            ->toArray();

        $this->assertArrayHasKey('mask', $wire);
        $this->assertArrayNotHasKey('init_images', $wire);
    }

    // ---- helpers ------------------------------------------------------------------

    /** Copy one fixture into a private dir and commit it through the mask picker. */
    private function attach(ImageSection $section, string $fixture): ImageSection
    {
        return $this->attachIn($section, $this->dirWith([basename($fixture) => (string) file_get_contents($fixture)]));
    }

    private function attachIn(ImageSection $section, string $dir): ImageSection
    {
        $picker = FilePicker::new(ImageSection::ROW_MASK_ATTACH, $dir)
            ->exts(ImageIngress::EXTENSIONS)
            ->dirAllowed(false)
            ->fileAllowed(true);
        [$editing] = $picker->focus();
        [$editing] = $editing->update(new KeyMsg(KeyType::Enter));
        $this->assertInstanceOf(FilePicker::class, $editing);
        $this->assertNotNull($editing->value(), 'the single file in the dir is selected');

        $next = $section->withField($editing);
        $this->assertInstanceOf(ImageSection::class, $next);

        return $next;
    }

    /** Index of the mask classification note row (labelled "Mask"). */
    private function noteRow(ImageSection $section): int
    {
        $needle = Lang::t(GenFormKeys::MASK_LABEL);
        foreach ($section->rows() as $index => $row) {
            if (trim($row) === $needle) {
                return $index;
            }
        }

        $this->fail('note row not found in ' . implode('|', $section->rows()));
    }

    private function dirWith(array $files): string
    {
        $dir = sys_get_temp_dir() . '/crush-mask-' . bin2hex(random_bytes(5));
        mkdir($dir, 0o700, true);
        foreach ($files as $name => $contents) {
            file_put_contents($dir . '/' . $name, $contents);
        }
        $this->dirs[] = $dir;

        return $dir;
    }

    private function classCell(string $dimensions, string $mode): string
    {
        $label = Lang::t(match ($mode) {
            MaskIngest::MODE_ALPHA => GenFormKeys::MASK_CLASS_ALPHA,
            default => GenFormKeys::MASK_CLASS_LUMINANCE,
        });

        return $dimensions . ' · ' . $label;
    }
}
