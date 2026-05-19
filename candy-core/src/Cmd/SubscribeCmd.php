<?php

declare(strict_types=1);

namespace SugarCraft\Core\Cmd;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\Msg\SubscriptionsMsg;
use SugarCraft\Core\Subscriptions;

/**
 * Cmd that replaces the Program's active subscription set.
 *
 * The runtime intercepts the resulting {@see SubscriptionsMsg} and
 * installs the new set, cancelling any subscriptions that are no longer
 * present and starting any new ones.
 *
 * @see SubscriptionsMsg
 */
final class SubscribeCmd
{
    private \Closure $cmd;

    /**
     * @param Subscriptions $subscriptions New subscription set
     */
    public function __construct(Subscriptions $subscriptions)
    {
        $this->cmd = static fn(): SubscriptionsMsg => new SubscriptionsMsg($subscriptions);
    }

    public function __invoke(): SubscriptionsMsg
    {
        return ($this->cmd)();
    }
}
