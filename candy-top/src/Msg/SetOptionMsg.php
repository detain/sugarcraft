<?php

declare(strict_types=1);

namespace SugarCraft\Top\Msg;

use SugarCraft\Core\Msg;

/**
 * Set one config option at runtime from an ASYNCHRONOUS producer — a Cmd
 * that only learns the value when it runs (a future sampler, a menu worker).
 *
 * A key handler must NOT use this: candy-core's Program dispatches every
 * key of one terminal read before any Cmd runs, so a write routed through a
 * Cmd lands a round-trip late and a second key from the same read acts on
 * the stale value. Key-driven writes (btop's immediate `Config::set` in
 * proc `e`/`r`/`c`/`m`/`f`/sorting and net `a`/`y`, btop_input.cpp:303-391)
 * go in {@see \SugarCraft\Top\Panel\PanelResult::$set}, which the App
 * applies inside the same update().
 *
 * Both paths share the same handling: the App validates the value against
 * the schema ({@see \SugarCraft\Top\Config\Config::with()}), drops it
 * when btop would reject it, and otherwise applies it through
 * {@see \SugarCraft\Top\App::applyConfig()}, so the relayout, tick re-arm
 * and colour-profile side effects all hold. The change is in memory only;
 * writing the config file belongs to the options menu / exit path (P-F).
 */
final class SetOptionMsg implements Msg
{
    public function __construct(
        public readonly string $key,
        public readonly bool|int|string $value,
    ) {
    }
}
