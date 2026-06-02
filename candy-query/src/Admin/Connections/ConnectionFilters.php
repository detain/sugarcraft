<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\Connections;

/**
 * User-configurable filters for the connections view.
 *
 * Controls visibility of sleeping/background threads, info column loading,
 * and auto-refresh interval.
 *
 * @see Mirrors charmbracelet/lazysql connection filters
 */
final class ConnectionFilters
{
    public const MIN_REFRESH_RATE = 0.5;
    public const MAX_REFRESH_RATE = 30.0;

    private bool $hideSleepingSet = false;
    private bool $hideBackgroundSet = false;
    private bool $skipFullInfoSet = false;
    private ?float $refreshRateSet = null;

    private function __construct(
        public readonly bool $hideSleeping,
        public readonly bool $hideBackground,
        public readonly bool $skipFullInfo,
        public readonly ?float $refreshRate,
    ) {}

    /**
     * Create with default settings (no filtering, no auto-refresh).
     */
    public static function new(): self
    {
        return new self(
            hideSleeping: false,
            hideBackground: false,
            skipFullInfo: false,
            refreshRate: null,
        );
    }

    /**
     * Return a new instance with hide-sleeping toggled.
     */
    public function withHideSleeping(bool $hide): self
    {
        $clone = clone $this;
        $clone->hideSleepingSet = true;
        return new self(
            hideSleeping: $hide,
            hideBackground: $this->hideBackground,
            skipFullInfo: $this->skipFullInfo,
            refreshRate: $this->refreshRate,
        );
    }

    /**
     * Return a new instance with hide-background toggled.
     */
    public function withHideBackground(bool $hide): self
    {
        return new self(
            hideSleeping: $this->hideSleeping,
            hideBackground: $hide,
            skipFullInfo: $this->skipFullInfo,
            refreshRate: $this->refreshRate,
        );
    }

    /**
     * Return a new instance with skip-full-info toggled.
     */
    public function withSkipFullInfo(bool $skip): self
    {
        return new self(
            hideSleeping: $this->hideSleeping,
            hideBackground: $this->hideBackground,
            skipFullInfo: $skip,
            refreshRate: $this->refreshRate,
        );
    }

    /**
     * Return a new instance with refresh rate set.
     *
     * @param float|null $rate Refresh interval in seconds (0.5-30), or null to disable
     */
    public function withRefreshRate(?float $rate): self
    {
        $clamped = self::clampRefreshRate($rate);
        return new self(
            hideSleeping: $this->hideSleeping,
            hideBackground: $this->hideBackground,
            skipFullInfo: $this->skipFullInfo,
            refreshRate: $clamped,
        );
    }

    /**
     * True when refresh is enabled (non-null rate).
     */
    public function isRefreshEnabled(): bool
    {
        return $this->refreshRate !== null;
    }

    /**
     * Clamp refresh rate to valid range or null.
     */
    private static function clampRefreshRate(?float $rate): ?float
    {
        if ($rate === null) {
            return null;
        }
        return max(self::MIN_REFRESH_RATE, min(self::MAX_REFRESH_RATE, $rate));
    }
}
