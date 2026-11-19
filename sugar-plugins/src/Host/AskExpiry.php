<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Host;

/**
 * The approval ceiling a plugin's pending permission question is held to.
 *
 * Mirrors the host's approval expiry (the host's wire constant ASK_EXPIRY_SECONDS):
 * a bundle clamps its armed approval timer to this value so the client shows the same
 * deadline the server enforces. The value is pinned to the host's by a test, so the two
 * must change together.
 */
final class AskExpiry
{
    /** How long a pending permission question stays answerable, in seconds. */
    public const ASK_EXPIRY_SECONDS = 1800;
}
