<?php

declare(strict_types=1);

namespace SugarCraft\Log;

/**
 * The exception {@see Logger::fatal()} throws once the Fatal record has
 * landed on its stream.
 *
 * [PHP-01] The PsrBridge used to detect this throw by comparing the message
 * string against `Lang::t('logger.fatal', ...)` — invisible coupling that a
 * wording change or a locale flip between the two call sites silently broke.
 * Detection is now `instanceof`, and the raw caller message is swept and
 * flattened before interpolation so the crash display cannot carry control
 * bytes or forged extra lines (SEC-01).
 *
 * Extends {@see \RuntimeException} deliberately: pre-existing catchers of the
 * fatal throw keep working unchanged.
 */
final class FatalLoggedException extends \RuntimeException
{
}
