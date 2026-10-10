<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui\MediaForm;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Media\ImageIngress;
use SugarCraft\Crush\Media\MediaRequest;
use SugarCraft\Crush\Media\Sd\Client;
use SugarCraft\Crush\Tui\MediaForm\ChoiceFeed;
use SugarCraft\Crush\Tui\MediaForm\MediaForm;
use SugarCraft\Crush\Tui\MediaForm\Sections\ImageSection;

/**
 * Plan W4.2 form-side law: the host persists the clipboard bytes first
 * (volatile-temp law) and hands them to {@see MediaForm::withClipboardImage()},
 * which walks the SAME ImageIngress door as the picker — one representation,
 * no second validator. Accepted pastes take a source slot, fold into the
 * draft as init_images, and answer with the geometry; refused bytes never
 * occupy a slot and land their message on the status line. With the img2img
 * half forced closed the paste still lands the slot row (the section keeps
 * its notes), but the closed gate's collect-identity law means the draft
 * gains nothing — an attach can never smuggle payload past a closed gate.
 */
final class MediaFormClipboardTest extends TestCase
{
    private const TINY_PNG = __DIR__ . '/../../fixtures/img/tiny.png';
    private const CLIP_GIF = __DIR__ . '/../../fixtures/img/tiny.gif';

    protected function setUp(): void
    {
        putenv(Client::BASE_URL_ENV);
        ChoiceFeed::resetForTesting();
    }

    protected function tearDown(): void
    {
        putenv(Client::BASE_URL_ENV);
        ChoiceFeed::resetForTesting();
    }

    public function testAnAcceptedPasteTakesTheSlotFoldsTheDraftAndNamesTheGeometry(): void
    {
        $bytes = (string) file_get_contents(self::TINY_PNG);
        $form = MediaForm::open()->withClipboardImage($bytes);

        $this->assertSame(
            MediaForm::CLIPBOARD_ATTACHED . ' (4×3)',
            $this->clipStatusOf($form),
        );

        $image = $this->clipImageSection($form);
        $this->assertCount(1, $image->sources());
        $this->assertSame('clipboard.png', $image->sources()[0]->name());
        $this->assertSame(MediaForm::CLIPBOARD_ORIGIN, ImageIngress::fromBytes($bytes, 'clipboard.png')->path);

        $wire = $form->collect()->toArray();
        $this->assertSame([base64_encode($bytes)], $wire['init_images']);
    }

    public function testTwoPastesAndAPickKeepOneSharedOrder(): void
    {
        $form = MediaForm::open()
            ->withClipboardImage((string) file_get_contents(self::TINY_PNG))
            ->withClipboardImage((string) file_get_contents(self::CLIP_GIF));

        $image = $this->clipImageSection($form);
        $this->assertSame(['clipboard.png', 'clipboard.png'], array_map(
            static fn (ImageIngress $s): string => $s->name(),
            $image->sources(),
        ));

        $wire = $form->collect()->toArray();
        $this->assertSame([
            base64_encode((string) file_get_contents(self::TINY_PNG)),
            base64_encode((string) file_get_contents(self::CLIP_GIF)),
        ], $wire['init_images'], 'pastes append in arrival order');
    }

    public function testRefusedBytesTakeNoSlotAndRideTheStatusLine(): void
    {
        $form = MediaForm::open()->withClipboardImage(str_repeat("\x00", 64));

        $this->assertStringStartsWith('! ', (string) $this->clipStatusOf($form));
        $this->assertStringContainsString('GD failed to load image', (string) $this->clipStatusOf($form));

        $image = $this->clipImageSection($form);
        $this->assertSame([], $image->sources());
        $this->assertArrayNotHasKey('init_images', $form->collect()->toArray());
    }

    public function testEmptyBytesAreRefusedNotAttached(): void
    {
        $form = MediaForm::open()->withClipboardImage('');

        $this->assertStringStartsWith('! ', (string) $this->clipStatusOf($form));
        $this->assertSame([], $this->clipImageSection($form)->sources());
    }

    public function testAPasteBehindAClosedGateKeepsTheDraftClean(): void
    {
        $closed = ImageSection::forDraft(MediaRequest::new(), null, false);
        $form = MediaForm::fromSections([$closed], MediaRequest::new());

        $pasted = $form->withClipboardImage((string) file_get_contents(self::TINY_PNG));

        // The door accepted the bytes (slot taken, status confirms) …
        $this->assertStringStartsWith(MediaForm::CLIPBOARD_ATTACHED, (string) $this->clipStatusOf($pasted));
        /** @var ImageSection $section */
        $section = $pasted->sections()[0];
        $this->assertCount(1, $section->sources());

        // … but the closed gate's collect-identity law keeps the payload off
        // the wire: an invisible section can never speak for the request.
        $this->assertArrayNotHasKey('init_images', $pasted->collect()->toArray());
    }

    // ---- helpers (uniquely named: cross-file drift scanner) -------------------------

    private function clipStatusOf(MediaForm $form): ?string
    {
        return (new \ReflectionProperty($form, 'status'))->getValue($form);
    }

    private function clipImageSection(MediaForm $form): ImageSection
    {
        foreach ($form->sections() as $section) {
            if ($section instanceof ImageSection) {
                return $section;
            }
        }

        $this->fail('no image section is registered');
    }
}
