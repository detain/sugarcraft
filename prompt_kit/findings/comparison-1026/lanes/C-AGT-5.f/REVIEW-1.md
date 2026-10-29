# C-AGT-5.f REVIEW-1 (round-1, reviewer fresh session)

Tree judged: worktree /home/sites/cc-cmp-C-AGT-5.f, branch cc/C-AGT-5.f tip 4c1dbe12d + 07ea10067 over merge-base 6d0c79851. Authors 2/2 detain@interserver.net, zero Co-Authored trailers — PASS. Master comparison point 003cb1e4e.

## VERDICT: FINDINGS (1 CRITICAL / 1 MAJOR / 1 MINOR)

### 1. CRITICAL — candy-code/src/Chat.php:2966 (committed tip): `$msg->toolCall->name()` is a guaranteed fatal on the armed permission-ask path
`PermissionRequestMsg->toolCall` is `SugarCraft\Code\ToolCall` (src/App/PermissionRequestMsg.php:11; src/ToolCall.php) — a plain value object with `public readonly string $name` and NO `name()` method and NO `__call` (src/ToolCall.php:81's `$call->name()` is the Engine ToolCall inside fromEngineCall, a different class). The committed requestPermission webhook dispatch therefore throws `Error: Call to undefined method SugarCraft\Code\ToolCall::name()` on EVERY armed TUI permission prompt — production core flow. The fix already exists UNCOMMITTED in the worktree: drop the parens (`->name`), matching the sibling idiom PermissionPrompt.php:654 and the 38 other bare-property call sites. Committed tests that drive ask (PermissionFsmExtractionParityTest; 26 requestPermission/PermissionRequestMsg refs) would red at tip; my gates ran on the working tree (which carries the fix) so never observed it. FIX: commit the one-line hunk.

### 2. MAJOR — the lane's entire regression-test deliverable is untracked; the branch as committed has zero tests
BRIEF.md promises `create candy-code/tests/Automation/OutboundWebhookWiringTest.php`; the tip commit is literally titled "in-flight cut" and REPORT.md says "Status: in flight", gates "(pending)". The 433-line/10-method file EXISTS on disk untracked and covers exactly the DoD pins — tier-by-construction, dark launch, endpoint parse, refuse-launch, settle dial + queued-not-completed guard, armed ask dial, no-subscription no-dial, served feed leg (testAnArmedApproval… is also the pin that discriminates finding 1). FIX: commit tests/Automation/OutboundWebhookWiringTest.php on the branch; at weld this adds +10T so the welder owes the suite-figure.json/durations.tsv re-pin (lane-forbidden files — welder duty, disclosed here).

### 3. MINOR (step-local) — tests/Automation/OutboundWebhookWiringTest.php:173-174, :301-302: duration pins do not discriminate a never-firing completedAt stamp
Both settle tests assert only `assertIsFloat` + `assertGreaterThanOrEqual(0.0, …duration_seconds)` — a hardcoded 0.0 constant would pass. The stamping itself is real by code-reading (TUI: settle recomputes tool-call window + `$message->createdAt - $turnPrompt->createdAt`, inside the same !$inFlight bell guard; serve: TURN_STARTED ms stamp → (ts−startedMs)/1000, honest 0.0 only when the feed attaches mid-turn), but checklist item (3) demands the predicate fire under test. FIX: space the fixture stamps (explicit createdAt / injected clock offset) and assert a positive derived value so the 0.0 constant mutation reddens.

## Adjudication checklist — live-code dispositions
1. Dialer MOUNTED + reachable: YES (beyond the accessor claim). Bootstrap::workspace() registers WebhookDispatcher::fromConfig as a workspace service when user-tier webhooksOut is non-empty; workspace() production callers = Serve.php:624, Workspace/WorkspaceHostProcess.php:137, and chat() hands the workspace to Chat at Bootstrap.php:1536. Chat::dispatchOutboundWebhook reads `$this->workspace?->service(...)`; ServerContext::background() passes it positionally into BackgroundEvents::new (6th optional param). No setter-with-zero-callers pattern. Bad-map config = PermissionConfigException exit-2 (outboundWebhookEndpoints reads ONLY $userConfig; key absent from LAYERED_KEYS so a project tier cannot widen outbound).
2. requestPermission: dispatch lands AFTER the armAsk null guard (superseded asks return early, never dispatch), beside the bell. The verdict/recordToolRun threading from the old narrative is NOT part of this diff and is not needed by the engine's 4-event vocabulary — live shape correct, but marred by finding 1.
3. completedAt: real computation both hosts (see finding 3 for the weak pin).
4. Lang keys settings.webhooksOut.label/.help/.refuse all consumed (ServerSettings.php:172-173, Bootstrap.php:2209); candy-code ships en.php only — no locale parity owed. SERVER.md/SETTINGS.md truthful and regen-consistent: drift filter (SettingsSchemaDocDrift|DocFigureProseDrift|ReadmeSettingsTierClaim|ReadmeRosterDrift) 127T/4430A OK; "nothing dials outbound" superseded, session-ended honestly marked no-emitter-yet.
5. DocFigure settings-count word handled in-step (green above).
6. E646: clean — lane adds zero timers; per-POST bounds ride the welded engine (CONNECT 2.0 / POST 10.0 / backoff, WebhookDispatcher at C-AGT-5.e 23e4dae80); no wall-clock kill anywhere; LLM paths untouched.
7. Scope: committed diff = exactly the 9 BRIEF-declared files (283+/9−). EventCollector.php NOT touched; App.php NOT touched; Bootstrap edit confined to the declared [bootstrap:workspace] region + its helper. No undeclared files.
8. Forbidden: suite-figure.json, parallel-tests-durations.tsv, dist/, protocol trio — all absent from diff (and from working dirt).

## Gates run
- Worktree, full task filter (Automation|BackgroundEvents|Webhook|EventSink|EventCollector|ServerContext|DocFigure|Settings): 1149T/23303A/7F. Attribution: 5 reproduced at pristine master 003cb1e4e under the settings-family filter (ApplySettings x2, MediaOptions schema, SettingsWriterCensus, SettingsEditor search) = foreign base-reds; the 2 ToolCapSettingsTest reds are base-inherited — master 003cb1e4e IS the cure ("W18 wave cure: ToolCapSettingsTest webSearchBackend roster line…") landing AFTER merge-base 6d0c79851; the cured roster iterates ToolLimits::KEYS only, so webhooksOut cannot redden it post-weld. ZERO lane-attributed reds.
- Mutation-reasoning: neuter service registration → settle + served dials red (dark test stays green, correct polarity); drop ask dispatch → armed-ask test red (once committed); completedAt→0.0 → NO red (finding 3).
- vitest N/A (no candy-code-web files in diff or working tree).
- PtyRunToolTest exclusion honored.

## Summary for welder
Pick needs: commit the ->name fix + commit the untracked test file, then +10T re-pin pair + durations.tsv row. FINDINGS — one CRITICAL, one MAJOR; repair = two commits of work already on disk.
