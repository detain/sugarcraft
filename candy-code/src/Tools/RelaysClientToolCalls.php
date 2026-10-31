<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tools;

/**
 * A tool whose execution is a call OUT to the attached client, relayed over
 * the turn channel rather than answered by anything in this process.
 *
 * This is its own seam, not a reuse of {@see RelaysPermissionAsks}, because
 * the two answers are different objects wearing the same socket: a permission
 * ask resolves to an {@see \SugarCraft\Code\Permissions\ApprovalVerdict} whose
 * clipped note surfaces to EVERY client as `permission.requested`, while a
 * client-hosted tool call resolves to a tool result that belongs to exactly
 * one conversation and one model turn. Folding them would leak a browser's
 * private answer into the shared permission feed, or hide a refusal's verdict
 * inside a tool error. The frames ride the same socket (ChildChannel), the
 * semantics stay a room apart.
 *
 * The bound closure is the CHILD-side sender: it writes a `client_call` frame
 * to the serve parent, blocks for the matching `client_result` (buffering any
 * steer or cancel frames that overtake the reply, exactly as
 * {@see \SugarCraft\Code\Backend\ChildChannel::ask()} does for replies), and
 * settles an early EOF as an error result — never as a success.
 */
interface RelaysClientToolCalls
{
    /**
     * Return a copy of this tool that answers calls through `$caller`.
     *
     * @param \Closure(string $toolCallId, string $name, array<string, mixed> $arguments, int $deadlineSeconds): ToolResult $caller
     */
    public function withClientCaller(\Closure $caller): Tool;
}
