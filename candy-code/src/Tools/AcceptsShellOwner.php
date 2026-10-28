<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tools;

/**
 * Opt-in declaration that a {@see Tool} talks to the launch's
 * {@see \SugarCraft\Code\Background\BackgroundShellRegistry} on behalf of ONE
 * session, and the seam a {@see \SugarCraft\Code\Backend\EngineBackend} uses
 * to bind that session into the tool before the turn runs.
 *
 * WHY A SEAM OF ITS OWN. A served launch shares one registry across every
 * session it hosts — same workspace, same backend, same tool instances — so
 * without a tag each session's Bash/ShellOutput/ShellStop would read and stop
 * every other session's shells. The fix is owner tagging on the rows, not a
 * table split: one map keeps the launch's single-pass teardown guarantee
 * intact, and the tag scopes answers only. The tools that consult the
 * registry implement this interface so the turn can hand them the session id
 * they tag rows and filter lookups with; a null owner (a launch that binds no
 * session — plain TUI, or a tool built outside the turn) keeps every row
 * visible, which is exactly the pre-tag behavior. Bash implements this seam
 * and {@see AcceptsToolProgress} together, and its rebuilds carry every field,
 * so the turn binds the owner BEFORE the progress arm and the two never fight.
 */
interface AcceptsShellOwner
{
    /**
     * This tool claiming shells for session $owner, every other field kept.
     *
     * @param string|null $owner the session id to tag new rows with and scope
     *                           lookups by; null means "bound to nobody", and
     *                           untagged rows stay visible to all
     */
    public function withShellOwner(?string $owner): self;
}
