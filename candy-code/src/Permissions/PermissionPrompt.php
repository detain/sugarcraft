<?php

declare(strict_types=1);

namespace SugarCraft\Code\Permissions;

use SugarCraft\Code\Backend\PendingAsk;
use SugarCraft\Code\Lang;
use SugarCraft\Code\ToolCall;

    /**
 * Everything the live permission prompt offers the user, derived from the
 * flow state — the consult half of the permission FSM, extracted from Chat
 * in W5-A (the raise/resolve bodies join in the commits that follow).
 *
 * A stateless snapshot: constructed per read from the current
 * {@see PermissionFlow} and the project root, so an immutable Chat always
 * consults the truth of the prompt it is showing. Chat's public accessors
 * delegate here verbatim; the forensic prose moved with the behaviour.
 *
 * Mirrors no upstream file: opencode keeps this advice in its React
 * component tree, charmbracelet has no permission prompt.
 */
final class PermissionPrompt
{
    public function __construct(
        private readonly PermissionFlow $flow,
        private readonly string $projectRoot,
    ) {
    }

    /**
     * What `a` + `y` would remember for the question that is up, as the modal
     * names it before the user commits: a pattern (`Bash(git status *)`),
     * `this exact command` / `this exact call`, or null when this question
     * cannot be remembered at all (a hook other than the gate asked it, so
     * `always` settles as `once` — {@see \SugarCraft\Code\Chat::answerPermission()}).
     *
     * Both tool paths remember a {@see SessionPermissionMemo}
     * scope ({@see \SugarCraft\Code\Chat::rememberNative()} on Chat's own: the exact call for a tool
     * whose subject is unknown).
     */
    public function alwaysScope(): ?string
    {
        $request = $this->flow->pendingPermission;
        if ($request === null) {
            return null;
        }

        $ask = $request->pendingAsk;
        if ($ask !== null) {
            return $ask->offers(PermissionReply::Always)
                ? SessionPermissionMemo::scopeOf($ask->tool, $ask->arguments, $this->projectRoot)
                : null;
        }

        foreach ($this->flow->jobs as $job) {
            if ($job[0] === $request->toolCall && $job[3] !== null) {
                if (!$job[3]->isRememberable()) {
                    return null;
                }
                $call = $request->toolCall;

                return PermissionRule::subjectArgumentName($call->name) !== null
                    ? SessionPermissionMemo::scopeOf($call->name, $call->arguments, $this->projectRoot)
                    : SessionPermissionMemo::exactScopeOf($call->name, $call->arguments, $this->projectRoot);
            }
        }

        return null;
    }

    /**
     * The {@see PermissionRule} patterns `p`
     * would save for the question that is up — the same scope `a` remembers,
     * kept only where a CONFIGURED rule reproduces the grant. Two shapes do
     * not survive that translation and are dropped here, which is what takes
     * the `p` row off the modal:
     *
     *  - a PER-PART pipeline grant (`npm test | tail` → `Bash(npm test *)` +
     *    `Bash(tail *)`): session rules cover such a line part by part
     *    ({@see SessionPermissionMemo::coversBySegments()}),
     *    but a configured `Allow` rule fires only when EVERY command of the
     *    line matches IT, so saving the parts would write rules that never
     *    answer the next launch's question about the same pipeline;
     *  - an EXACT-call grant (a launcher, a destructive command, a
     *    subject-less tool): there is no rule form for "this exact call", and
     *    the tool as a whole is never granted ({@see \SugarCraft\Code\Chat::rememberNative()}).
     *
     * The test is the configured path's own: the whole set is saved only
     * when at least one of its rules matches the FULL call as written —
     * true for a single command (whose bare `Tool(x)` rule matches, and whose
     * `Tool(x *)` sibling then rides along to cover the prefixed calls the
     * session grant covers), false for a pipeline's parts.
     *
     * @return list<string>
     */
    public function persistPatterns(): array
    {
        $request = $this->flow->pendingPermission;
        if ($request === null) {
            return [];
        }
        [$tool, $arguments] = $request->pendingAsk !== null
            ? [$request->pendingAsk->tool, $request->pendingAsk->arguments]
            : [$request->toolCall->name, $request->toolCall->arguments];
        // Chat's own tool path remembers a subject-less tool as the EXACT
        // call ({@see \SugarCraft\Code\Chat::rememberNative()}), which no rule expresses — the
        // engine path's whole-tool grant does translate, and stays.
        if ($request->pendingAsk === null && PermissionRule::subjectArgumentName($tool) === null) {
            return [];
        }
        $root = $this->projectRoot;

        $patterns = SessionPermissionMemo::patternsFor($tool, $arguments, $root);
        if ($patterns === []) {
            return [];
        }

        $full = new ToolCall($tool, $arguments);
        foreach ($patterns as $pattern) {
            $rule = new PermissionRule($pattern, PermissionAction::Allow);
            if ($rule->matches($full, true, $root)) {
                return $patterns;
            }
        }

        return [];
    }

