<?php

declare(strict_types=1);

namespace SugarCraft\Mail;

/**
 * Thrown when no transport is configured (no API key, no SMTP host).
 *
 * The CLI's --render path treats "no transport at all" (fall back to an
 * offline localhost preview) and "transport configured but broken" (refuse
 * loudly) as different states. That discrimination must never lean on
 * message-text equality: a locale switch or a re-word of the lang value
 * silently reroutes one state into the other. The type IS the intent, so it
 * gets its own class and the catch matches on it structurally.
 */
final class MissingTransportConfig extends \RuntimeException
{
}
