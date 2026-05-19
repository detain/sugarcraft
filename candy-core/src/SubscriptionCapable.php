<?php

declare(strict_types=1);

namespace SugarCraft\Core;

/**
 * Provides the default {@see Model::subscriptions()} implementation that
 * returns null (no subscriptions). Use this trait in model classes that
 * don't need subscriptions to satisfy the interface contract without
 * boilerplate.
 *
 * @internal
 */
trait SubscriptionCapable
{
    public function subscriptions(): ?Subscriptions
    {
        return null;
    }
}
