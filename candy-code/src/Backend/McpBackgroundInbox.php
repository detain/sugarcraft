<?php

declare(strict_types=1);

namespace SugarCraft\Code\Backend;

use SugarCraft\Code\Lang;
use SugarCraft\Code\MCP\McpBackgroundCalls;
use SugarCraft\Code\Messages\Message as TypedMessage;
use SugarCraft\Code\Messages\UserMessage;

/**
 * The finished half of {@see McpBackgroundCalls}: answers that outlived the
 * turn which asked for them, delivered as messages at the next step boundary
 * or the next turn, exactly where the steering and mailbox inboxes already
 * hand the model news it did not wait for.
 *
 * The row is the harness's own sentence, and then the answer's own bytes:
 * the finished exchange already IS a rendered {@see \SugarCraft\Code\Tools\ToolResult}
 * — the child ran the plain path's full fence, truncation and spill shaping
 * before publishing — so this inbox adds no framing of server content and
 * must not: a second fence around an already-fenced row would speak a shape
 * the model never sees for an answered call.
 *
 * This inbox never creates or waits on a job; it only drains. A backgrounded
 * call has no other watcher by design — the point of the hand-off is that
 * nothing stands and blocks between "still running" and the answer landing
 * here. The registry's own stale-claim repair (see
 * {@see McpBackgroundCalls::takeFinished()}) is the single recovery path.
 */
final class McpBackgroundInbox implements TurnInbox
{
    public function pending(): bool
    {
        return McpBackgroundCalls::hasFinished();
    }

    /**
     * @return list<TypedMessage>
     */
    public function drain(int $step): array
    {
        $rows = [];
        foreach (McpBackgroundCalls::takeFinished() as $frame) {
            $rows[] = new UserMessage(
                Lang::t('mcp.background.finished', [
                    'id' => $frame['job'],
                    'server' => $frame['server'],
                    'tool' => $frame['tool'],
                ]) . "\n" . $frame['result']->content(),
            );
        }

        return $rows;
    }

    /**
     * The composite wiring asks for a member only where the registry can
     * exist at all; a home this process may not write means no background
     * delivery was ever possible, and {@see CompositeTurnInbox::of()} drops
     * the null so a pcntl-less or home-locked launch keeps its exact pre-3.d
     * inbox pair.
     */
    public static function forTurn(): ?self
    {
        return McpBackgroundCalls::directory() === null ? null : new self();
    }
}
