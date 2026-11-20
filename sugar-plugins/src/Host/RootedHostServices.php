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
     * @return PromiseInterface<array<string, mixed>>
     */
    public function createSessionIn(string $root, ?string $name, ?string $permissionMode): PromiseInterface;

    /**
     * The pending permission asks of $root's workspace host, with the top-level `root` set.
     *
     * @return PromiseInterface<array<string, mixed>>
     */
    public function pendingPermissionsIn(string $root): PromiseInterface;
}
