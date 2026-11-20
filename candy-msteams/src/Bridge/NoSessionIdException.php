<?php

declare(strict_types=1);

namespace CandyCode\Bundles\MsTeams\Bridge;

/**
 * The serve host answered `session.create` without an id. The message is for the
 * log only: the router maps this type to the catalog's `session.no_id` line before
 * anything reaches a Teams user, so the English here never shows in a chat.
 */
final class NoSessionIdException extends \RuntimeException
{
}
