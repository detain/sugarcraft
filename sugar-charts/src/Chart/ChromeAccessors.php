<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Chart;

/**
 * The untrusted chrome surface a composition pass needs.
 *
 * Both chart families — the Chart base (LineChart) and the ChartExtras
 * renderers (BarChart, Scatter, OHLCChart) — expose their title, legend,
 * and axis-label state through these accessors so ONE compositor body
 * (the ChartExtras trait) serves every renderer. Previously Chart carried
 * a private fork of the same layout, and the three trait users supplied
 * protected implementations of abstract trait hooks; an accessor drift in
 * one family silently desynced the two composers (sugar-charts-008).
 */
interface ChromeAccessors
{
    /** Declared canvas width in terminal cells (extras are clipped to it). */
    public function chartExtrasGetWidth(): int;

    /** Whether a legend row/column is composed into the output. */
    public function chartExtrasShowLegend(): bool;

    /**
     * Legend entries in render order.
     *
     * @return list<array{label: string, color: string}>
     */
    public function chartExtrasGetLegendItems(): array;

    public function chartExtrasGetLegendPosition(): Position;

    public function chartExtrasGetLegendIndicatorChar(): ?string;

    public function chartExtrasGetTitle(): ?string;

    /** Guaranteed Top or Bottom — the constructor door refuses Left/Right. */
    public function chartExtrasGetTitlePosition(): Position;

    public function chartExtrasGetXLabel(): ?string;

    public function chartExtrasGetYLabel(): ?string;
}
