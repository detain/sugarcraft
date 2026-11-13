<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Admin;

use SugarCraft\Sql\Lang;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
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
        // While the first async fetch is in flight and nothing is cached yet,
        // show a loading screen instead of failing validate() into "Error:".
        if ($this->context instanceof AsyncCachingServerContext
            && $this->context->isLoading()
            && !$this->context->hasCachedData()) {
            return $this->loadingScreen();
        }
        if (!$this->validate()) {
            return $this->errorScreen();
        }
        return $this->build();
    }

    public function subscriptions(): ?\SugarCraft\Core\Subscriptions
    {
        return null;
    }
}
