<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin;

use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;

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
     */
    protected function errorScreen(): string
    {
        return 'Error: ' . $this->errorMessage;
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
