<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin;

use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Util\Color;
use SugarCraft\Sprinkles\Style;

/**
 * Base class for admin dashboard pages.
 *
 * Lifecycle: validate() is called first. If it returns false,
 * errorScreen() is shown. Otherwise, build() renders the page.
 * refresh() is called on periodic updates.
 *
 * @see Mirrors charmbracelet/lazysql AdminPage
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
     * Render the error screen.
     *
     * With no specific message, show a neutral "no data" line rather than a
     * bare "Error:" — an empty async cache during the first ticks is not an
     * error, just data that has not arrived yet.
     */
    protected function errorScreen(): string
    {
        if ($this->errorMessage === '') {
            return Style::new()->foreground(Color::ansi(8))->render('  No data available yet.');
        }
        return 'Error: ' . $this->errorMessage;
    }

    /**
     * Render the loading screen shown while the first async fetch is in flight.
     */
    protected function loadingScreen(): string
    {
        return Style::new()->foreground(Color::ansi(3))->render('  ◌ Loading…')
            . "\n\n  "
            . Style::new()->foreground(Color::ansi(8))->render('Fetching data from the server.');
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
        if ($this->context instanceof CachingServerContext
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
