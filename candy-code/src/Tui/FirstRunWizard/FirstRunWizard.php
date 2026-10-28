<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tui\FirstRunWizard;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Code\Cli\Help;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Providers\ProviderFactory;
use SugarCraft\Code\Support\HomeDirectory;
use SugarCraft\Forms\Field\Input;
use SugarCraft\Forms\Field\RadioPills;

/**
 * The first-run provider setup: a two-step sugar-forms model with no I/O.
 *
 * Step 1 picks a provider from {@see ProviderFactory::availableTypes()}; step
 * 2 takes an API key through a masked {@see Input} — or defers, offering the
 * `candy-code login <provider>` command instead — or skips. The decision
 * leaves as a {@see FirstRunWizardAction} for the host (C-TUI-5.e's App slot)
 * to execute: a {@see \SugarCraft\Code\Config\ProviderKeyStore} persist for
 * Finish, an echoed suggestion for the login deferral, a
 * {@see FirstRunWizard::markDismissed()} stamp for closing without a key.
 * The widget never opens a file, a store or a process.
 *
 * Keys (also rendered in the footer hint):
 *  - Step 1: ←/→ (or `h`/`l`) move the pill — {@see RadioPills} commits every
 *    move, the value IS the highlighted pill — `Enter` continues, `s` skips,
 *    `Esc` cancels.
 *  - Step 2: everything printable types into the masked field, `Enter`
 *    stores (Finish), `Tab` defers to `candy-code login <provider>` (Skip
 *    with the provider payload), `Esc` cancels. Step 2 deliberately claims
 *    no printable key: an API key may legally contain any letter, so the
 *    deferral rides `Tab` instead.
 *  - An empty or control-character-bearing key is refused IN the wizard as a
 *    notice — the same shape {@see \SugarCraft\Code\Config\ProviderKeyStore::store()}
 *    refuses on the store side, so a host racing the two paths can never see
 *    different verdicts for one key.
 *
 * {@see shouldOffer()} answers the launch question (no provider selected, no
 * provider configured, a real interactive TTY, and no dismissal stamp yet)
 * and the stamp seam is its only filesystem touch, reading under the owned
 * home exactly like {@see \SugarCraft\Code\Support\MacAltNotice} reads its
 * once-per-version row. Fail-soft toward OFFERING: no readable stamp is not
 * proof somebody dismissed this.
 *
 * Field `Cmd`s (cursor blink) are discarded on forwarding, same as
 * {@see \SugarCraft\Code\Tui\MediaForm\MediaForm} does with its open fields.
 */
final class FirstRunWizard
{
    /** File name of the dismissal stamp inside `~/.candy-code/cache/`. */
    public const STAMP_FILE = 'first-run-dismissed';

    private const STEP_PROVIDER = 1;

    private const STEP_KEY = 2;

    private function __construct(
        private readonly RadioPills $providers,
        private readonly Input $keyField,
        private readonly int $step,
        private readonly string $notice,
    ) {
    }

    /**
     * Open on step 1. `$providerTypes` exists for pins and for a host that
     * already holds the roster; null asks the live
     * {@see ProviderFactory::availableTypes()}. `$seedKey` pre-types the
     * step-2 field (pins, and any later headless prefilled offer); an empty
     * value — the only value the interactive launch ever passes — leaves the
     * field blank.
     *
     * @param list<string>|null $providerTypes
     */
    public static function open(?array $providerTypes = null, string $seedKey = ''): self
    {
        $types = $providerTypes ?? (new ProviderFactory())->availableTypes();

        [$pills] = RadioPills::new('provider', $types)
            ->withTitle(Lang::t('tui.wizard.step_provider'))
            ->focus();
        $pills = $pills instanceof RadioPills ? $pills : RadioPills::new('provider', $types);

        $key = Input::new('api_key')
            ->withTitle(Lang::t('tui.wizard.step_key', ['provider' => '']))
            ->withPassword(true)
            ->withValue($seedKey);

        return new self($pills, $key, self::STEP_PROVIDER, '');
    }

