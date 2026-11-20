<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Host;

use React\Promise\PromiseInterface;

/**
 * The root forms of the two {@see HostServices} calls that need a root argument because no
 * session exists yet to route them by (PL-5c-A design, section 2.6): creating a session in a
 * named root, and listing the pending asks of a named root.
 *
 * It is a separate interface, so {@see HostServices} keeps every signature it has. Only a host
 * that routes to other roots implements it. A bundle that needs a non-primary root checks
 * `instanceof RootedHostServices`; without the check it gets the primary root, as before.
 */
interface RootedHostServices
{
    /**
     * Creates a session in $root (the primary root when $root names it). Resolves to the
     * `session.create` answer as an array, with `id` and `root`.
     *
     * Caveat (section 2.6): a host with no gateway answers the primary root form without `root`,
     * because no canonical root is known and the caller's root was never verified. Only a host
     * with a gateway names the root in the primary answer. A non-primary $root with no gateway
     * also runs on the primary dispatcher, a latent misroute recorded in section 12 of the design.
     *
     * @return PromiseInterface<array<string, mixed>>
     */
    public function createSessionIn(string $root, ?string $name, ?string $permissionMode): PromiseInterface;

    /**
     * The pending permission asks of $root's workspace host, with the top-level `root` set.
     *
     * Caveat (section 2.6): as for {@see createSessionIn()}, a host with no gateway answers the
     * primary root form without a top-level `root`, and a non-primary $root with no gateway runs
     * on the primary dispatcher (latent, section 12 of the design).
     *
     * @return PromiseInterface<array<string, mixed>>
     */
    public function pendingPermissionsIn(string $root): PromiseInterface;
}
