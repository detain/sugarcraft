<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Channel;

use SugarCraft\Plugins\Plugin;
use SugarCraft\Plugins\PluginContext;

/**
 * A plugin that keeps credentials in the host's store.
 *
 * The host calls {@see start()} with the writer only for implementers of this
 * interface, and calls the plain {@see Plugin::start()} form for every other
 * plugin. The writer is optional in the signature because PHP forbids a
 * sub-interface from making the inherited `start(PluginContext)` require a
 * second argument; a host always passes it to an implementer.
 */
interface WritesCredentials extends Plugin
{
    public function start(PluginContext $context, ?CredentialWriter $writer = null): void;
}
