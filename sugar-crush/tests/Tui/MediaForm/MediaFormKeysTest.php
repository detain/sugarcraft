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
