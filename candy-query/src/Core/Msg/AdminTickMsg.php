<?php

declare(strict_types=1);

namespace SugarCraft\Query\Core\Msg;

use SugarCraft\Core\Msg;

/**
 * Fired every second by the Admin pane's stable 'admin-fetch' tick.
 *
 * The tick declares nothing about WHAT to fetch: App::update() re-reads the
 * fresh throttle state (AdminState::$lastFetchAt) on each fire and decides
 * between a full fetch, a drain-only pass, or nothing. The decision cannot
 * live in the subscription closure because Program::reconcileSubscriptions()
 * diffs subscriptions by id only — the closure installed when the tick first
 * starts is locked until the Admin pane cancels the id.
 */
final readonly class AdminTickMsg implements Msg {}
