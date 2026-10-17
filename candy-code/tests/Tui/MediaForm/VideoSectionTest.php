<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tui\MediaForm;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Media\MediaRequest;
use SugarCraft\Code\Media\Sd\Client;
use SugarCraft\Code\Tui\MediaForm\ChoiceFeed;
use SugarCraft\Code\Tui\MediaForm\GenFormKeys;
use SugarCraft\Code\Tui\MediaForm\MediaFormState;
use SugarCraft\Code\Tui\MediaForm\Sections\VideoSection;
use SugarCraft\Code\Tools\BuiltIn\GenerateVideo;
use SugarCraft\Forms\Field\Confirm;
use SugarCraft\Forms\Field\FilePicker;
use SugarCraft\Forms\Field\NumberField;
use SugarCraft\Forms\Field\Select;
use SugarCraft\Forms\Field\Slider;

/**
 * Plan W5.6: the video tab. Pins the rows-to-schema parity law (every name
 * the tab folds lands under a declared {@see GenerateVideo} inputSchema
 * property), the SEND SNAPPED ladder choice with the snap advice surfaced on
 * the inert timing row, the kind gate's zero-dial roster door and its W3.7
 * draft-identity collect, the capability-gated cfg2 row, the single-image
 * first-frame slot on the W4.1 ingress door, and the §5.5 hidden-row law
 * (override-hidden drops from render AND collect).
 */
final class VideoSectionTest extends TestCase
{
    private const TINY = __DIR__ . '/../../fixtures/img/tiny.png';
    private const GIF = __DIR__ . '/../../fixtures/img/tiny.gif';
    private const CORRUPT = __DIR__ . '/../../fixtures/img/corrupt.png';

    private string $frameDir = '';

    private string $frameOriginalCwd = '';

    protected function setUp(): void
    {
        putenv(Client::BASE_URL_ENV);
        ChoiceFeed::resetForTesting();
        $this->frameOriginalCwd = (string) getcwd();
    }

    protected function tearDown(): void
    {
        putenv(Client::BASE_URL_ENV);
        ChoiceFeed::resetForTesting();
        chdir($this->frameOriginalCwd);
        if ($this->frameDir !== '') {
            exec('rm -rf ' . escapeshellarg($this->frameDir) . ' 2>&1');
            $this->frameDir = '';
        }
    }

    public function testEveryFoldTheTabEmitsLandsUnderAGenerateVideoSchemaWireName(): void
    {
        $schema = array_keys(GenerateVideo::new()->inputSchema()['properties']);
        $wire = $this->engaging(VideoSection::forDraft(MediaRequest::new(), enabled: true))
            ->collect(MediaRequest::new()->withPrompt('a cat walking'))
            ->toArray();

        foreach (['num_frames', 'fps', 'cfg', 'motion'] as $name) {
            $this->assertArrayHasKey($name, $wire, "video-exclusive knob {$name} rides the tolerated unknowns");
        }
        foreach (['width', 'height', 'steps', 'seed'] as $name) {
            $this->assertArrayHasKey($name, $wire, "modelled DTO field {$name} folds through its own wither");
        }
        foreach (array_keys($wire) as $name) {
            $this->assertContains($name, $schema, "the tab never emits a name the tool does not declare: {$name}");
        }

        // Coverage, disclosured: prompt/negative_prompt live on the shared
        // prompt row, duration_seconds is sugar the timing note displays
        // instead of a row, cfg2/first_frame_image are capability/admission
        // conditional — everything else the schema declares has a row.
        $this->assertSame([], array_values(array_diff(
            $schema,
            array_keys($wire),
            ['prompt', 'negative_prompt', 'duration_seconds', 'cfg2', 'first_frame_image'],
        )));
    }

    public function testOffLadderFramesKeepTheRawNumberOnScreenAndSnapOnTheWayOut(): void
    {
        $section = VideoSection::forDraft(MediaRequest::new(), enabled: true);
        $frames = $section->fieldAt(1);
        $this->assertInstanceOf(NumberField::class, $frames);
        $section = $section->withField($frames->withValue(100));

        $this->assertSame('100', $section->valueAt(1), 'the row keeps the user\'s shot length verbatim');
        $this->assertSame(121, $section->collect(MediaRequest::new())->toArray()['num_frames'], 'SEND SNAPPED');

        $note = $section->valueAt(2);
        $this->assertStringContainsString('frames 100 is off the ladder - snapped up to 121 (+21)', $note);
        $this->assertStringContainsString('121 frames @ 24 fps ≈ 5.0s', $note);
    }

