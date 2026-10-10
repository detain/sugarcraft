<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui\MediaForm;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseWheelMsg;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Media\MediaRequest;
use SugarCraft\Crush\Media\Sd\Client;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Tui\Components\MenuBar;
use SugarCraft\Crush\Tui\MediaForm\MediaForm;
use SugarCraft\Crush\Tui\MediaForm\MediaFormState;
use SugarCraft\Crush\Tui\MediaForm\OpenMediaFormMsg;
use SugarCraft\Crush\Tui\MediaForm\ParamsSection;
use SugarCraft\Crush\Tui\MediaForm\Section;
use SugarCraft\Crush\Tui\Settings\OpenSettingsMsg;

/**
 * The media form's keys driven through the shell (crush-media W3.1): the
 * App gate is the modal law — while the form is up its keys are its own,
 * the settings exception list still reaches the shell, and Esc closes.
 * The Veil composite chain in src/Renderer.php is deliberately untouched
 * by Shape A, so KeyHelpTest's painted==routed pins stay green alongside.
 */
final class MediaFormKeysTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/crush-mediaform-keys-' . bin2hex(random_bytes(5));
        mkdir($this->dir . '/project', 0o700, true);
        putenv(Client::BASE_URL_ENV);
        MenuBar::closeMenu();
    }

    protected function tearDown(): void
    {
        putenv(Client::BASE_URL_ENV);
        MenuBar::closeMenu();
        exec('rm -rf ' . escapeshellarg($this->dir) . ' 2>&1');
    }

    public function testTheMsgOpensTheFormAndEscClearsTheSlot(): void
    {
        [$opened] = $this->app()->update(new OpenMediaFormMsg());
        $this->assertNotNull($opened->mediaForm);

        $closed = $this->press($opened, new KeyMsg(KeyType::Escape));
        $this->assertNull($closed->mediaForm, 'Esc cancels and the slot is cleared');
        $this->assertSame('', $closed->chat?->inputBuf ?? '', 'cancel never types into the chat');
    }

    public function testKeysDoNotLeakToTheChatWhileTheFormIsUp(): void
    {
        // THE modal pin: drop the App key gate and these runes land in the
        // chat composer instead — the test reddens.
        $app = $this->press($this->app()->openMediaForm(), new KeyMsg(KeyType::Char, rune: 'x'), new KeyMsg(KeyType::Char, rune: 'y'));

        $this->assertNotNull($app->mediaForm);
        $this->assertSame('', $app->chat?->inputBuf, 'no rune reaches the chat input through the modal');
    }

    public function testTheSettingsExceptionListStillReachesTheShell(): void
    {
        // One law for every full-band modal: F10 is the menu's even over the
        // form (it mirrors settingsEditorYields exactly).
        $app = $this->app()->openMediaForm();
        $this->press($app, new KeyMsg(KeyType::F10));
        $this->assertGreaterThan(0, MenuBar::getActiveMenu(), 'F10 opened the shell menu');

        // Ctrl+C is the quit chord: the form must not swallow it either. It
        // stays open (the chord answers at shell level), and the rune never
        // types into the composer.
        $after = $this->press($app, new KeyMsg(KeyType::Char, rune: "\x03"));
        $this->assertNotNull($after->mediaForm);
        $this->assertSame('', $after->chat?->inputBuf);
    }

    public function testTabAndArrowsMoveFocusWithinTheForm(): void
    {
        $app = $this->app()->withMediaForm($this->twoSectionForm());

        $moved = $this->press($app, new KeyMsg(KeyType::Tab));
        $this->assertSame(1, $moved->mediaForm?->state()->section, 'Tab crosses to the next section');

        $moved = $this->press($moved, new KeyMsg(KeyType::Down));
        $this->assertSame(1, $moved->mediaForm?->state()->row);

        $moved = $this->press($moved, new KeyMsg(KeyType::Char, rune: 'k'));
        $this->assertSame(0, $moved->mediaForm?->state()->row, 'k moves the cursor up');

        $moved = $this->press($moved, new KeyMsg(KeyType::Char, rune: 'l'));
        $this->assertSame(0, $moved->mediaForm?->state()->section, 'l crosses sections and wraps');
        $this->assertSame(0, $moved->mediaForm?->state()->row, 'crossing resets the row cursor');

        $moved = $this->press($moved, new KeyMsg(KeyType::Tab, shift: true));
        $this->assertSame(1, $moved->mediaForm?->state()->section, 'Shift+Tab crosses backwards');
    }

    public function testPageKeysCrossSectionsMirroringTab(): void
    {
        // R8-MINOR-1: the FormTabs strip is reached by PageUp/PageDown
        // exactly like Tab/Shift+Tab — one wrap law at the strip ends.
        $app = $this->app()->withMediaForm($this->twoSectionForm());

        $moved = $this->press($app, new KeyMsg(KeyType::PageDown));
        $this->assertSame(1, $moved->mediaForm?->state()->section, 'PageDown crosses to the next section');

        $moved = $this->press($moved, new KeyMsg(KeyType::PageDown));
        $this->assertSame(0, $moved->mediaForm?->state()->section, 'at the last tab PageDown wraps forward, like Tab');
        $this->assertSame(0, $moved->mediaForm?->state()->row, 'crossing resets the row cursor');

        $moved = $this->press($app, new KeyMsg(KeyType::PageUp));
        $this->assertSame(1, $moved->mediaForm?->state()->section, 'PageUp from the first section wraps backwards to the last');

        $moved = $this->press($moved, new KeyMsg(KeyType::PageUp));
        $this->assertSame(0, $moved->mediaForm?->state()->section, 'PageUp crosses to the previous section');
    }

    public function testWheelScrollsTheFormRows(): void
    {
        $app = $this->press(
            $this->app()->withMediaForm($this->twoSectionForm()),
            new MouseWheelMsg(10, 10, MouseButton::WheelDown, MouseAction::Press),
        );

        $this->assertSame(3, $app->mediaForm?->state()->row, 'one notch = WHEEL_ROWS rows, like settings');
    }

    public function testEnterOpensThePromptFieldAndCommitsTheTypedText(): void
    {
        $app = $this->press($this->app()->openMediaForm(), new KeyMsg(KeyType::Enter));
        $this->assertTrue($app->mediaForm?->state()->editing, 'Enter focuses row 0 (the prompt field)');

        $app = $this->press($app, new KeyMsg(KeyType::Char, rune: 'h'), new KeyMsg(KeyType::Char, rune: 'i'));
        $app = $this->press($app, new KeyMsg(KeyType::Enter));

        $this->assertNotNull($app->mediaForm, 'committing a field leaves the form open');
        $this->assertFalse($app->mediaForm->state()->editing);
        $this->assertSame('hi', $app->mediaForm->draft()->prompt());
    }

    public function testGenerateClosesTheFormAndFeedsTheDraftToTheCommandPipeline(): void
    {
        // The DoD wire: the SAME execution path the typed command uses. With
        // no SD base configured anywhere (env cleared, HOME not consulted —
        // the production tool refuses at its config door), the arrival is
        // observable as the pipeline's own refusal row and zero dials.
        $app = $this->app()->openMediaForm(MediaRequest::new()->fromArray(['prompt' => 'a test cat']));

        $done = $this->press($app, new KeyMsg(KeyType::Char, rune: 'g'));

        $this->assertNull($done->mediaForm, 'Generate closes the modal');
        $rows = $done->chat?->history ?? [];
        $this->assertNotCount(0, $rows);
        $this->assertSame('/generate', $rows[0]->content, 'the draft arrives as one command row');
        $this->assertStringContainsString('no SD base URL configured', $rows[1]->content);
    }

    // ---- launch chord (crush-media W3.10) ------------------------------------------

    public function testTheLaunchChordOpensTheFormFromTheOrdinaryChatPane(): void
    {
        // The full App->KeyboardHandler->Chat loop, same shape as the
        // settings editor's Ctrl+, pins: registry row 'media.open_form'
        // declares Ctrl+D, the shell claims nothing, Chat's ctrl arm answers
        // with the OpenMediaFormMsg Cmd the shell then runs into its slot.
        $app = $this->pressChord($this->app(), self::ctrlD());
        $this->assertNotNull($app->mediaForm, 'Ctrl+D opens the generation form');
        $this->assertSame('', $app->chat?->inputBuf, 'the chord never types a d into the composer');

        // While the form is up the chord belongs to the modal gate, which
        // ignores it - no re-open, no duplicate slot.
        $again = $this->pressChord($app, self::ctrlD());
        $this->assertSame($app->mediaForm, $again->mediaForm, 'Ctrl+D is inert under the form itself');
    }

    public function testTheLaunchChordIsDeadWhileTheSettingsModalIsUp(): void
    {
        [$settings] = $this->app()->update(new OpenSettingsMsg(''));
        $this->assertNotNull($settings->settingsEditor, 'fixture: settings modal up');

        $after = $this->pressChord($settings, self::ctrlD());
        $this->assertNull($after->mediaForm, 'the chord no-ops under settings - Ctrl+D is not on the yields list');
        $this->assertNotNull($after->settingsEditor, 'and it never closes the settings modal either');
    }

    public function testTheLaunchChordIsDeadWhileThePaletteIsUp(): void
    {
        $paletteUp = $this->press($this->app(), new KeyMsg(KeyType::Char, 'p', ctrl: true));
        $this->assertNotNull($paletteUp->chat?->palette(), 'fixture: palette up via its own chord');

        $after = $this->pressChord($paletteUp, self::ctrlD());
        $this->assertNull($after->mediaForm, 'keys while the palette is up belong to the palette');
        $this->assertNotNull($after->chat?->palette(), 'the palette stays up');
    }

    public function testThePaletteRowRunsTheSameOpenerAsTheChord(): void
    {
        // crush-media W3.10 law: palette door, chord, and bare /generate all
        // raise one OpenMediaFormMsg - asserted on the message the Cmd emits.
        [$next, $cmd] = (new Chat())->runPaletteAction('Generate image');
        $this->assertNull($next->palette(), 'the row closes the palette on the way out');
        $this->assertInstanceOf(\Closure::class, $cmd);
        $this->assertInstanceOf(OpenMediaFormMsg::class, $cmd());
    }

    private static function ctrlD(): KeyMsg
    {
        return new KeyMsg(KeyType::Char, 'd', ctrl: true);
    }

    /**
     * Press keys through the shell AND run the Cmd each key emits back into
     * App::update - the production pump, so a chord whose answer is
     * Cmd::send(OpenMediaFormMsg) actually lands in the slot.
     */
    private function pressChord(App $app, KeyMsg ...$keys): App
    {
        foreach ($keys as $key) {
            [$app, $cmd] = $app->update($key);

            if ($cmd instanceof \Closure) {
                [$app] = $app->update($cmd());
            }
        }

        return $app;
    }

    // ---- fixtures -----------------------------------------------------------------

    private function press(App $app, object ...$msgs): App
    {
        foreach ($msgs as $msg) {
            [$app] = $app->update($msg);
        }

        return $app;
    }

    private function app(): App
    {
        return App::new($this->createMock(ProviderInterface::class), 'test-model')
            ->withRoot($this->dir . '/project')
            ->withChat((new Chat())->withSize(120, 30));
    }

    private function twoSectionForm(): MediaForm
    {
        return MediaForm::fromSections([
            ParamsSection::forDraft(MediaRequest::new()),
            new FakeSection(),
        ], null);
    }
}

/**
 * Minimal second section proving the Section contract the W3.4 registry will
 * register against: key/label/visibility/rows plus the collect fold.
 */
final class FakeSection implements Section
{
    public function key(): string
    {
        return 'fake';
    }

    public function label(): string
    {
        return 'Fake';
    }

    public function visible(MediaFormState $state): bool
    {
        return true;
    }

    /** @return list<string> */
    public function rows(): array
    {
        return ['one', 'two', 'three'];
    }

    public function fieldAt(int $row): ?\SugarCraft\Forms\Field
    {
        return null;
    }

    public function valueAt(int $row): string
    {
        return 'v' . $row;
    }

    public function withField(\SugarCraft\Forms\Field $field): self
    {
        return $this;
    }

    public function collect(MediaRequest $draft): MediaRequest
    {
        return $draft;
    }
}
