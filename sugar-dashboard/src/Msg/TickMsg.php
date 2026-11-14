<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Msg;

use SugarCraft\Core\Msg;

/**
 * The one tick marker every module's refresh cadence mints.
 *
 * Before this type each module shipped its own byte-identical marker
 * class (four TickMsgs plus the System module's RefreshMsg), which only
 * forced hosts to keep a type-per-module routing table — a table the
 * example host got wrong, mapping one module's import onto another's
 * address. The cadence itself differs per module (1 Hz clock, 2 s
 * system, 30 min weather, …); the MESSAGE does not, so the vocabulary
 * collapses to this class and the routing identity rides on it as a
 * source label.
 *
 * A tick with a source drives only the named module ({@see self::isFor});
 * a source-less broadcast drives every ticking module, which is the
 * shape the old per-module names took for single-module hosts and tests
 * that just construct the marker.
 *
 * The five legacy names (Modules\{Clock,Uptime,Weather,Generic}\TickMsg
 * and Modules\System\RefreshMsg) resolve here as class_alias shims;
 * REMOVED-IN: 2.0.0.
 */
final class TickMsg implements Msg
{
    /**
     * @param null|string $source module whose cadence minted this tick,
     *     or null for a broadcast that every ticking module should serve.
     */
    public function __construct(public readonly ?string $source = null)
    {
    }

    /**
     * Mint a tick addressed to one module.
     */
    public static function for(string $source): self
    {
        return new self($source);
    }

    /**
     * True when this tick should drive the named module: addressed to
     * it, or broadcast (source-less).
     */
    public function isFor(string $module): bool
    {
        return $this->source === null || $this->source === $module;
    }
}
