<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui\MediaForm;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Lang;
use SugarCraft\Crush\Media\ImageIngress;
use SugarCraft\Crush\Media\MediaRequest;
use SugarCraft\Crush\Media\Sd\Client;
use SugarCraft\Crush\Tui\MediaForm\ChoiceFeed;
use SugarCraft\Crush\Tui\MediaForm\GenFormKeys;
use SugarCraft\Crush\Tui\MediaForm\MediaForm;
use SugarCraft\Crush\Tui\MediaForm\Sections\ImageSection;
use SugarCraft\Forms\Field\Confirm;
use SugarCraft\Forms\Field\FilePicker;

/**
 * Plan W4.1 ingress law: the attach row is a candy-forms FilePicker whose
 * committed path goes through {@see ImageIngress} before it takes a slot —
 * accepted files fold into the draft as raw-base64 init_images in PICK
 * ORDER, refused files never occupy a slot (their message lands on the
 * attach row's value cell), and the picker resets after either outcome so
 * the same file cannot be re-added by a second Enter. The MediaForm Enter
 * protocol is pinned end-to-end: while browsing (value null) Enter belongs
 * to the picker; once a path is selected the next Enter commits.
 */
final class ImageSectionIngressTest extends TestCase
{
    private const TINY = __DIR__ . '/../../fixtures/img/tiny.png';
    private const GIF = __DIR__ . '/../../fixtures/img/tiny.gif';
    private const CORRUPT = __DIR__ . '/../../fixtures/img/corrupt.png';

    private string $browseDir = '';

    private string $originalCwd = '';

    protected function setUp(): void
    {
        putenv(Client::BASE_URL_ENV);
        ChoiceFeed::resetForTesting();
        $this->originalCwd = (string) getcwd();
    }

    protected function tearDown(): void
    {
        putenv(Client::BASE_URL_ENV);
        ChoiceFeed::resetForTesting();
        // Leave the sandbox before deleting it: a cwd inside the removed
        // tree would strand every later getcwd() in this process.
        chdir($this->originalCwd);
        if ($this->browseDir !== '') {
            exec('rm -rf ' . escapeshellarg($this->browseDir) . ' 2>&1');
            $this->browseDir = '';
        }
    }

    public function testACommittedPickerPathTakesTheSlotAndCollectsAsRawBase64InPickOrder(): void
    {
        $dir = $this->browseDirWith(['aaa.png' => self::TINY, 'zzz.png' => self::GIF]);
        $this->enterDir($dir);
        $section = ImageSection::forDraft(MediaRequest::new());

        $section = $this->pickWith($section, []);
        $this->assertCount(1, $section->sources());
        $this->assertSame('aaa.png', $section->sources()[0]->name());

        // The reset picker re-browses the file's directory (dirname of the
        // pick), so the second attach starts one Down from the top.
        $section = $this->pickWith($section, [new KeyMsg(KeyType::Down)]);
        $this->assertSame(['aaa.png', 'zzz.png'], array_map(
            static fn (ImageIngress $s): string => $s->name(),
            $section->sources(),
        ));

        $wire = $section->collect(MediaRequest::new())->toArray();
        $this->assertSame([
            base64_encode((string) file_get_contents($dir . '/aaa.png')),
            base64_encode((string) file_get_contents($dir . '/zzz.png')),
        ], $wire['init_images'], 'raw base64 of the source bytes, pick order (§4.3 batch)');

        // Source note rows sit between attach and the lattice, showing the
        // geometry cell; the clear row appeared with the first source.
        $rows = $section->rows();
        $this->assertStringContainsString('aaa.png', $rows[1]);
        $this->assertStringContainsString('4×3 · image/png', $section->valueAt(1));
        $this->assertNull($section->fieldAt(1), 'a source note row has no widget');
        $this->assertSame('4×3 · image/gif', $section->valueAt(2));
    }

    public function testClearingDropsEverySourceAndTheWireFieldAltogether(): void
    {
        $this->enterDir($this->browseDirWith(['aaa.png' => self::TINY]));
        $section = $this->pickWith(ImageSection::forDraft(MediaRequest::new()), []);
        $this->assertArrayHasKey('init_images', $section->collect(MediaRequest::new())->toArray());

        $cleared = $section->withField(Confirm::new(ImageSection::ROW_CLEAR, false));
        $this->assertInstanceOf(ImageSection::class, $cleared);

        $this->assertSame([], $cleared->sources());
        $this->assertArrayNotHasKey('init_images', $cleared->collect(MediaRequest::new())->toArray());
        $this->assertSame(
            Lang::t(GenFormKeys::IMAGE_SOURCE),
            $cleared->rows()[0],
            'with zero sources the clear row leaves and attach leads alone',
        );
    }