    // ── state ───────────────────────────────────────────────────────────

    /** 1 = picking a provider, 2 = typing its key. */
    public function step(): int
    {
        return $this->step;
    }

    /** The pill currently selected; null only on a degenerate empty roster. */
    public function selectedProvider(): ?string
    {
        $value = $this->providers->value();

        return \is_string($value) ? $value : null;
    }

    /** The last refusal shown in the wizard, or null. */
    public function notice(): ?string
    {
        return $this->notice === '' ? null : $this->notice;
    }

    // ── input ───────────────────────────────────────────────────────────

    /**
     * Answer a message. The first element is null once the wizard closed;
     * the second carries the action the host must execute, if any.
     *
     * @return array{0: ?self, 1: ?FirstRunWizardAction}
     */
    public function update(Msg $msg): array
    {
        $plainRune = $msg instanceof KeyMsg
            && $msg->type === KeyType::Char
            && !$msg->ctrl && !$msg->alt
            ? $msg->rune
            : null;

        if ($msg instanceof KeyMsg && $msg->type === KeyType::Escape) {
            return [null, FirstRunWizardAction::cancel()];
        }

        if ($this->step === self::STEP_PROVIDER) {
            if ($msg instanceof KeyMsg && $msg->type === KeyType::Enter) {
                return $this->toKeyStep();
            }

            if ($plainRune === 's') {
                return [null, FirstRunWizardAction::skip()];
            }

            $moved = $this->providers->update($msg)[0];

            return [$this->with(providers: $moved instanceof RadioPills ? $moved : null), null];
        }

        // Step 2: only Enter/Tab are claimed; every other key — printable or
        // named — feeds the masked field.
        if ($msg instanceof KeyMsg && $msg->type === KeyType::Tab) {
            $provider = $this->selectedProvider();

            return [null, FirstRunWizardAction::skip($provider)];
        }

        if ($msg instanceof KeyMsg && $msg->type === KeyType::Enter) {
            $raw = $this->keyField->value();
            $key = \is_string($raw) ? $raw : '';
            $provider = $this->selectedProvider();
            if ($provider === null || $key === '') {
                // The provider arm is defense-in-depth: {@see toKeyStep()}
                // refuses the transition on an empty roster, so step 2 is
                // never reached without one.
                return [$this->with(notice: Lang::t('tui.wizard.key_empty')), null];
            }

            if (\preg_match('/[\x00-\x1F\x7F]/', $key) === 1) {
                return [$this->with(notice: Lang::t('tui.wizard.key_chars')), null];
            }

            return [null, FirstRunWizardAction::finish($provider, $key)];
        }

        $typed = $this->keyField->update($msg)[0];

        return [$this->with(keyField: $typed instanceof Input ? $typed : null), null];
    }

    /**
     * Enter at step 1: blur the pills, focus the key field — titled now that
     * the provider is known — and clear any refusal carried over.
     *
     * @return array{0: self, 1: null}
     */
    private function toKeyStep(): array
    {
        $provider = $this->selectedProvider();
        if ($provider === null) {
            return [$this, null];
        }

        [$key] = $this->keyField
            ->withTitle(Lang::t('tui.wizard.step_key', ['provider' => $provider]))
            ->focus();

        $blurred = $this->providers->blur();

        return [
            $this->with(
                providers: $blurred instanceof RadioPills ? $blurred : null,
                keyField: $key instanceof Input ? $key : $this->keyField,
                step: self::STEP_KEY,
                notice: '',
            ),
            null,
        ];
    }

    // ── the offer question ──────────────────────────────────────────────

