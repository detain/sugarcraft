<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\App;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseClickMsg;
use SugarCraft\Core\Msg\MouseWheelMsg;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\MouseAction;
use SugarCraft\Code\App\App;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Providers\ProviderInterface;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Tests\Support\PinsEnglishLocaleTrait;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Tui\FirstRunWizard\FirstRunWizard;
use SugarCraft\Code\Tui\FirstRunWizard\OpenFirstRunWizardMsg;
use SugarCraft\Code\Tui\Renderer as TuiRenderer;

/**
 * C-TUI-5.e: the wiring around the first-run wizard shell slot.
 *
 * The launch batch asks {@see App::firstRunWizardOfferCmd()} whether the
 * band should open — once per install, only on a blank interactive launch;
 * the answer rides an {@see OpenFirstRunWizardMsg}. The shell then routes
 * the band's keys like the other full-band slots: Enter on the provider
 * step walks to the key step, Enter there stores the typed key through
 * the same door `candy-code login` uses and switches the LIVE session to
 * that provider (the `/model` path), while any way out without storing —
 * `s`, Tab, or Escape — stamps the wizard away for good. The model itself
 * is pinned in tests/Tui/FirstRunWizard/; the registry rows in
 * KeyBindingDriftTest.
 */
final class FirstRunWizardWiringTest extends TestCase
{
    use HomeSandboxTrait;
    use PinsEnglishLocaleTrait;

