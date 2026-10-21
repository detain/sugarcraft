<?php

declare(strict_types=1);

namespace SugarCraft\Code\Permissions;

use React\Promise\Deferred;
use SugarCraft\Code\Backend\PendingAsk;
use SugarCraft\Code\Chat;
use SugarCraft\Code\PermissionRequestMsg;
use SugarCraft\Code\Config\Settings\SettingsTier;
use SugarCraft\Code\Config\Settings\SettingsWriter;
use SugarCraft\Code\Hooks\HookResult;
use SugarCraft\Code\Host\WorkspaceContext;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Message;
use SugarCraft\Code\ToolCall;
use SugarCraft\Code\ToolResult;

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

    /**
     * Decide whether a raised ask may put a prompt up, prepare it, and hand
     * Chat the three values its arm sequence needs: the (possibly
     * label-prepended) ask, the Deferred that suspends the turn, and the
     * terminal-notification title. The superseded-generation guard returns
     * null for an ask nobody is waiting on anymore, which Chat answers with
     * its byte-identical old early-return. Lifted from
     * Chat::requestPermission() in W5-A; the mutate()/Cmd plumbing that must
     * return a Chat stayed.
     *
     * @param list<\SugarCraft\Code\Message> $history  the transcript the origin lookup reads
     * @param \SugarCraft\Code\Agents\Live\AgentLiveRegistry $live  delegated-run identities read from here
     */
    public function armAsk(PermissionRequestMsg $msg, int $generation, array $history, \SugarCraft\Code\Agents\Live\AgentLiveRegistry $live): ?ArmedPrompt
    {
        // An ASK for a turn that was abandoned (double-Escape) or otherwise
        // superseded can still land: the PreToolUse chain that raised it ran
        // against the generation that was current when the batch was gated.
        // Putting its prompt up would suspend a turn nobody is waiting on, and
        // would force inFlight true behind an overlay that OUTRANKS the prompt
        // (see Renderer::renderView()'s chain). The message carries a
        // generation for exactly this comparison; the AssistantMsg arm in
        // update() is the pattern, and $generation being null still means
        // "unstamped, apply it" there and here alike.
        //
        // DORMANT DEFENCE, stated as such rather than as a live path closed --
        // the same honest form shellOwnsKeyboard()'s unobservable conjunct is
        // documented in. Measured: the two Chat-native callers build the ASK
        // with $this->generation on the very object they then call
        // (beginToolCalls() and answerPermission(), and mutate() touches no
        // 'generation' at either site), so the comparison below is
        // tautologically FALSE at both. The THIRD constructor in `src/`, the
        // engine path wired by roadmap 1.C-2 (pumpLiveToolEvents()), stamps
        // the inbox entry's generation only after comparing it equal to
        // $this->generation and answering a stale question itself -- so it
        // cannot make the comparison true either.
        //
        // What that bounds is which tests can watch this guard FIRE. It does
        // NOT bound which tests reach a STAMPED ask, and the first version of
        // this comment claimed the second: ChatTest's whole permission suite
        // reaches one, through exactly the two producers above. They cannot
        // make the comparison true, which is the property -- not that they
        // never get here. No count is given for that suite either; the
        // previous revision said "14 ChatTest tests" and it was correct when
        // written, which is the exact shape every stale figure in this comment
        // has had. Rows 3 and 4 below measure it when a reader mutates.
        //
        // FIND THE RIGHT SITE BEFORE MUTATING. This exact predicate --
        // `$msg->generation !== null && $msg->generation !== $this->generation`
        // -- appears FOUR times across the turn-suspension
        // pair -- this one spelled against the armAsk() parameter, $generation,
        // $this->generation at the Chat sites: THREE in Chat.php (update()'s
        // AssistantMsg arm, finishToolCalls() and applyBackendToolEvent()) and
        // THIS guard, lifted out of requestPermission() with its comment in W5-A.
        // TWO of the Chat copies are byte-identical apart from indentation;
        // applyBackendToolEvent()'s is not: its arm bills the
        // superseded turn's usage before dropping its events, because the chain
        // it breaks means no AssistantMsg is ever synthesised for that turn and
        // update()'s accounting arm is never reached. So a replace-first sed
        // lands on the AssistantMsg arm, not here; the previous revision of this
        // table did exactly that and then recorded the wrong site's numbers as a
        // refutation of the right ones. The table below is therefore anchored by
        // SITE, and the mis-site's figures are kept beside it as the tell.
        // Reproduce with
        // `grep -nF 'generation !== null && $msg->generation !== $' src/Chat.php src/Permissions/PermissionPrompt.php`.
        //
        // The four sites, which method owns each, and WHICH THREE are mutually
        // indistinguishable are asserted rather than narrated, by
        // KeyHelpTest::testTheGenerationGuardPredicateAppearsInExactlyFourNamedMethods().
        //
        // Mutations of the guard belonging to THIS method -- the block
        // immediately below this comment, inside armAsk() -- each judged
        // by the targeted files going red. NO LINE NUMBER is given, deliberately:
        // the previous two revisions carried one ("1175", corrected to "1220"), it
        // was wrong both times because editing this comment moves the guard under
        // it, and the grep above plus the four-site test already name the site
        // exactly. A figure that cannot survive its own paragraph does not belong
        // in the paragraph.
        //
        // The TRIO column carries NAMES, not counts, for the same reason. The
        // revision before last recorded "1 failure / 1 error / 1 error / 1
        // failure" and "raw trio totals 2 / 2 / 2 / 2"; rows 3 and 4 were 3 raw /
        // 2 behavioural even as it was written, because the test added in that
        // very commit (testThePromptAndTheReferenceCannotBothBeRaisedByRealInput)
        // reaches a stamped-and-current ask and so trips them. A trio count is
        // fed by the files being measured; a class name (STALE / CURRENT) is not.
        //
        // The CHATTEST column carries counts, and the round that replaced them
        // with a name made the column worse rather than better -- see below the
        // table. A name is only an improvement when it identifies the population;
        // "the permission suite" identified neither of the two it was applied to.
        //
        //   | mutation                     | trio (behavioural)   | ChatTest        |
        //   |------------------------------|----------------------|-----------------|
        //   | guard deleted                | STALE only           | green           |
        //   | throw when the guard FIRES   | STALE only           | green           |
        //   | throw on ANY stamped ask     | STALE + CURRENT      | 14 errors       |
        //   | 2nd conjunct dropped, so     | STALE + CURRENT      | 1 error,        |
        //   | every stamped ask is dropped |                      | 11 failures,    |
        //   |                              |                      | 6 warnings      |
        //
        // The ChatTest figures are counts again, with their domain, because the
        // revision that replaced them with the NAME "the permission suite" named
        // a population that does not exist: the two rows report 14 and 12
        // problems, and ChatTest has no 14-test and no 12-test suite to be. What
        // the two rows share is the population, and THAT is the durable label:
        // both reds land on the SAME ELEVEN ChatTest methods, measured by name --
        // testApprovingAnAskDispatchesTheRewrittenCallTheUserWasShown,
        // testASessionGrantCannotSilentlyDispatchAnAsksOwnRewrite,
        // testAskHookSuspendsTheTurnInsteadOfRunningOrDenyingTheCall,
        // testTheSuspendingCmdStaysPendingUntilTheUserAnswers,
        // testOnceReplyRunsTheToolAndGatesItExactlyOnce,
        // testAlwaysReplyGrantsTheToolForTheRestOfTheSession,
        // testRejectReplyRefusesTheCallAndEndsTheTurn,
        // testPermissionKeysDecideThePrompt, testUnmappedKeyLeavesThePermissionPromptUp,
        // testAnsweringOneAskDoesNotReleaseTheOtherCallsInTheBatch and
        // testAlwaysForOneToolDoesNotReleaseAnAskForAnother. The counts differ
        // only because testPermissionKeysDecideThePrompt is a data-provider test:
        // row 3 reds all four of its data sets (y / a / n / escape), row 4 only
        // the two approving ones -- and the reason is worth recording, because it
        // is this round's own rule seen from the other side. Under row 4 the ask
        // is dropped, so the turn is left NOT in flight; the refusing sets assert
        // exactly `!inFlight`, so they pass VACUOUSLY on a prompt that never
        // appeared. Measured: the "y" set fails on `!$answered->inFlight` in
        // `ChatTest::testPermissionKeysDecideThePrompt()`. Domain of both
        // figures: tests/ChatTest.php alone at 215 tests, mutated at
        // armAsk()'s guard (the site this comment sits above) in a
        // sandbox copy of this lib, where Chat::requestPermission() delegates, PHP 8.3.6. Counts go stale; the eleven names
        // and the data-provider explanation are what survive a test being added.
        //
        // where the two trio classes are, and this is the RULE that keeps the table
        // true as tests are added:
        //
        //   STALE   -- a test that hands this method a stamped ask from a SUPERSEDED
        //              turn. Exactly one exists and only one can exist per distinct
        //              way of building one:
        //              KeyHelpTest::testASupersededAskNeverPutsUpAPrompt().
        //   CURRENT -- every test that reaches a stamped ask AT ALL, whoever built
        //              it. Open-ended by construction, and RE-ENUMERATED by running
        //              row 3 and reading the reds back rather than by reasoning about
        //              who ought to be in it, because the previous revision's list of
        //              "the three KeyHelpTest tests" was short by one BEFORE this
        //              round touched anything (testEachQueuedAskArmsAfresh reaches a
        //              stamped ask through answerPermission()'s resume path and was
        //              not listed). Re-measured at THIS commit, row 3 reds these SIX:
        //              KeyHelpTest::testWithAPromptUpNeitherKeyReachesItsOverlay(),
        //              KeyHelpTest::testAKeyThePromptActsOnReachesItAndYApprovesRatherThanRefuses(),
        //              KeyHelpTest::testAPromptOutlivesItsTurnAndTheReferenceIsStillRefused(),
        //              KeyHelpTest::testEachQueuedAskArmsAfresh(),
        //              MouseModalGuardTest::testAClickUnderALivePromptIsRefusedExactlyAsTheKeyIs() and
        //              MouseModalGuardTest::testAPromptRaisedOverAnOpenOverlayOutranksItOnBothDevices(),
        //              the last two being the members from outside KeyHelpTest -- both
        //              drive a real PreToolUse ask hook to put a modal up, one for the
        //              click path to be refused by and one to establish that a prompt
        //              over an open overlay is a state real input can build at all.
        //              Row 3's raw total is 8: these six, the STALE member, and the
        //              text pin.
        //              testThePromptAndTheReferenceCannotBothBeRaisedByRealInput() is
        //              NOT a member: it was split into the first two above and now
        //              stops at the in-flight half.
        //
        // Rows 1 and 2 are bounded, rows 3 and 4 are not, and that difference is the
        // whole content of the table. Row 2 is the honest bound on OBSERVABILITY:
        // exactly one test anywhere can watch this branch being taken. Row 3 is what
        // refutes the wording that claimed no other test can even REACH a stamped
        // ask. Row 4 changes BEHAVIOUR as well as observability, which is why its
        // ChatTest column is the loud one: the stamped-and-current path is what
        // ChatTest exercises.
        //
        // Measured with this comment: RendererTest, Commands/KeyBindingDriftTest and
        // Chat/InFlightInputQueueTest contribute ZERO behavioural reds to all four
        // rows, even though they are three fifths of the domain by file count --
        // their asks are UNSTAMPED, and none of these four mutations touches an
        // unstamped ask. The domain is what the rows were measured over; it is not
        // the set of files that react. That silence is a fact about THIS site: the
        // same mutations at the wrong site (below) red Chat/InFlightInputQueueTest
        // six times and RendererTest not at all, which is the reverse shape.
        //
        // Every row ALSO reds
        // testTheGenerationGuardPredicateAppearsInExactlyFourNamedMethods(), because
        // each of these mutations edits the guard's TEXT and that test reads the four
        // blocks back. By design, and the reason it is excluded from the column
        // above rather than folded into it: it is a "you changed the guard, re-read
        // this table" alarm, not evidence about the guard's reach. So raw = the
        // column above plus exactly one, on every row -- stated as a rule, because
        // the revision that stated it as "2 / 2 / 2 / 2" was already wrong on two.
        //
        // The variant that made the previous revision's error visible: discarding
        // EVERY ask rather than only stamped ones (`if (true)`) reds every trio test
        // that needs a prompt to appear at all, plus ChatTest 1 error, 11 failures,
        // 6 warnings -- so the trio is NOT silent about it, and the rows above are
        // not covering it either. It is the ONE figure in this comment kept as a
        // count, because it is the mis-site tell described below and a tell needs a
        // magnitude. Re-measured AT THIS COMMIT, over all FIVE files of the
        // domain: 31 raw / 30 behavioural, composed of 7 in RendererTest, 17 in
        // KeyHelpTest (including the pin), 4 KeyBindingDriftTest data sets, 1 in
        // Chat/InFlightInputQueueTest and 2 in MouseModalGuardTest. The figure this
        // replaces read "20 raw / 19 behavioural, composed of 5 / 12 / 3"; driven
        // against `995eb257` with none of this round's files present it came out at
        // 29 raw over the four files that then existed, so it had gone stale on its
        // own before the fifth file was written -- exactly the growth its own caveat
        // below predicts, recorded here as a re-measurement rather than as a delta.
        // The EXCLUSION RULE that produces the
        // second number, stated because the revision before last recorded a total no
        // rule produced ("14 behavioural (16 raw)"): raw MINUS exactly one, the
        // text-reading pin, testTheGenerationGuardPredicateAppearsInExactlyFourNamedMethods.
        // Nothing else is excluded.
        //
        // That total MUST NOT be read as fixed -- it grows by one for every
        // prompt-dependent test added to any of the FIVE files of the domain named
        // two paragraphs up (it grew by two on this commit, both in
        // MouseModalGuardTest). The sentence used to say "those three files", which
        // named the historical trio while sitting under a paragraph that had just
        // re-declared the domain as five. What is load-bearing is the rule and the
        // fact that the figure is not zero.
        //
        // At the WRONG site (update()'s AssistantMsg arm) the same two mutations,
        // ALL FOUR figures re-driven at this commit and each stated over BOTH
        // domains, because the previous revision compared a re-measured right-site
        // number against a wrong-site number taken over the smaller domain and
        // flagged only that it carried someone else's date:
        //
        //   * 2nd-conjunct-dropped -> 2 raw / 1 behavioural over the five-file
        //     domain (Chat/InFlightInputQueueTest::testTheDrainedPromptCarriesIts
        //     OwnGenerationAndCancellationToken), which is 1 raw / 0 behavioural
        //     when restricted to the historical trio; ChatTest 6 failures.
        //   * `if (true)` -> 13 raw / 12 behavioural over the five-file domain,
        //     composed of 5 in KeyHelpTest (including the pin), 6 in
        //     Chat/InFlightInputQueueTest and 2 in MouseModalGuardTest; restricted
        //     to the trio that is 5 raw / 4 behavioural. ChatTest 1 error, 37
        //     failures, 6 warnings, over its 222 tests.
        //
        // The previous revision published "trio 3" for that last one. It is 4
        // behavioural over the trio now, and the reason is the one its own sentence
        // gave and then failed to apply: it tracks the real-gate group in
        // KeyHelpTest, and that group became FOUR when testEachQueuedAskArmsAfresh
        // was added to the CURRENT list above -- a correction made in one half of
        // this comment and not in the half that cites it.
        //
        // The old tell -- "zero behavioural trio reds means you mutated the wrong
        // place" -- is now HALF TRUE and is corrected rather than repeated: it holds
        // for 2nd-conjunct-dropped and no longer for `if (true)`, because the
        // KeyHelpTest tests built on promptRaisedByTheRealGate() drive a real
        // AssistantMsg through update() and so react at BOTH sites. The tell that
        // does not decay is the magnitude AND the population, both re-measured
        // above over the SAME five-file domain: at the right site `if (true)` reds
        // the whole prompt-dependent population (30 behavioural, led by RendererTest
        // and KeyHelpTest), at the wrong site 12. The population tell is a RATIO,
        // not a zero, and the previous revision wrote it as a zero fifty lines below
        // its own enumeration, which had already said otherwise:
        // Chat/InFlightInputQueueTest is 1 of the right site's 30 and 6 of the wrong
        // site's 12 -- three percent against half. So the two sites are told apart
        // by WHICH files answer as much as by how many: a mutation at the right site
        // can make at most one queue test fail while one at the wrong site makes six,
        // and one at the wrong site cannot make RendererTest or KeyBindingDriftTest
        // fail at all.
        // And the four-site text pin fires at either site, which is the cheapest
        // signal that you edited SOMETHING and must re-read this table.
        //
        // Domains: "trio" is the historical name for tests/RendererTest.php +
        // tests/Renderer/KeyHelpTest.php + tests/Commands/KeyBindingDriftTest.php,
        // the three files that CONSTRUCTED a PermissionRequestMsg when the rows
        // below were first measured; the set is FIVE today (see the paragraph after
        // next) -- asserted, not narrated, and by a token scan for
        // `new …PermissionRequestMsg` rather than by the
        // `grep -rl PermissionRequestMsg tests/` this line used to cite, which also
        // matches files that merely mention the class in a comment:
        // KeyHelpTest::testTheGuardMutationDomainIsTheFilesThatBuildAPermissionRequestMsg(),
        // whose docblock states what that instrument can and cannot see. PHP 8.3.6.
        //
        // A FOURTH file joined without costing a re-measurement: W2's
        // tests/Chat/InFlightInputQueueTest.php raises an UNSTAMPED ask (to reach
        // answerPermission()'s denial path, which is one of the four places a queued
        // prompt is released), and no row of this table touches an unstamped ask --
        // the same reason two of the original three contribute nothing.
        //
        // A FIFTH did cost one, and the rows above are the re-measurement.
        // tests/MouseModalGuardTest.php hand-builds an unstamped ask for most of its
        // states, but ONE of its tests raises a prompt through a real PreToolUse ask
        // hook and so reaches a stamped, current one. That is the rule this comment
        // already stated, applied: re-measuring is due when a file joins the set
        // with a STAMPED ask.
        //
        // ChatTest is measured separately BECAUSE rows 3 and 4 show the domain's
        // silence does not cover it. Rows 1 and 2 red inside KeyHelpTest alone; rows
        // 3 and 4 red there AND in MouseModalGuardTest, which is what stopped
        // "KeyHelpTest ALONE" being true. RendererTest, KeyBindingDriftTest and
        // InFlightInputQueueTest build UNSTAMPED asks, which no row of this table
        // touches.
        //
        // So this is not what makes the reference-over-prompt state
        // unreachable, and the sentence claiming it was "the one way that state
        // is reachable through the front door" was wrong: there is no producer
        // for that door today. That unreachability is now asserted rather than
        // stated -- driven from both ends through a real PreToolUse ask hook by
        // KeyHelpTest::testThePromptAndTheReferenceCannotBothBeRaisedByRealInput().
        // What actually protects the user there is the cue
        // -- Renderer::KEY_HELP_OVER_PROMPT -- which is driven and does bite.
        // The guard stays because the engine path is coming and an unstamped
        // ASK is the legitimate case it must keep letting through; deleting a
        // correct guard because today's producers cannot trip it is how the
        // path arrives unprotected.

        if ($msg->generation !== null && $msg->generation !== $generation) {
            return null;
        }


        // P-E2: a delegated run's question says whose it is, at the top of
        // the modal — the same question also shows inline in that run's
        // Agent View (Renderer). The main turn's own questions are untouched.
        $origin = $msg->pendingAsk === null
            ? null
            : AskOrigin::locate($msg->pendingAsk, $history, $live);
        if ($origin !== null) {
            $label = AskOrigin::label($origin);
            $msg = new PermissionRequestMsg(
                $msg->assistantMessage,
                $msg->toolCall,
                $msg->prompt === '' ? $label : $label . "\n" . $msg->prompt,
                $msg->generation,
                $msg->pendingAsk,
            );
        }

        $deferred = new Deferred();

        // Roadmap 5.14a: the agent is now waiting on the user, which is the
        // other moment the `notify` setting rings for.
        return new ArmedPrompt($msg, $deferred, 'candy-code: ' . Lang::t('chat.notify.awaiting_approval', [
            'tool' => $msg->toolCall->name,
            'origin' => $origin === null ? '' : Lang::t('chat.notify.awaiting_approval_origin', [
                'agent' => $origin->name !== '' ? $origin->name : $origin->id,
            ]),
        ]));
    }

    /**
     * The label `always` records for this ask: the user-written scope
     * (`e`) labelled through the memo, or the gate's own
     * {@see alwaysScope()} when no scope was written.
     */
    public function rememberedLabel(PermissionRequestMsg $request, ?string $scope): ?string
    {
        return $scope !== null
            ? \SugarCraft\Code\Permissions\SessionPermissionMemo::scopeLabel(
                $scope,
                $request->pendingAsk?->tool ?? $request->toolCall->name,
                $request->pendingAsk?->arguments ?? $request->toolCall->arguments,
                $this->projectRoot,
            )
            : $this->alwaysScope();
    }

    /**
     * The session grants after an `always` answer at an engine-path ask:
     * the user-written scope recorded as a pattern, else a grant for this
     * exact call, appended to the grants already held.
     */
    public function enginePathGrants(array $grants, PendingAsk $ask, ?string $scope): array
    {
        $memo = \SugarCraft\Code\Permissions\SessionPermissionMemo::fromGrants($grants);
        $memo = ($scope === null ? null : $memo->withPattern($scope, $ask->tool, $ask->arguments, $this->projectRoot))
            ?? $memo->withGrant($ask->tool, $ask->arguments, $this->projectRoot);

        return [...$grants, ...$memo->grants()];
    }

    /** What the transcript row says the user answered a delegated ask with. */
    public static function answeredLabel(PermissionReply $reply, PendingAsk $ask, string $note): string
    {
        return match (true) {
            $reply === PermissionReply::Always && $ask->offers(PermissionReply::Always) => Lang::t('chat.permission.answer.always'),
            $reply->permits() => Lang::t('chat.permission.answer.once'),
            default => $note === ''
                ? Lang::t('chat.permission.answer.refused')
                : Lang::t('chat.permission.answer.refused_note', ['note' => $note]),
        };
    }

    /**
     * The two transcript rows a user refusal leaves when the batch path
     * ends the turn.
     *
     * A rejection's feedback is the model's to read, on this path as
     * on the engine one (1.C-2).
     */
    public static function refusalPair(PermissionRequestMsg $request, string $note): array
    {
        $refusal = \SugarCraft\Code\Permissions\ApprovalVerdict::rejectedByUser($note)
            ->denialMessage("{$request->toolCall->name} was not run.");

        return [
            // Deliberately AGENT-VISIBLE (audit 15b-03): EngineBackend's
            // toTypedMessages() flattens tool calls and results away,
            // so on that path this note is the model's only record
            // that the call it asked for was refused.
            Message::system('_' . DenialKind::Refused->reason($refusal) . '_'),
            // The refusal also has to exist as a RESULT, not only as
            // a system note (crush_feat.md §1 E7): the assistant
            // message above carries the tool call, so leaving it
            // unanswered puts a tool_use block on the next request's
            // wire with no matching tool_result. It doubles as the
            // producer for the struck-through denied row
            // {@see Renderer::renderToolResults()} draws.
            Message::assistant('')->withToolResults([ToolResult::denied(
                $request->toolCall->name,
                DenialKind::Refused,
                $refusal,
                $request->toolCall->id,
            )]),
        ];
    }

    /**
     * The outstanding question for $call among the parked batch jobs, if it
     * has one — slot 3 carries the HookResult ask exactly as the inline
     * lookup read it; untyped here so the method stays the byte-equivalent
     * of the loop it replaces.
     */
    public static function askFor(array $jobs, ToolCall $call)
    {
        foreach ($jobs as $job) {
            if ($job[0] === $call) {
                return $job[3];
            }
        }

        return null;
    }

    /**
     * The parked batch after the user answered: drop ONLY the ASK they
     * just answered (plus, under a fresh Always grant, other queued asks
     * the same grant answers) — consent for one call is not consent for
     * another. Slot 4 (the pre-hook note) rides across the re-entry so a
     * settled question's model-visible context reaches the result the same
     * way an immediate permission's does.
     */
    public static function releasedJobs(array $jobs, ToolCall $answeredCall, array $grants, ?string $root): array
    {
        return array_map(
            static function (array $job) use ($answeredCall, $grants, $root): array {
                if ($job[3] === null) {
                    return $job;
                }

                $answered = $job[0] === $answeredCall || self::grantCovers($grants, $job[0], $job[3], $root);

                return $answered ? [$job[0], $job[1], $job[2], null, $job[4]] : $job;
            },
            $jobs,
        );
    }
}