    /**
     * Whether THIS launch should offer the wizard: the session has no
     * provider selected yet, the config names no provider at all, and a real
     * interactive terminal is attached — and no earlier dismissal was
     * stamped. The first three answers belong to the caller because they are
     * host state; this class reads only its own stamp file, so every
     * quadrant stays pinned without a filesystem surprise inside a model.
     *
     * A missing/unowned stamp path fails soft toward OFFERING (nothing
     * demonstrably dismissed this), exactly as {@see
     * \SugarCraft\Code\Support\MacAltNotice} fails soft toward showing.
     *
     * @param string|null $stampPath stamp file to consult, null for the owned-home default
     */
    public static function shouldOffer(
        bool $hasSelectedProvider,
        bool $hasConfiguredProvider,
        bool $isInteractiveTty,
        ?string $stampPath = null,
    ): bool {
        return !$hasSelectedProvider && !$hasConfiguredProvider && $isInteractiveTty && !self::isDismissed($stampPath);
    }

    /** Whether a dismissal stamp exists and is readable (body non-empty). */
    public static function isDismissed(?string $stampPath = null): bool
    {
        return self::readStamp($stampPath ?? self::defaultStampPath()) !== null;
    }

    /**
     * Record the dismissal: the wizard will not be offered again.
     * Best-effort under the owned-home cache directory (mode 0700 on the
     * way in, same as the MacAltNotice stamp); an unwritable cache only
     * means offering again, never an error to the operator. The stamp body
     * is the candy-code version it was recorded under, for the human who
     * later wonders.
     */
    public static function markDismissed(?string $stampPath = null): void
    {
        $path = $stampPath ?? self::defaultStampPath();
        if ($path === null) {
            return;
        }

        $dir = \dirname($path);
        if (!\is_dir($dir) && !@\mkdir($dir, 0700, true) && !\is_dir($dir)) {
            return;
        }

        @\file_put_contents($path, Help::versionString());
    }

    /**
     * The production stamp location: `~/.candy-code/cache/first-run-dismissed`
     * under the single owned-home resolution, or null when no home can be
     * established as this user's.
     */
    private static function defaultStampPath(): ?string
    {
        $home = HomeDirectory::owned();
        if ($home === null) {
            return null;
        }

        return \rtrim($home, '/') . '/.candy-code/cache/' . self::STAMP_FILE;
    }

    /** The stamp body, or null when there is no readable stamp. */
    private static function readStamp(?string $path): ?string
    {
        if ($path === null || !\is_file($path)) {
            return null;
        }

        $body = @\file_get_contents($path);
        if (!\is_string($body)) {
            return null;
        }

        $body = \trim($body);

        return $body === '' ? null : $body;
    }

    // ── render ──────────────────────────────────────────────────────────

    /**
     * The wizard as lines: title, the active field's own view, the key hint,
     * and the refusal notice when one is showing. Fitting and framing are the
     * host's layout concern when the wiring step mounts it; nothing here
     * chases a width.
     */
    public function view(): string
    {
        $lines = [Lang::t('tui.wizard.title')];

        if ($this->step === self::STEP_PROVIDER) {
            $lines[] = $this->providers->view();
            $lines[] = Lang::t('tui.wizard.hint_provider');
        } else {
            $provider = $this->selectedProvider() ?? '';
            $lines[] = $this->keyField->view();
            $lines[] = Lang::t('tui.wizard.hint_key', [
                'command' => 'candy-code login' . ($provider === '' ? '' : ' ' . $provider),
            ]);
        }

        if ($this->notice !== '') {
            $lines[] = $this->notice;
        }

        return \implode("\n", $lines);
    }

    /**
     * Full-value rebuild with named overrides (house immutable shape).
     * `notice: ''` clears the notice; a null override keeps that slot.
     */
    private function with(
        ?RadioPills $providers = null,
        ?Input $keyField = null,
        ?int $step = null,
        ?string $notice = null,
    ): self {
        return new self(
            $providers ?? $this->providers,
            $keyField ?? $this->keyField,
            $step ?? $this->step,
            $notice ?? $this->notice,
        );
    }
}
