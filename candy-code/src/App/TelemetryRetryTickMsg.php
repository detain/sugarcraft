<?php

declare(strict_types=1);

namespace SugarCraft\Code\App;

use SugarCraft\Core\Msg;
use SugarCraft\Code\Chat;

/**
 * The tick {@see Chat::subscriptions()} declares while the OTLP sink carries
 * over a batch its endpoint refused (C-SES-4.b): when it lands,
 * {@see \SugarCraft\Code\Observability\RetryingEventSink::tick()} re-posts
 * the carried bodies — asynchronously, on the loop that is by definition
 * running when a tick fires.
 *
 * Payload-free: the retry is over whatever the sink itself still holds when
 * the tick arrives, so a tick armed before the carry-over drained costs one
 * empty check. The arm that answers it lives with the session-surface family
 * (`Chat\Handlers\HandlesSessionSurface`), whose serialized claim this
 * dispatch region carries.
 */
final class TelemetryRetryTickMsg implements Msg
{
}
