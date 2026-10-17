<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tools;

/**
 * A {@see Tool} whose run raises permission questions of its own — today
 * only {@see BuiltIn\TaskTool}, whose delegated run gates every call it makes.
 *
 * The approver such a tool's run inherits answers only in the process that
 * built it (the turn child's {@see \SugarCraft\Code\Backend\ChildChannel}),
 * so a member of a concurrent group, which runs in its own forked child,
 * could only be refused. {@see \SugarCraft\Code\Runtime::executeConcurrently()}
 * closes that gap (roadmap 1.C-5): before forking such a member it opens a
 * {@see \SugarCraft\Code\Support\PermissionAskRelay}, the child runs the copy
 * {@see withPermissionApprover()} returns, and the turn child answers each
 * relayed question with its own approver.
 */
interface RelaysPermissionAsks
{
    /**
     * The same tool, putting every question its run raises to $approver
     * instead of the approver it was bound with.
     *
     * @param \Closure(ToolCall, \SugarCraft\Code\Hooks\HookResult): \SugarCraft\Code\Permissions\ApprovalVerdict $approver
     */
    public function withPermissionApprover(\Closure $approver): Tool;
}
