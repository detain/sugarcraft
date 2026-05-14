<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\GridTable;

/**
 * Border configuration for each region of the grid.
 */
final class BorderConfig
{
    public function __construct(
        public readonly bool $showOuter = true,
        public readonly bool $showHeader = true,
        public readonly bool $showInner = true,
        public readonly bool $showFooter = false,
        public readonly ?BorderChars $chars = null,
    ) {
        // Ensure chars is never null
        if ($this->chars === null) {
            $this->chars = BorderChars::default();
        }
    }

    /**
     * Create default heavy border configuration.
     */
    public static function default(): self
    {
        return new self(
            showOuter: true,
            showHeader: true,
            showInner: true,
            showFooter: true,
            chars: BorderChars::default(),
        );
    }

    /**
     * Create rounded thin border configuration.
     */
    public static function rounded(): self
    {
        return new self(
            showOuter: true,
            showHeader: true,
            showInner: true,
            showFooter: true,
            chars: BorderChars::rounded(),
        );
    }

    /**
     * Create borderless configuration.
     */
    public static function borderless(): self
    {
        return new self(
            showOuter: false,
            showHeader: false,
            showInner: false,
            showFooter: false,
            chars: BorderChars::borderless(),
        );
    }

    /**
     * Create minimal border configuration (header only).
     */
    public static function minimal(): self
    {
        return new self(
            showOuter: false,
            showHeader: true,
            showInner: false,
            showFooter: false,
            chars: BorderChars::minimal(),
        );
    }

    public function withOuter(bool $show): self
    {
        return new self(
            showOuter: $show,
            showHeader: $this->showHeader,
            showInner: $this->showInner,
            showFooter: $this->showFooter,
            chars: $this->chars,
        );
    }

    public function withHeader(bool $show): self
    {
        return new self(
            showOuter: $this->showOuter,
            showHeader: $show,
            showInner: $this->showInner,
            showFooter: $this->showFooter,
            chars: $this->chars,
        );
    }

    public function withInner(bool $show): self
    {
        return new self(
            showOuter: $this->showOuter,
            showHeader: $this->showHeader,
            showInner: $show,
            showFooter: $this->showFooter,
            chars: $this->chars,
        );
    }

    public function withFooter(bool $show): self
    {
        return new self(
            showOuter: $this->showOuter,
            showHeader: $this->showHeader,
            showInner: $this->showInner,
            showFooter: $show,
            chars: $this->chars,
        );
    }

    public function withChars(BorderChars $chars): self
    {
        return new self(
            showOuter: $this->showOuter,
            showHeader: $this->showHeader,
            showInner: $this->showInner,
            showFooter: $this->showFooter,
            chars: $chars,
        );
    }
}
