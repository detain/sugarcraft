<?php

declare(strict_types=1);

namespace SugarCraft\Code\Permissions;

use React\Promise\Deferred;
use SugarCraft\Code\App\PermissionRequestMsg;

/**
 * The transient state of one permission prompt, as a value object.
 *
 * These five fields lived directly on Chat (crush_feat.md §1 E2 and the
 * rounds that followed); the FSM that READS and WRITES them is being lifted
 * into this namespace family in steps, and this class is where the STATE
 * settled first, so the prompt's data has one home while Chat keeps only the
 * thin {@see \SugarCraft\Code\Chat::pendingPermission()} /
 * {@see \SugarCraft\Code\Chat::permissionStage()} delegates its renderer
 * readers call.
 *
 * Every field is the Chat-era field, byte for byte in meaning:
 *
 *   - `pendingPermission` — the prompt currently blocking the turn (§1 E2);
 *   - `stage` — how far that prompt is along answering itself;
 *   - `deferred` — the settle-handle of the Cmd that suspends the turn;
 *   - `jobs` — the gated batch parked while the prompt shows;
 *   - `scopeEdit` — the modal's open scope-editor draft and refusal note.
 *
 * The durable session grant map deliberately did NOT join this object:
 * `permissionGrants` is keyed to the SESSION, not the prompt, it is loaded by
 * a launch-time reader outside the FSM, and Chat's carry map resets it on a
 * session switch — a different lifetime, kept a different field.
 *
 * Immutability mirrors {@see \SugarCraft\Code\Palette\PaletteState}: public
 * readonly promoted properties, and a `with*`-family that returns a new
 * instance. {@see with()} is the shape Chat::mutate() used to absorb these
 * overrides — Chat-era change keys are honoured verbatim by the rule spelled
 * in {@see OVERRIDE_KEYS} so the FSM's `mutate([...'pendingPermission'...])`
 * writes relocated without a spelling change; an unknown key is ignored,
 * exactly as Chat's carry map ignored a non-parameter key before it widened.
 *
 * Mirrors no upstream file: opencode keeps this state in its React store,
 * charmbracelet has no permission prompt.
 */
final class PermissionFlow
{
    /**
     * Chat-era mutate() change key => this object's property. The parse
     * boundary between "a change naming the prompt" and "a change naming a
     * Chat field"; kept as data so the rename lives in one readable place.
     *
     * @var array<string, string>
     */
    public const OVERRIDE_KEYS = [
        'pendingPermission' => 'pendingPermission',
        'permissionStage' => 'stage',
        'permissionDeferred' => 'deferred',
        'pendingPermissionJobs' => 'jobs',
        'permissionScopeEdit' => 'scopeEdit',
    ];

    /**
     * @param ?PermissionRequestMsg $pendingPermission The permission prompt
     *        currently blocking this turn, or null when nothing is waiting on
     *        the user (crush_feat.md §1 E2). Set by
     *        {@see \SugarCraft\Code\Chat::requestPermission()}, cleared by
     *        {@see \SugarCraft\Code\Chat::answerPermission()};
     *        {@see \SugarCraft\Code\Renderer} reads it through
     *        {@see \SugarCraft\Code\Chat::pendingPermission()}.
     * @param PermissionPromptStage $stage How far $pendingPermission is along
     *        at answering itself, and the reason an ordinary slash command
     *        typed at a live prompt no longer answers it — see
     *        {@see \SugarCraft\Code\Chat::handlePermissionKey()} for the rule
     *        and the measured table, and {@see PermissionPromptStage} for the
     *        states.
     *
     *        Meaningless while $pendingPermission is null; every raise sets it
     *        back to {@see PermissionPromptStage::Armed}
     *        ({@see \SugarCraft\Code\Chat::requestPermission()} was the ONLY
     *        writer of a non-null $pendingPermission, so arming there covers a
     *        first ask and a queued one alike).
     * @param ?Deferred $deferred The {@see Deferred} whose promise is the Cmd
     *        that keeps the turn suspended while $pendingPermission is
     *        showing. Answering resolves it, which is the whole "block on a UI
     *        decision" mechanism — the same Deferred object is shared by every
     *        clone in between, so the instance that receives the reply settles
     *        the promise the instance that raised the prompt handed out.
     * @param list<array{0: \SugarCraft\Code\ToolCall, 1: ?\SugarCraft\Code\ToolResult, 2: ?\SugarCraft\Code\Hooks\HookContext, 3: ?\SugarCraft\Code\Hooks\HookResult, 4: string}> $jobs
     *        The gated batch parked while $pendingPermission is showing, in
     *        {@see \SugarCraft\Code\Chat::gateToolCall()}'s return shape.
     *
     *        Carried across the pause rather than re-gated on resume so the
     *        `PreToolUse` chain runs EXACTLY once per tool call: re-gating
     *        would fire every hook's side effects (AuditHook's log line,
     *        accumulated state) a second time, and would re-ask the question
     *        the user just answered.
     * @param ?array{draft: string, error: ?string} $scopeEdit The modal's
     *        scope editor (`e`) while it is open: the draft that was in the
     *        box when it opened (put back when it closes), and why the last
     *        Enter was refused. Null while the editor is closed.
     */
    public function __construct(
        public readonly ?PermissionRequestMsg $pendingPermission = null,
        public readonly PermissionPromptStage $stage = PermissionPromptStage::Armed,
        public readonly ?Deferred $deferred = null,
        public readonly array $jobs = [],
        public readonly ?array $scopeEdit = null,
    ) {
    }

    /**
     * Fresh flow with every prompt cleared — the shape an answered, dismissed
     * or generation-stale prompt collapses to (Chat's Disarm).
     */
    public static function disarmed(): self
    {
        return new self();
    }

    /**
     * Apply prompt-state overrides to a copy of this flow.
     *
     * @param array<string, mixed> $overrides Chat-era change keys (see
     *        {@see OVERRIDE_KEYS}); keys naming anything else are ignored.
     */
    public function with(array $overrides): self
    {
        $mapped = [];
        foreach (self::OVERRIDE_KEYS as $changeKey => $property) {
            if (\array_key_exists($changeKey, $overrides)) {
                $mapped[$property] = $overrides[$changeKey];
            }
        }

        return new self(...array_merge(get_object_vars($this), $mapped));
    }
}
