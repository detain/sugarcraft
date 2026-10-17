<?php

declare(strict_types=1);

namespace SugarCraft\Core\Msg;

use SugarCraft\Core\Msg;
use SugarCraft\Core\Subscriptions;

/**
 * Internal marker carrying a new subscription set. The Program intercepts
 * this during dispatch and reconciles its active subscriptions.
 *
 * @internal
 */
final class SubscriptionsMsg implements Msg
{
    public function __construct(
        public readonly Subscriptions $subscriptions,
    ) {
    }
}
