<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Channel;

use SugarCraft\Plugins\Plugin;

/**
 * A plugin that is a channel: a long-lived transport, such as a chat client,
 * that dials out to its platform itself.
 *
 * The channel adds no methods to {@see Plugin}. Capability is declared by
 * which extra interface the class implements: {@see SubscribesToSessionEvents}
 * for the in-process event feed, {@see AcceptsHttpRequests} for an inbound
 * HTTP route, and {@see DispatchesInboundEvents} for a durable inbound ledger.
 *
 * Timers a channel arms must ride the loop from its {@see \SugarCraft\Plugins\PluginContext}
 * and be unwired in dispose(), so a clean shutdown never waits on a stray timer.
 */
interface ChannelInterface extends Plugin
{
}