    /**
     * What `p` would save, as the modal names it — {@see alwaysScope()}
     * when that scope is one a configured rule reproduces
     * ({@see persistPatterns()}), null when `p` has nothing to offer
     * (no `a` on this question, or its grant does not survive the rule
     * translation).
     */
    public function persistScope(): ?string
    {
        $scope = $this->alwaysScope();

        return $scope !== null && $this->persistPatterns() !== [] ? $scope : null;
    }

    /**
     * What the modal's `e` editor starts from for the question that is up —
     * the argument half of what `a` would remember (`sed * | sort * | uniq *`),
     * or the command itself when `a` would remember the exact call — or null
     * when there is nothing to edit (`a` is not offered, or the tool has no
     * subject argument, so its only scope is the tool).
     */
    public function editableScope(): ?string
    {
        $request = $this->flow->pendingPermission;
        if ($request === null || $this->alwaysScope() === null) {
            return null;
        }
        [$tool, $arguments] = $request->pendingAsk !== null
            ? [$request->pendingAsk->tool, $request->pendingAsk->arguments]
            : [$request->toolCall->name, $request->toolCall->arguments];

        return SessionPermissionMemo::editableScopeOf($tool, $arguments, $this->projectRoot);
    }

    /**
     * Why the scope being edited cannot be saved, shown on the modal, or null
     * while it has not been refused.
     */
    public function scopeError(): ?string
    {
        $error = $this->flow->scopeEdit['error'] ?? null;

        return \is_string($error) ? $error : null;
    }

    /**
     * Why $scope may not be saved as the grant for the question that is up,
     * or null when it may: it must be a well-formed pattern for this tool
     * that covers this very call ({@see SessionPermissionMemo::withPattern()}).
     */
    public function scopeRefusal(string $scope): ?string
    {
        $request = $this->flow->pendingPermission;
        if ($request === null) {
            return Lang::t('chat.permission.scope_invalid');
        }
        [$tool, $arguments] = $request->pendingAsk !== null
            ? [$request->pendingAsk->tool, $request->pendingAsk->arguments]
            : [$request->toolCall->name, $request->toolCall->arguments];
        if (SessionPermissionMemo::patternFromScope($scope, $tool) === null
            && SessionPermissionMemo::scopePatterns($scope, $tool) === null) {
            return Lang::t('chat.permission.scope_invalid');
        }

        return SessionPermissionMemo::new()->withPattern($scope, $tool, $arguments, $this->projectRoot) === null
            ? Lang::t('chat.permission.scope_does_not_cover')
            : null;
    }

    /**
     * Why the permission question that is up cannot be remembered — `asked
     * by hook protect-files`, `flagged as external-endpoint, a security
     * finding, which always asks` — so the modal says so plainly and offers
     * no `a`; null when `a` would remember it ({@see alwaysScope()})
     * or the question is the model's own (`AskUser`, `PlanExit`).
     */
    public function alwaysAsks(): ?string
    {
        $request = $this->flow->pendingPermission;
        if ($request === null || $this->alwaysScope() !== null) {
            return null;
        }

        $ask = $request->pendingAsk;
        if ($ask !== null) {
            if ($ask->question() !== null) {
                return null;
            }

            return $ask->alwaysAsks !== '' ? $ask->alwaysAsks : Lang::t('chat.permission.always_asks.unattributed');
        }

        foreach ($this->flow->jobs as $job) {
            if ($job[0] === $request->toolCall && $job[3] !== null) {
                $reason = PendingAsk::alwaysAsksReason($request->toolCall->name, $job[3]);

                return $reason === '' ? null : $reason;
            }
        }

        // A prompt raised with no attributed question behind it at all.
        return Lang::t('chat.permission.always_asks.unattributed');
    }
}