    public function testOnLadderFramesSnapAdviceStaysQuiet(): void
    {
        $section = VideoSection::forDraft(MediaRequest::new(), enabled: true);
        $frames = $section->fieldAt(1);
        $this->assertInstanceOf(NumberField::class, $frames);
        $section = $section->withField($frames->withValue(81));

        $this->assertSame(81, $section->collect(MediaRequest::new())->toArray()['num_frames']);
        $note = $section->valueAt(2);
        $this->assertStringNotContainsString('off the ladder', $note);
        $this->assertSame('81 frames @ 24 fps ≈ 3.4s', $note);
    }

    public function testTheBottomOfTheRangeSnapsToTheFirstRungAndTheCeilingIsARung(): void
    {
        $section = VideoSection::forDraft(MediaRequest::new(), enabled: true);

        $low = $section->withField($this->framesOf($section)->withValue(25));
        $this->assertSame(81, $low->collect(MediaRequest::new())->toArray()['num_frames']);
        $this->assertStringContainsString('frames 25 is off the ladder - snapped up to 81 (+56)', $low->valueAt(2));

        $high = $section->withField($this->framesOf($section)->withValue(257));
        $this->assertSame(257, $high->collect(MediaRequest::new())->toArray()['num_frames']);
        $this->assertStringNotContainsString('off the ladder', $high->valueAt(2));
    }

    public function testFpsSelectDrivesTheSecondsMathAndCollectsAsAnInteger(): void
    {
        $section = VideoSection::forDraft(MediaRequest::new(), enabled: true);
        $fps = $section->fieldAt(3);
        $this->assertInstanceOf(Select::class, $fps);
        $section = $section->withField($fps->withSelected('16'));

        $wire = $section->collect(MediaRequest::new())->toArray();
        $this->assertSame(16, $wire['fps']);
        $this->assertStringContainsString('121 frames @ 16 fps ≈ 7.6s', $section->valueAt(2));
    }

    public function testTheCfg2RowExistsOnlyWhenTheDialectDeclaresDualGuidance(): void
    {
        $plain = $this->engaging(VideoSection::forDraft(MediaRequest::new(), enabled: true));
        $this->assertNotContains(Lang::t(GenFormKeys::VIDEO_CFG2), $plain->rows());
        $this->assertArrayNotHasKey('cfg2', $plain->collect(MediaRequest::new())->toArray());
        $this->assertArrayNotHasKey(VideoSection::ROW_CFG2, $plain->fieldValues());

        $dual = $this->engaging(VideoSection::forDraft(MediaRequest::new(), enabled: true, dualCfg: true));
        $this->assertContains(Lang::t(GenFormKeys::VIDEO_CFG2), $dual->rows());
        $wire = $dual->collect(MediaRequest::new())->toArray();
        $this->assertArrayHasKey('cfg2', $wire);
        $this->assertSame(4.0, $wire['cfg2'], '§6.2: sometimes cfg2 lower than cfg');
    }

    public function testTheKindGateHidesTheTabAndCollectReturnsTheDraftUntouched(): void
    {
        $section = VideoSection::forDraft(MediaRequest::new(), enabled: false);
        $this->assertFalse($section->visible(new MediaFormState()));

        $draft = MediaRequest::new()->withPrompt('x');
        $this->assertSame($draft, $section->collect($draft), 'W3.7: feature ABSENT, not collapsed');

        $this->assertTrue(VideoSection::forDraft(MediaRequest::new(), enabled: true)->visible(new MediaFormState()));
    }