    public function testARefusedFileNeverOccupiesASlotAndItsMessageRidesTheRow(): void
    {
        $this->enterDir($this->browseDirWith(['bad.png' => self::CORRUPT]));
        $section = $this->pickWith(ImageSection::forDraft(MediaRequest::new()), []);

        $this->assertSame([], $section->sources(), 'a refused file takes no slot');
        $this->assertStringStartsWith('! ', $section->valueAt(0));
        $this->assertStringContainsString('GD failed to load image', $section->valueAt(0));

        $this->assertArrayNotHasKey(
            'init_images',
            $section->collect(MediaRequest::new())->toArray(),
            'the refusal keeps the draft a pure txt2img shape',
        );

        // A later accepted pick clears the error cell. The committed pick's
        // reset picker snapshotted the listing BEFORE the copy, so drive a
        // fresh picker over the now-two-entry directory (bad.png sorts
        // first; Down lands on good.png).
        copy(self::TINY, $this->browseDir . '/good.png');
        $picker = FilePicker::new(ImageSection::ROW_ATTACH, $this->browseDir)
            ->exts(ImageIngress::EXTENSIONS)
            ->dirAllowed(false)
            ->fileAllowed(true);
        [$editing] = $picker->focus();
        [$editing] = $editing->update(new KeyMsg(KeyType::Down));
        [$editing] = $editing->update(new KeyMsg(KeyType::Enter));
        $section = $section->withField($editing);
        $this->assertInstanceOf(ImageSection::class, $section);

        $this->assertCount(1, $section->sources());
        $this->assertSame('good.png', $section->sources()[0]->name());
        $this->assertStringStartsWith('1 × ', $section->valueAt(0));
    }

    public function testTheFormEnterProtocolBrowsesThenCommits(): void
    {
        $dir = $this->browseDirWith(['one.png' => self::TINY]);
        $this->enterDir($dir);

        $form = MediaForm::open()->click(MediaForm::TAB_ZONE . '5');

        // Enter #1: begin editing the attach row.
        [$form] = $form->update(new KeyMsg(KeyType::Enter));
        $this->assertNotNull($this->editingOf($form), 'the picker took the keys');

        // Enter #2: pickerStillBrowsing (value null) — the key belongs
        // to the widget (it selects the only file), NOT to the commit.
        [$form] = $form->update(new KeyMsg(KeyType::Enter));
        $this->assertNotNull($this->editingOf($form), 'browsing Enter does not end the edit');
        $this->assertArrayNotHasKey('init_images', $form->collect()->toArray());

        // Enter #3: a path is selected — now the commit runs and the
        // ingress lands the slot.
        [$form] = $form->update(new KeyMsg(KeyType::Enter));
        $this->assertNull($this->editingOf($form), 'the commit hands the row back');

        $wire = $form->collect()->toArray();
        $this->assertSame(
            [base64_encode((string) file_get_contents($dir . '/one.png'))],
            $wire['init_images'],
        );
        $image = $this->imageSection($form);
        $this->assertSame('one.png', $image->sources()[0]->name());
    }

    public function testAPureSectionPasteCarriesOrderAlongsidePickerPicks(): void
    {
        $this->enterDir($this->browseDirWith(['aaa.png' => self::TINY]));
        $section = $this->pickWith(ImageSection::forDraft(MediaRequest::new()), []);

        $bytes = (string) file_get_contents(self::GIF);
        $pasted = $section->withIngress(ImageIngress::fromBytes($bytes, 'clipboard.png'));

        $this->assertCount(2, $pasted->sources());
        $wire = $pasted->collect(MediaRequest::new())->toArray();
        $this->assertCount(2, $wire['init_images']);
        $this->assertSame(base64_encode($bytes), $wire['init_images'][1], 'picks then pastes = pick order kept');

        // withIngress clears a stale refusal cell.
        $this->assertStringStartsWith('2 × ', $pasted->valueAt(0));
    }

    // ---- helpers ------------------------------------------------------------------

    /**
     * Focus the attach row's picker, navigate with $keys, SELECT (Enter) and
     * commit through withField (the accept door the shell drives on
     * Enter-commit of the edit).
     *
     * @param list<KeyMsg> $keys
     */
    private function pickWith(ImageSection $section, array $keys): ImageSection
    {
        $picker = $section->fieldAt(0);
        $this->assertInstanceOf(FilePicker::class, $picker);

        [$editing] = $picker->focus();
        foreach ($keys as $key) {
            [$editing] = $editing->update($key);
        }
        [$editing] = $editing->update(new KeyMsg(KeyType::Enter));
        $this->assertInstanceOf(FilePicker::class, $editing);

        $next = $section->withField($editing);
        $this->assertInstanceOf(ImageSection::class, $next);

        return $next;
    }

    private function enterDir(string $dir): void
    {
        self::assertNotFalse(chdir($dir), 'the sandbox dir must be enterable');
    }

    /** @param array<string, string> $files name => fixture source path */
    private function browseDirWith(array $files): string
    {
        $dir = sys_get_temp_dir() . '/crush-imgsection-' . bin2hex(random_bytes(5));
        mkdir($dir, 0o700, true);
        foreach ($files as $name => $source) {
            copy($source, $dir . '/' . $name);
        }
        $this->browseDir = $dir;

        return $dir;
    }

    private function editingOf(MediaForm $form): mixed
    {
        return (new \ReflectionProperty($form, 'editing'))->getValue($form);
    }

    private function imageSection(MediaForm $form): ImageSection
    {
        foreach ($form->sections() as $section) {
            if ($section instanceof ImageSection) {
                return $section;
            }
        }

        $this->fail('no image section is registered');
    }
}