    private string $sandbox;
    private ProviderInterface $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sandbox = $this->useHomeSandbox(
            sys_get_temp_dir() . '/crush_wizard_wire_' . uniqid('', true)
        );
        // The offer reads live process state; pin every selection door shut
        // so a developer's own environment cannot answer for the sandbox.
        putenv('CANDY_CODE_PROVIDER');
        putenv('CANDY_CODE_BACKEND_CMD');
        putenv('CANDY_CODE_BACKEND_CMD_STREAM');
        $this->provider = $this->createMock(ProviderInterface::class);
    }

    protected function tearDown(): void
    {
        putenv('CANDY_CODE_PROVIDER');
        putenv('CANDY_CODE_BACKEND_CMD');
        putenv('CANDY_CODE_BACKEND_CMD_STREAM');
        $this->restoreHomeSandbox();
        parent::tearDown();
    }

    private function app(): App
    {
        return App::new($this->provider, 'test-model');
    }

    private function chat(): Chat
    {
        return (new Chat(history: [], inputBuf: '', backend: new EchoBackend()))
            ->withSize(100, 30);
    }

    private function wizardApp(?string $seedKey = null): App
    {
        return $this->app()
            ->withChat($this->chat())
            ->withFirstRunWizard(FirstRunWizard::open(['openai', 'anthropic', 'gemini'], $seedKey ?? ''));
    }

    /**
     * Lay a user config into the sandbox before the first read: the
     * launcher memoises the parse per config path, so the write has to
     * predate the offer call the way a real config file predates a launch.
     */
    private function seedConfig(array $settings): void
    {
        $dir = $this->sandbox . '/.candy-code';
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        file_put_contents(
            $dir . '/config.json',
            json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
        clearstatcache();
    }

    public function testTheOfferOpensOnceOnABlankInteractiveLaunch(): void
    {
        $app = $this->app();
        $cmd = $app->firstRunWizardOfferCmd(isInteractiveTty: true);
        $this->assertInstanceOf(\Closure::class, $cmd, 'a blank interactive launch is offered the wizard');
        $this->assertInstanceOf(OpenFirstRunWizardMsg::class, $cmd());

        FirstRunWizard::markDismissed();
        $again = $this->app()->firstRunWizardOfferCmd(isInteractiveTty: true);
        $this->assertNull($again, 'the stamp is the one door: after any dismissal the launch stays quiet');
    }

    public function testTheOfferStandsDownOnANonInteractiveLaunch(): void
    {
        $this->assertNull(
            $this->app()->firstRunWizardOfferCmd(isInteractiveTty: false),
            'there is nobody to type a key into a piped launch'
        );
    }

    public function testTheOfferStandsDownWhenTheEnvironmentAlreadyPicksAProvider(): void
    {
        putenv('CANDY_CODE_PROVIDER=openai');
        $this->assertNull(
            $this->app()->firstRunWizardOfferCmd(isInteractiveTty: true),
            'an environment-chosen provider is already a decision'
        );
    }

    public function testTheOfferStandsDownWhenTheBackendCommandTierIsSelected(): void
    {
        putenv('CANDY_CODE_BACKEND_CMD=some-bridge');
        $this->assertNull(
            $this->app()->firstRunWizardOfferCmd(isInteractiveTty: true),
            'a command-tier backend is already a decision too'
        );
    }

    public function testTheOfferStandsDownWhenTheConfigPicksAProvider(): void
    {
        $this->seedConfig(['provider' => 'openai']);
        $this->assertNull(
            $this->app()->firstRunWizardOfferCmd(isInteractiveTty: true),
            'a persisted provider choice suppresses the band'
        );
    }

    public function testTheOfferStandsDownWhenAProviderKeyIsAlreadyConfigured(): void
    {
        $this->seedConfig(['providers' => ['openai' => ['apiKey' => 'sk-already-set']]]);
        $this->assertNull(
            $this->app()->firstRunWizardOfferCmd(isInteractiveTty: true),
            'a stored key means the setup was already done'
        );
    }

    /**
     * Wiring pin (C-TUI-5.e review r1, MAJOR-1): the offer's decision table
     * is tested through the injectable seam above, but that seam must
     * actually be dialled at launch. This is a source scan of init()'s
     * batch — the house shape for the one link a piped test run cannot
     * execute (precedent: the bin pop pin in
     * tests/App/KittyKeyboardNegotiationTest.php). Deleting the batch
     * member keeps every seam test green while shipping a wizard nobody
     * ever sees, so the call site itself is the pin.
     */
    public function testTheLaunchBatchDialsTheOfferExactlyOnce(): void
    {
        $source = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/App/App.php');

        $start = strpos($source, 'public function init(): ?\\Closure');
        $this->assertIsInt($start, 'fixture: App::init() moved or changed signature');
        $end = strpos($source, "\n    }", $start);
        $this->assertIsInt($end, 'fixture: unterminated init() body');
        // Comment-strip the window first (same regex as the modifyOtherKeys
        // sweep in tests/App/KittyKeyboardNegotiationTest.php): commenting
        // the member out must count as removing it, not as keeping it.
        $body = (string) preg_replace(
            '~(/\*.*?\*/|//[^\n]*|#[^\n]*)~s',
            '',
            substr($source, $start, $end - $start),
        );

        $this->assertSame(
            1,
            substr_count($body, '$this->firstRunWizardOfferCmd(),'),
            'C-TUI-5.e: the launch batch must carry the wizard offer — a Cmd, so nothing here '
            . 'blocks, and a null (dropped by the batch) when the launch already knows a provider',
        );
    }

    public function testTheOpenMessageSlidesTheWizardIntoTheSlot(): void
    {
        [$opened] = $this->app()->update(new OpenFirstRunWizardMsg());
        $this->assertNotNull($opened->firstRunWizard, 'the message lands the band');
        $this->assertSame(1, $opened->firstRunWizard->step(), 'the band starts on the provider step');

        [$again] = $opened->update(new OpenFirstRunWizardMsg());
        $this->assertSame($opened->firstRunWizard, $again->firstRunWizard, 'a second open is inert');
    }

    public function testEscapeCancelsTheBandAndStampsItAway(): void
    {
        [$after] = $this->wizardApp()->update(new KeyMsg(KeyType::Escape));
        $this->assertNull($after->firstRunWizard, 'Escape closes the band');
        $this->assertTrue(FirstRunWizard::isDismissed(), 'leaving without a key is a decision: the band never returns');
        $this->assertNull($after->status, 'canceling says nothing beyond closing');
    }

    public function testSkipEchoesTheLoginDoor(): void
    {
        [$after] = $this->wizardApp()->update(new KeyMsg(KeyType::Char, 's'));
        $this->assertNull($after->firstRunWizard, 's closes the band');
        $this->assertTrue(FirstRunWizard::isDismissed(), 'the skip stamps the wizard away');
        $this->assertStringContainsString('candy-code login', (string) $after->status, 'the footer points at the door that stays open');
    }

    public function testTabAfterTheProviderDefersWithTheExactCommand(): void
    {
        [$atKey] = $this->wizardApp()->update(new KeyMsg(KeyType::Enter));
        $this->assertSame(2, $atKey->firstRunWizard->step(), 'the provider step answers Enter with the key step');

        [$after] = $atKey->update(new KeyMsg(KeyType::Tab));
        $this->assertNull($after->firstRunWizard, 'Tab hands the moment back without storing');
        $this->assertTrue(FirstRunWizard::isDismissed());
        $this->assertStringContainsString('candy-code login openai', (string) $after->status, 'the deferral names the highlighted provider');
    }

    public function testEnterStoresTheKeyAndSwitchesTheLiveProvider(): void
    {
        [$atKey] = $this->wizardApp('sk-wired-probe')->update(new KeyMsg(KeyType::Enter));
        $this->assertSame(2, $atKey->firstRunWizard->step());

        [$after] = $atKey->update(new KeyMsg(KeyType::Enter));
        $this->assertNull($after->firstRunWizard, 'finishing closes the band');
        $this->assertFalse(FirstRunWizard::isDismissed(), 'a stored key is not a dismissal');

        $config = Bootstrap::readUserConfig();
        $this->assertSame(
            'sk-wired-probe',
            $config['providers']['openai']['apiKey'] ?? null,
            'the typed key landed in the sandbox config through the login door'
        );
        $this->assertInstanceOf(
            EngineBackend::class,
            $after->chat?->backend(),
            'the live session switched to the provider whose key was stored'
        );
    }

    public function testTheBandSwallowsTheMouseLikeTheOtherSlots(): void
    {
        $opened = $this->wizardApp();

        [$wheeled] = $opened->update(new MouseWheelMsg(1, 1, MouseButton::WheelDown, MouseAction::Press));
        $this->assertSame($opened->firstRunWizard, $wheeled->firstRunWizard, 'the band is keyboard-only: wheel is inert');

        [$clicked] = $opened->update(new MouseClickMsg(1, 1, MouseButton::Left, MouseAction::Press));
        $this->assertSame($opened->firstRunWizard, $clicked->firstRunWizard, 'clicks are inert too');
    }

    public function testTheWizardPaintsOverTheTranscriptLikeTheOtherSlots(): void
    {
        $opened = $this->wizardApp();
        $frame = TuiRenderer::render($opened, 100, 30);
        $this->assertStringContainsString('Welcome to candy-code', $frame, 'the band paints its title');
        $this->assertStringContainsString('openai', $frame, 'the highlighted providers ride the frame');

        $narrow = TuiRenderer::render($opened, 40, 12);
        $this->assertStringContainsString('Welcome to candy-code', $narrow, 'the band still paints on a small terminal');
    }
}
