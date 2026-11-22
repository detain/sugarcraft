<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Admin;

use SugarCraft\Sql\Lang;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Util\Color;
use SugarCraft\Style\Style;
use SugarCraft\Sql\CellValue;

/**
 * Base class for admin dashboard pages.
 *
 * Lifecycle: validate() is called first. If it returns false,
 * errorScreen() is shown. Otherwise, build() renders the page.
 * refresh() is called on periodic updates.
 *
 * @see Mirrors jorgerojas26/lazysql AdminPage
 */
abstract class PageBase implements Model
{
    protected string $errorMessage = '';

    public function __construct(
        public readonly ServerContextInterface $context,
    ) {}

    /**
     * Validate prerequisites for this page.
     * Return false if validation fails.
     */
    abstract protected function validate(): bool;

    /**
     * Build the page content.
     *
     * @return string
     */
    abstract protected function build(): string;

    /**
     * Refresh data and return new instance.
     */
    public function refresh(): self
    {
        $this->context->refresh();
        return $this;
    }

    /**
     * Whether the page currently owns an open modal editing surface.
     *
     * While this is true the App routes every keystroke to the page before
     * its own key handling, so dialog text (including 'q' or modifier-free
     * characters) types instead of triggering app-level navigation (D-C5).
     * Non-modal pages keep the default false.
     */
    public function isModal(): bool
    {
        return false;
    }

    /**
     * Whether this page claims `$msg` ahead of the App's own Admin-pane
     * chords (the Tab pane cycle, the 1-{n} page picker, q, p, r).
     *
     * Narrower than {@see isModal()}: a claimed key goes to the page, every
     * other key keeps its app-level meaning. A page claims a key only while
     * a focused component of its own binds it, so a blurred page leaves the
     * global keys alone. Non-claiming pages keep the default false.
     */
    public function claimsKey(KeyMsg $msg): bool
    {
        return false;
    }

    /**
     * This page with every keyboard claim dropped (a focused component
     * blurred), so {@see claimsKey()} answers false. App calls it when the
     * user leaves the Admin pane; a page with no claims returns itself.
     */
    public function releaseKeys(): self
    {
        return $this;
    }

    /**
     * Render the error screen.
     *
     * With no specific message, show a neutral "no data" line rather than a
     * bare "Error:" — an empty async cache during the first ticks is not an
     * error, just data that has not arrived yet.
     */
    protected function errorScreen(): string
    {
        if ($this->errorMessage === '') {
            return Style::new()->foreground(Color::ansi(8))->render('  ' . Lang::t('page.no_data'));
        }
        // $errorMessage carries raw driver/server text — neutralize control
        // bytes before it reaches the terminal.
        return Lang::t('page.error', ['message' => CellValue::sanitize($this->errorMessage)]);
    }

    /**
     * Render the loading screen shown while the first async fetch is in flight.
     */
    protected function loadingScreen(): string
    {
        return Style::new()->foreground(Color::ansi(3))->render('  ◌ ' . Lang::t('page.loading'))
            . "\n\n  "
            . Style::new()->foreground(Color::ansi(8))->render(Lang::t('page.fetching'));
    }

    public function init(): ?\Closure
    {
        return null;
    }

    public function update(Msg $msg): array
    {
        return [$this, null];
    }

    public function view(): string
    {
        if ($this->showsLoadingScreen()) {
            return $this->loadingScreen();
        }
        if (!$this->validate()) {
            return $this->errorScreen();
        }
        return $this->build();
    }

    /**
     * Whether {@see view()} paints the page body ({@see build()}) rather
     * than the loading or error screen.
     *
     * A page that claims keys for a component of its body asks this first:
     * a component the frame does not paint must not take keys from the
     * app (the user cannot see what they would drive).
     */
    protected function paintsBody(): bool
    {
        return !$this->showsLoadingScreen() && $this->validate();
    }

    /**
     * While the first async fetch is in flight and nothing is cached yet,
     * {@see view()} shows a loading screen instead of failing validate()
     * into "Error:".
     */
    private function showsLoadingScreen(): bool
    {
        return $this->context instanceof AsyncCachingServerContext
            && $this->context->isLoading()
            && !$this->context->hasCachedData();
    }

    public function subscriptions(): ?\SugarCraft\Core\Subscriptions
    {
        return null;
    }
}