    public function testTheKindDoorFailsClosedForTheTab(): void
    {
        $this->assertFalse(VideoSection::videoKindEnabled([]), 'the tab fails closed on an absent roster — unlike the tool doors');
        $this->assertTrue(VideoSection::videoKindEnabled(['mediaKinds' => ['image', 'video']]));
        $this->assertTrue(VideoSection::videoKindEnabled(['mediaKinds' => 'video']), 'a one-kind string is a list');
        $this->assertFalse(VideoSection::videoKindEnabled(['mediaKinds' => ['image']]));
        $this->assertFalse(VideoSection::videoKindEnabled(['mediaKinds' => 'mistyped']), 'unparseable collapses to nothing declared');

        $section = VideoSection::forDraft(MediaRequest::new(), settings: ['mediaKinds' => ['image']]);
        $this->assertFalse($section->visible(new MediaFormState()), 'forDraft reads the door without an explicit override');
        $declared = VideoSection::forDraft(MediaRequest::new(), settings: ['mediaKinds' => ['video']]);
        $this->assertTrue($declared->visible(new MediaFormState()));
    }

    public function testAnUnengagedTabCollectsIdentityEvenWhenVisible(): void
    {
        $section = VideoSection::forDraft(MediaRequest::new(), enabled: true);
        $draft = MediaRequest::new()->withPrompt('x');
        $this->assertTrue($section->visible(new MediaFormState()));
        $this->assertSame($draft, $section->collect($draft), 'the mere presence of the tab never stamps video defaults onto an image run');
    }

    public function testTheFirstFrameSlotAdmitsReplacesAndClearsOnTheIngressDoor(): void
    {
        $dir = $this->frameDirWith(['f.png' => self::TINY, 'g.gif' => self::GIF]);
        $this->frameEnter($dir);
        $section = VideoSection::forDraft(MediaRequest::new(), enabled: true);

        $section = $this->admitFrame($section, []);
        $this->assertSame('f.png', $section->valueAt(0));
        $wire = $section->collect(MediaRequest::new())->toArray();
        $this->assertStringEndsWith('f.png', $wire['first_frame_image']);

        // Single-image mode: the next admission REPLACES the slot.
        $section = $this->admitFrame($section, [new KeyMsg(KeyType::Down)]);
        $this->assertSame('g.gif', $section->valueAt(0));
        $wire = $section->collect(MediaRequest::new())->toArray();
        $this->assertStringEndsWith('g.gif', $wire['first_frame_image']);

        // The clear row is a press row; after it the slot and its satellite
        // rows are gone and the wire carries no first_frame_image again.
        $section = $section->withField(Confirm::new(VideoSection::ROW_CLEAR, true));
        $this->assertSame('', $section->valueAt(0));
        $this->assertStringNotContainsString('first_frame', implode(',', array_keys($section->collect(MediaRequest::new())->toArray())));
        $this->assertCount(9, $section->rows());
    }

    public function testARefusedFrameNeverTakesTheSlotAndItsMessageLandsOnTheRow(): void
    {
        $dir = $this->frameDirWith(['c.png' => self::CORRUPT]);
        $this->frameEnter($dir);
        $section = VideoSection::forDraft(MediaRequest::new(), enabled: true);

        $section = $this->admitFrame($section, []);
        $this->assertStringStartsWith('! ', $section->valueAt(0));
        $this->assertArrayNotHasKey('first_frame_image', $section->collect(MediaRequest::new())->toArray());
        $this->assertCount(9, $section->rows(), 'no satellite rows appear for a refused admission');
    }

    public function testSliderRowsClampAtTheirRecordedBounds(): void
    {
        $section = VideoSection::forDraft(MediaRequest::new(), enabled: true);

        $motion = $section->fieldAt(7);
        $this->assertInstanceOf(Slider::class, $motion);
        $section = $section->withField($motion->withValue(2.0));
        $this->assertSame(1.0, $section->collect(MediaRequest::new())->toArray()['motion'], '§6.2 motion is 0-1');

        $cfg = $section->fieldAt(6);
        $this->assertInstanceOf(Slider::class, $cfg);
        $section = $section->withField($cfg->withValue(50.0));
        $this->assertSame(30.0, $section->collect(MediaRequest::new())->toArray()['cfg']);
    }

