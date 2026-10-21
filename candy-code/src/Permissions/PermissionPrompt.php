<?php

declare(strict_types=1);

namespace SugarCraft\Code\Permissions;

use SugarCraft\Code\Backend\PendingAsk;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Config\Settings\SettingsTier;
use SugarCraft\Code\Config\Settings\SettingsWriter;
use SugarCraft\Code\Hooks\HookResult;
use SugarCraft\Code\Host\WorkspaceContext;
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
        private readonly ?WorkspaceContext $workspace = null,
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

/**
     * Append $patterns to the user's `permissionRules` in `config.json`,
     * through the workspace's {@see SettingsWriter}
     * — the settings editor's own write door, so the schema's validation of
     * the key (refusing a value the launch would not load whole) is this
     * write's validation too. Existing entries ride in front of the new ones:
     * rules are first-match-wins, so a `deny` the user wrote keeps refusing
     * what `p` saves, and the grant only answers questions the mode would
     * have asked anyway. An entry already there is not written twice.
     *
     * Returns the notice the transcript shows — where it landed, or why it
     * did not. A failed save is REPORTED, never fatal, exactly as a model
     * choice that cannot be persisted still switches for the session: the
     * session grant `p` rides on has already been recorded.
     *
     * @param list<string> $patterns
     */
    public function persistRules(array $patterns): string
    {
        $scope = implode(', ', $patterns);
        $writer = $this->workspace?->service(SettingsWriter::class);
        if (!$writer instanceof SettingsWriter) {
            return Lang::t('chat.permission.not_persisted', [
                'scope' => $scope,
                'error' => Chat::reportField('this session has no settings write door'),
            ]);
        }

        try {
            $config = $writer->current(SettingsTier::You);
            $existing = $config['permissionRules'] ?? [];
            $existing = \is_array($existing)
                ? array_values(array_filter($existing, static fn (mixed $rule): bool => \is_array($rule)))
                : [];
            $have = [];
            foreach ($existing as $rule) {
                if (($rule['action'] ?? null) === 'allow' && \is_string($rule['pattern'] ?? null)) {
                    $have[$rule['pattern']] = true;
                }
            }

            $next = $existing;
            foreach ($patterns as $pattern) {
                if (!isset($have[$pattern])) {
                    $next[] = ['pattern' => $pattern, 'action' => 'allow'];
                }
            }
            $path = $next === $existing
                ? (string) $writer->targetPath(SettingsTier::You)
                : $writer->write(SettingsTier::You, ['permissionRules' => $next]);

            return Lang::t('chat.permission.persisted', [
                'scope' => $scope,
                'path' => Chat::reportField($path),
            ]);
        } catch (\Throwable $e) {
            return Lang::t('chat.permission.not_persisted', [
                'scope' => $scope,
                'error' => Chat::reportField($e->getMessage()),
            ]);
        }
    }

/**
     * Whether a grant in $grants answers this ask on Chat's own tool path —
     * the SAME memo, keys and scopes the engine path uses
     * ({@see SessionPermissionMemo}), so `a`
     * means the same thing whichever path a call took (this path kept an
     * exact-call key of its own, so `a` on `git status` never covered
     * `git status -s` here).
     *
     * NEVER FOR AN ASK NO GRANT MAY ANSWER ({@see HookResult::isRememberable()},
     * stamped by the registry, which a hook cannot forge): a user hook's ASK
     * is a question about this call's content — the reason the hook exists —
     * and a security finding is asked every time (audit F-P9).
     *
     * @param array<string, bool> $grants
     */
    public static function grantCovers(
        array $grants,
        ToolCall $toolCall,
        HookResult $ask,
        ?string $projectRoot = null,
    ): bool {
        return $ask->isRememberable()
            && SessionPermissionMemo::fromGrants($grants)
                ->allows($toolCall->name, $toolCall->arguments, $projectRoot);
    }

/**
     * $grants with "always" remembered for $toolCall on Chat's own tool path,
     * or $grants unchanged when the ask may not be remembered. A tool whose
     * subject argument is known ({@see PermissionRule::SUBJECT_ARGUMENTS})
     * gets the engine path's scope (or the user's own, $scope, from the
     * modal's `e`); any other tool is remembered as the exact call — an
     * embedder's tool has no subject that can be read, and the memo would
     * otherwise grant it whole.
     *
     * @param array<string, bool> $grants
     *
     * @return array<string, bool>
     */
    public static function rememberNative(
        array $grants,
        ToolCall $toolCall,
        HookResult $ask,
        ?string $projectRoot,
        ?string $scope = null,
    ): array {
        if (!$ask->isRememberable()) {
            return $grants;
        }
        $memo = SessionPermissionMemo::fromGrants($grants);
        $memo = match (true) {
            $scope !== null => $memo->withPattern($scope, $toolCall->name, $toolCall->arguments, $projectRoot) ?? $memo,
            PermissionRule::subjectArgumentName($toolCall->name) !== null
                => $memo->withGrant($toolCall->name, $toolCall->arguments, $projectRoot),
            default => $memo->withExactGrant($toolCall->name, $toolCall->arguments, $projectRoot),
        };

        return [...$grants, ...$memo->grants()];
    }

}
