<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Host;

/**
 * The approval ceiling a plugin's pending permission question is held to.
 *
 * Mirrors the host's approval expiry (the host's wire constant ASK_EXPIRY_SECONDS):
 * a bundle clamps its armed approval timer to this value so the client shows the same
 * deadline the server enforces. This is a literal copy. sugar-plugins depends only on
 * sugar-core and cannot read the host's constant, and AskExpiryTest pins this literal
 * to 1800 only. Nothing in this lib detects a later change to the host's value, so a
 * change to the host's ceiling must be made here by hand. The cross-check against
 * Protocol::ASK_EXPIRY_SECONDS belongs in candy-code, which may depend on both libs.
 */
final class AskExpiry
{
    /** How long a pending permission question stays answerable, in seconds. */
    public const ASK_EXPIRY_SECONDS = 1800;
}
