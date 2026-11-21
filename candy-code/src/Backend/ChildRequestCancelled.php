<?php

declare(strict_types=1);

namespace SugarCraft\Code\Backend;

/**
 * The rejection of an {@see ChildChannel::ask()} or {@see ChildChannel::callClient()}
 * promise whose answer never came: the parent went away before it was given.
 *
 * It is a cancellation, not a failure. {@see ChildChannel::awaitTurn()} hands
 * it to the caller's own cancellation handler, which settles the question as
 * unanswered (`Permission required:`) or the client call as an error result.
 * Neither ever becomes a grant or content.
 */
final class ChildRequestCancelled extends \RuntimeException
{
    /**
     * @param string $requestId the ask id or the client call id that was cancelled
     * @param string $reason    why nobody answered
     */
    public function __construct(public readonly string $requestId, string $reason)
    {
        parent::__construct($reason);
    }
}