    public function testTheDefaultTabPinsItsRowOrderAndCells(): void
    {
        $section = VideoSection::forDraft(MediaRequest::new(), enabled: true);

        $this->assertSame([
            Lang::t(GenFormKeys::VIDEO_FIRST_FRAME),
            Lang::t(GenFormKeys::VIDEO_FRAMES),
            '',
            Lang::t(GenFormKeys::VIDEO_FPS),
            Lang::t(GenFormKeys::VIDEO_RESOLUTION),
            Lang::t(GenFormKeys::FIELD_SAMPLING_STEPS),
            Lang::t(GenFormKeys::VIDEO_CFG),
            Lang::t(GenFormKeys::VIDEO_MOTION),
            Lang::t(GenFormKeys::ROW_SEED),
        ], $section->rows());

        $this->assertSame([
            '',
            '121',
            '121 frames @ 24 fps ≈ 5.0s',
            '24',
            '720p (1280x720)',
            '20',
            '5',
            '0.5',
            '-1',
        ], array_map(static fn (int $row): string => $section->valueAt($row), range(0, 8)));
    }

    public function testResolutionClassFoldsItsPixelPairOntoTheDraft(): void
    {
        $section = VideoSection::forDraft(MediaRequest::new(), enabled: true);
        $resolution = $section->fieldAt(4);
        $this->assertInstanceOf(Select::class, $resolution);
        $section = $section->withField($resolution->withSelected('480p (832x480)'));

        $wire = $section->collect(MediaRequest::new())->toArray();
        $this->assertSame(832, $wire['width']);
        $this->assertSame(480, $wire['height']);
    }

    public function testAHiddenRowDropsFromRenderAndCollectBoth(): void
    {
        $element = 'video/' . Lang::t(GenFormKeys::VIDEO_MOTION) . '/visible';
        $section = $this->engaging(VideoSection::forDraft(MediaRequest::new(), enabled: true))
            ->withDisplayOverrides([$element => false]);

        $this->assertNotContains(Lang::t(GenFormKeys::VIDEO_MOTION), $section->rows());
        $wire = $section->collect(MediaRequest::new())->toArray();
        $this->assertArrayNotHasKey('motion', $wire);
        $this->assertArrayHasKey('num_frames', $wire, 'the engaged tab still folds; only the hidden row drops');
        $this->assertArrayNotHasKey(VideoSection::ROW_MOTION, $section->fieldValues());
    }

    public function testFieldValuesRoundTripThroughAHydrationIntoTheSameWire(): void
    {
        $section = VideoSection::forDraft(MediaRequest::new(), enabled: true);
        $section = $section->withField($this->framesOf($section)->withValue(193));
        $fps = $section->fieldAt(3);
        $this->assertInstanceOf(Select::class, $fps);
        $section = $section->withField($fps->withSelected('30'));
        $cfg = $section->fieldAt(6);
        $this->assertInstanceOf(Slider::class, $cfg);
        $section = $section->withField($cfg->withValue(6.5));

        $hydrated = VideoSection::forDraft(MediaRequest::new(), enabled: true)
            ->withFieldValues($section->fieldValues());

        $this->assertSame(
            $section->collect(MediaRequest::new())->toArray(),
            $hydrated->collect(MediaRequest::new())->toArray(),
        );
    }

    // ── helpers (uniquely named vs the ImageSection ingress twins) ──────────

    /** Engagement without change: commit the fps row verbatim at its default. */
    private function engaging(VideoSection $section): VideoSection
    {
        $fps = $section->fieldAt(3);
        $this->assertInstanceOf(Select::class, $fps);

        return $section->withField($fps->withSelected(VideoSection::DEFAULT_FPS));
    }

    private function framesOf(VideoSection $section): NumberField
    {
        $frames = $section->fieldAt(1);
        $this->assertInstanceOf(NumberField::class, $frames);

        return $frames;
    }

    /** @param list<KeyMsg> $keys */
    private function admitFrame(VideoSection $section, array $keys): VideoSection
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
        $this->assertInstanceOf(VideoSection::class, $next);

        return $next;
    }

    /** @param array<string, string> $files name => fixture source */
    private function frameDirWith(array $files): string
    {
        $dir = sys_get_temp_dir() . '/crush-videosection-' . bin2hex(random_bytes(5));
        mkdir($dir, 0o700, true);
        foreach ($files as $name => $source) {
            copy($source, $dir . '/' . $name);
        }
        $this->frameDir = $dir;

        return $dir;
    }

    private function frameEnter(string $dir): void
    {
        self::assertNotFalse(chdir($dir), 'the sandbox dir must be enterable');
    }
}
