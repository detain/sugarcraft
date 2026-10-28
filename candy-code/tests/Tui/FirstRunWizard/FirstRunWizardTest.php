<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tui\FirstRunWizard;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Providers\ProviderFactory;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Tui\FirstRunWizard\FirstRunWizard;
use SugarCraft\Code\Tui\FirstRunWizard\FirstRunWizardActionKind;

/**
 * C-TUI-5.d: the first-run provider wizard as a pure sugar-forms model.
 *
 * Two things are pinned on every leg: the model only ever decides (an action
 * object or a notice — never a file, a store, a process), and the exact
 * refusal/accept shape of each step's keys. The offer question is pinned with
 * an explicit stamp path so no test touches a real HOME, both fail-soft
 * polarities included.
 */
final class FirstRunWizardTest extends TestCase
{
    use HomeSandboxTrait;

    private string $tmpRoot = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = \sys_get_temp_dir() . '/candy_code_frwiz_' . \uniqid('', true);
        mkdir($this->tmpRoot, 0700, true);
        $this->useHomeSandbox($this->tmpRoot . '/home');
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        $this->removeTree($this->tmpRoot);

        parent::tearDown();
    }

    // ── step 1 ──────────────────────────────────────────────────────────

    public function testOpensOnTheProviderStepWithTheChosenList(): void
    {
        $wizard = FirstRunWizard::open(['openai', 'sglang']);

        self::assertSame(1, $wizard->step());
        self::assertSame('openai', $wizard->selectedProvider());
        self::assertNull($wizard->notice());
        self::assertStringContainsString(Lang::t('tui.wizard.hint_provider'), $wizard->view());
    }

    public function testTheDefaultRosterIsTheLiveProviderFactory(): void
    {
        $wizard = FirstRunWizard::open();

        // The factory is the single source; the wizard must open standing
        // on its FIRST name without anybody handing the list in.
        self::assertSame((new ProviderFactory())->availableTypes()[0], $wizard->selectedProvider());
    }

    public function testArrowKeysMoveTheSelectionByCommittingEveryMove(): void
    {
        $wizard = FirstRunWizard::open(['openai', 'sglang', 'custom']);

        [$wizard] = $wizard->update(new KeyMsg(KeyType::Right));
        self::assertSame('sglang', $wizard?->selectedProvider());

        [$wizard] = $wizard->update(new KeyMsg(KeyType::Left));
        self::assertSame('openai', $wizard?->selectedProvider());

        [$wizard] = $wizard->update(new KeyMsg(KeyType::Char, 'l'));
        self::assertSame('sglang', $wizard?->selectedProvider());
        self::assertSame(1, $wizard?->step(), 'pill moves never advance the step');
    }

    public function testEnterAdvancesToTheKeyStepAndNamesTheChosenProvider(): void
    {
        $wizard = FirstRunWizard::open(['openai', 'sglang']);
        [$wizard] = $wizard->update(new KeyMsg(KeyType::Right));

        [$wizard, $action] = $wizard->update(new KeyMsg(KeyType::Enter));

        self::assertNotNull($wizard);
        self::assertNull($action, 'advancing is not a decision');
        self::assertSame(2, $wizard->step());
        self::assertSame('sglang', $wizard->selectedProvider());
        self::assertStringContainsString(
            Lang::t('tui.wizard.step_key', ['provider' => 'sglang']),
            $wizard->view(),
        );
    }

    public function testSkipOnStepOneCarriesNoProvider(): void
    {
        [$wizard, $action] = FirstRunWizard::open(['openai'])->update(new KeyMsg(KeyType::Char, 's'));

        self::assertNull($wizard);
        self::assertNotNull($action);
        self::assertSame(FirstRunWizardActionKind::Skip, $action->kind);
        self::assertNull($action->provider);
        self::assertNull($action->key);
    }

    public function testEscCancelsFromStepOne(): void
    {
        [$wizard, $action] = FirstRunWizard::open(['openai'])->update(new KeyMsg(KeyType::Escape));

        self::assertNull($wizard);
        self::assertSame(FirstRunWizardActionKind::Cancel, $action?->kind);
    }

    public function testAnEmptyRosterRefusesTheTransition(): void
    {
        $wizard = FirstRunWizard::open([]);

        [$wizard, $action] = $wizard->update(new KeyMsg(KeyType::Enter));

        self::assertNotNull($wizard, 'nothing to continue against — the wizard stays put');
        self::assertNull($action);
        self::assertSame(1, $wizard->step());
    }

    // ── step 2 ──────────────────────────────────────────────────────────

    private function onKeyStep(?string $seedKey = null): FirstRunWizard
    {
        $wizard = FirstRunWizard::open(['openai', 'anthropic'], (string) $seedKey);
        [$wizard] = $wizard->update(new KeyMsg(KeyType::Enter));

        self::assertInstanceOf(FirstRunWizard::class, $wizard);

        return $wizard;
    }

    public function testTypedCharactersBuildTheMaskedValue(): void
    {
        $wizard = $this->onKeyStep();

        foreach (['s', 'k', '-', 'x'] as $rune) {
            [$wizard] = $wizard->update(new KeyMsg(KeyType::Char, $rune));
            self::assertInstanceOf(FirstRunWizard::class, $wizard);
        }

        $sentinel = 'sk-x';
        [$wizard, $action] = $wizard->update(new KeyMsg(KeyType::Enter));

        self::assertNull($wizard);
        self::assertSame(FirstRunWizardActionKind::Finish, $action?->kind);
        self::assertSame('openai', $action?->provider);
        self::assertSame($sentinel, $action?->key);
    }

    public function testTheMaskedFieldNeverEchoesTheKeyText(): void
    {
        $secret = 'sk-very-not-this-one';
        $wizard = $this->onKeyStep($secret);

        self::assertStringNotContainsString($secret, $wizard->view());
        // chr(42), not a literal: a bare wildcard-shaped test string is
        // harvested by GlobDialectDifferentialTest's corpus and drifts the
        // PathGlob doc-block figures (§1.9 glob-literal law).
        self::assertStringContainsString(\chr(42), $wizard->view(), 'the echo rides the mask character');
    }

    public function testEnterWithNothingTypedIsANoticeNotADecision(): void
    {
        [$wizard, $action] = $this->onKeyStep()->update(new KeyMsg(KeyType::Enter));

        self::assertNotNull($wizard, 'a refusal keeps the wizard open');
        self::assertNull($action);
        self::assertSame(Lang::t('tui.wizard.key_empty'), $wizard->notice());
    }

    public function testEnterWithControlCharactersIsANoticeNotADecision(): void
    {
        $wizard = $this->onKeyStep("sk\x01evil");

        [$wizard, $action] = $wizard->update(new KeyMsg(KeyType::Enter));

        self::assertNotNull($wizard);
        self::assertNull($action, 'the wizard hands out no Finish the store would refuse');
        self::assertSame(Lang::t('tui.wizard.key_chars'), $wizard->notice());
    }

    public function testTabDefersToTheLoginCommandCarryingTheProvider(): void
    {
        $wizard = $this->onKeyStep();
        [$wizard, $action] = $wizard->update(new KeyMsg(KeyType::Tab));

        self::assertNull($wizard);
        self::assertSame(FirstRunWizardActionKind::Skip, $action?->kind);
        self::assertSame('openai', $action?->provider, 'the deferral names the command the host echoes');
        self::assertNull($action?->key);
    }

    public function testTabIsTheOnlyDeferralKeyAndLettersStayInTheKey(): void
    {
        $wizard = $this->onKeyStep();

        // 's' and 'l' — step 1's skip and pill words — must type here, not
        // decide: an API key may legally carry both letters.
        [$wizard, $action] = $wizard->update(new KeyMsg(KeyType::Char, 's'));
        self::assertNull($action);
        [$wizard, $action] = $wizard->update(new KeyMsg(KeyType::Char, 'l'));
        self::assertNull($action);
        [, $action] = $wizard->update(new KeyMsg(KeyType::Enter));
        self::assertSame(FirstRunWizardActionKind::Finish, $action?->kind);
        self::assertSame('sl', $action?->key);
    }

    public function testEscCancelsFromStepTwo(): void
    {
        [$wizard, $action] = $this->onKeyStep('sk-x')->update(new KeyMsg(KeyType::Escape));

        self::assertNull($wizard);
        self::assertSame(FirstRunWizardActionKind::Cancel, $action?->kind);
    }

    public function testTheKeyStepHintSpellsTheConcreteLoginCommand(): void
    {
        $wizard = $this->onKeyStep();

        self::assertStringContainsString(
            Lang::t('tui.wizard.hint_key', ['command' => 'candy-code login openai']),
            $wizard->view(),
        );
    }

    // ── the offer question ──────────────────────────────────────────────

    public function testShouldOfferNeedsEveryDoorOpenAtOnce(): void
    {
        $stamp = $this->tmpRoot . '/first-run-dismissed';

        self::assertTrue(FirstRunWizard::shouldOffer(false, false, true, $stamp));
        self::assertFalse(FirstRunWizard::shouldOffer(true, false, true, $stamp), 'a selected provider ends the question');
        self::assertFalse(FirstRunWizard::shouldOffer(false, true, true, $stamp), 'a configured provider ends it too');
        self::assertFalse(FirstRunWizard::shouldOffer(false, false, false, $stamp), 'no interactive TTY, no offer');
    }

    public function testTheDismissalStampIsWrittenOnceAndReadBack(): void
    {
        $stamp = $this->tmpRoot . '/first-run-dismissed';
        self::assertFalse(FirstRunWizard::isDismissed($stamp));

        FirstRunWizard::markDismissed($stamp);

        self::assertTrue(FirstRunWizard::isDismissed($stamp));
        self::assertFalse(
            FirstRunWizard::shouldOffer(false, false, true, $stamp),
            'the dismissed wizard never comes back for this install',
        );
        // The body records the version it was dismissed under (MacAltNotice
        // stamp idiom) — it is the EXISTENCE of a non-empty body that the
        // read arms treat as dismissal, never the bytes.
        self::assertNotSame('', trim((string) file_get_contents($stamp)));
    }

    public function testAnUnreadableStampFailsSoftTowardOffering(): void
    {
        $stamp = $this->tmpRoot . '/first-run-dismissed';
        file_put_contents($stamp, '   ');

        self::assertFalse(FirstRunWizard::isDismissed($stamp), 'a blank body is not proof anybody dismissed');
        self::assertTrue(FirstRunWizard::shouldOffer(false, false, true, $stamp));
    }

    public function testAMissingHomeStampPathFailsSoftTowardOffering(): void
    {
        // A stamp path that cannot exist (no parent directory) reads as no
        // stamp, so the offer survives the unwritable cache — noise over
        // silence, the MacAltNotice polarity.
        $missing = $this->tmpRoot . '/no/such/dir/first-run-dismissed';

        self::assertFalse(FirstRunWizard::isDismissed($missing));
        self::assertTrue(FirstRunWizard::shouldOffer(false, false, true, $missing));

        FirstRunWizard::markDismissed($missing);
        self::assertTrue(FirstRunWizard::isDismissed($missing), 'the best-effort write still creates the cache dir');
    }

    // ── the suite environment must never be offerable ───────────────────

    public function testThePipedSuiteShapeAnswersNoOffer(): void
    {
        // The wiring host (C-TUI-5.e) passes the house TTY probe; under a
        // phpunit pipe that door is false, so the suite, `-p`, `serve` and
        // `attach` all answer no-offer no matter what the stamp door says.
        // On a real terminal the probe is true and the claim is not testable
        // — early return, the terminal-size fallback's polarity.
        if (\stream_isatty(STDOUT)) {
            return;
        }

        self::assertFalse(
            FirstRunWizard::shouldOffer(false, false, \stream_isatty(STDOUT)),
            'the piped suite environment must never be offered the wizard',
        );
    }

    public function testTheDefaultStampStaysInsideTheSandboxedHome(): void
    {
        // Under the suite's HOME sandbox the default path resolves inside
        // the sandbox: nothing is dismissed there (so the TTY door alone is
        // what protects the suite — the honest reason, not a stale stamp),
        // and a no-argument markDismissed() lands its file under the sandbox
        // and never touches a real home.
        self::assertFalse(FirstRunWizard::isDismissed(), 'the sandbox home starts undismissed');

        FirstRunWizard::markDismissed();

        self::assertTrue(FirstRunWizard::isDismissed());
        self::assertFileExists($this->tmpRoot . '/home/.candy-code/cache/' . FirstRunWizard::STAMP_FILE);
    }

    private function removeTree(string $path): void
    {
        foreach ((array) scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . '/' . $entry;
            is_dir($child) ? $this->removeTree($child) : @unlink($child);
        }
        @rmdir($path);
    }
}
