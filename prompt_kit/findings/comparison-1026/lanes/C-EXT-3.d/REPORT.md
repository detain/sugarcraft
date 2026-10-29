# C-EXT-3.d — agents-proposed draft dir + /agents accept|proposed consent gate

VERDICT: DONE. Single commit `b1c24fa69` on `cc/C-EXT-3.d` (base `7e322e44d`), author/committer detain@interserver.net (1/1 separate greps), zero Co-Authored, tree clean.

## Shipped (11 files, +941/−6)
- `/agents new` drafts to `.candy-code/agents-proposed/<name>.md` via NewAgentPrompt (mirrors NewRulePrompt); Chat arm ONLY inside [chat:handleAgentsCommand] using withInputBuf->submit.
- `/agents accept <name>` consent gate: schema+DRAFT_NAME+containment validation, PRINTS permissionMode/tools/mcpServers verbatim, then rename into agents tree.
- `/agents proposed` lists pending drafts one-line each; builtin-commands + COMMANDS.md + AGENTS_AUTHORING.md rows in-step (§1.8).
- REAL DEFECT FOUND+CLOSED during verification: `is_link` check on the draft file missed a grafted `.candy-code/agents-proposed` DIRECTORY symlink — a bypass-permissions draft could be promoted from outside the project (pre-fix reproduction: draft actually promoted). Fixed with two ContainedPath::below doors (accept + proposed legs); 2 regression tests proven red pre-fix, green post.
- Pin proves WRITE_ONLY_PATTERNS still denies direct agents/ writes (detour doesn't weaken fence).

## Guards moved in-step (§1.8)
- ReadPathCensusTest: +3 roster rows (AgentsHostCommand glob=NAMES_ONLY, file_get_contents×2=CONTAINED now measured-true; NewAgentPrompt glob=NAMES_ONLY).
- ContainedPathInventoryTest ROUTED_CALL_SITES += AgentsHostCommand=>2; ContainedPath.php docblock sentence re-synced SIXTY-THREE/THIRTY-ONE.

## Gates (plain pipe, cwd candy-code, -c phpunit.xml)
- Brief filter (+ContainedPathInventory): 2160T/14905A — 3F ALL foreign base-standing (proven at 7e322e44d): ForeignAgentPresetWiring SchedulesCommand row; ContainedPathInventory 2 arms (AgentMemory/SkillLoader/ContextHostCommand/TranscriptProjector/PathJail drift, none lane files).
- AgentsNewAcceptTest: 19T/104A green. Combo2 449T/8318A — 2F foreign (ChatHostLangCatalogue 5 un-called chat.* keys; CliLangCatalogue cli.bundles.marketplace.none/renamed/removed mis-sort present AT BASE — lane diff touches only agents keys).
- Mutations: M1 proposed-door neuter → proposed-skips test red; M2 accept-door neuter → accept-refuses test red; cp+md5 ff2ebc27 restore verified.

## Carries (welder)
- suite-figure re-pin: +3T this lane over prior report's count (19T file; prior 16T+… final live-enum truth from battery run).
- durations.tsv row: tests/Host/Commands/AgentsNewAcceptTest.php (mint).
- Foreign reds standing at base (not lane): CliLangCatalogue sort, ChatHostLangCatalogue 5 keys, ForeignAgentPresetWiring, ContainedPathInventory×2, DocFigure 'dispatch arms' 59-vs-60.

## FIX ROUND 2 (independent verifier, 2026-10-28 18:2x–18:4x, disk-only)
- REAL TIP: b1c24fa69 (parent 7e322e44d, 18:25:31) — the live-then-dead r2 author SQUASHED 0da862e69+90e206721 plus the round-2 cure into ONE commit mid-verification. 0da862e69/90e206721 are now DANGLING on cc/C-EXT-3.d; 54204756d never existed (phantom). Author/committer both detain@interserver.net, zero Co-Authored trailers (verified).
- R1-MINOR cure present at tip and re-verified: is_link($draft) refusal before any read (AgentsHostCommand accept path), plus the stronger grafted-DIRECTORY containment (ContainedPath::below doors on accept + proposed legs) found live during this lane's round-2 churn; census rows (ReadPathCensus +3) and ContainedPathInventory row (AgentsHostCommand=>2, duplicate-key wart resolved) committed and consistent.
- Gates at clean HEAD (plain pipe, cwd candy-code, env -u LINES -u COLUMNS): AgentsNewAccept 19T/104A GREEN; ReadPathCensus 19T/661A GREEN (was 1F at dangling 90e206721 — rows cure it, red→green measured); ContainedPath 62T/2F; combo (brief's literal filter) 2111T/14822A/1F; standing foreign reds total: FAPW×1 + ContainedPathInventory×2 + ChatHostLangCatalogue/CliLangCatalogue/PermissionsIntroDrift/ProjectTierRefusalInventory(×2) 5F — ALL 8 identities reproduced IDENTICALLY at pristine base 7e322e44d (detached-tree runs) ⇒ foreign, none lane-curable.
- Mutations at HEAD, cp+md5 ff2ebc27 restores clean: drop is_link → exactly testAcceptRefusesASymlinkedDraft red; drop accept below → exactly swapped-dir accept test red; drop proposed below → exactly proposed-skips test red. (3/3 one-red-discriminating.)
- Welder supersedes earlier carries: pick b1c24fa69 ONLY (history rewritten; REVIEW-1/REVIEW-2 pick-lists cite the dangling pair); durations.tsv row AgentsNewAcceptTest mint still owed; suite-figure re-pin from battery live-enum.
## FIX ROUND 3 (r1 step-local MINOR + NITs, 2026-10-28, commit 1d8e9c906 on cc/C-EXT-3.d atop b1c24fa69)
- MINOR CURED — direct pin for the Chat `new` intercept (Chat.php handleAgentsCommand): two Chat-level tests in AgentsNewAcceptTest via the input-buffer submit idiom (NewRuleCommandTest shape). Positive: '/agents new reviewer for migrations' through a real Chat starts the turn and the ONLY user prompt sent is `NewAgentPrompt::prompt(focus, existingPresets)` recomputed (assertSame exact — pins routing AND focus/name folding; a SubmitPrompt-effect fall-through cannot fake it because applyCommandResult has no SubmitPrompt arm). Negative: plain 'new' reaches the backend as typed. File now 21T/108A (+2T/+4A).
- Mutation: intercept condition neutered (if(false)) → EXACTLY 1 red, the new positive pin (assertTrue(inFlight) :129); existing 19T + negative stay green (the vacuity the reviewer measured — host arm absorbs). Chat.php cp+md5-restored (49b20ee6), git diff clean; restore re-verified post-commit.
- NIT-2 applied: TOCTOU disclosure comment above the rename in accept() (consent printed from bytes read earlier; concurrent draft rewrite window disclosed, not cured — audit trade stated). NIT-3 applied: PROMPT grammar sentence now names the real DRAFT_NAME alphabet (lowercase letters, digits, dots, underscores, hyphens; starts alnum) instead of 'a-z 0-9 and hyphens'; verified no test/doc pinned the old clause. No Chat.php edit taken (docblock one-liner not needed — the new test docblock carries the fall-through explanation).
- NIT-4 WITHDRAWN: the spawn-note claims ('ONE uncommitted docblock edit in ReadPathCensusTest', 'AgentMemoryRoster flip wording with no counterpart') exist only in the round-1 spawn prompt — grepped: no such claim in this REPORT.md and the worktree tree was clean at b1c24fa69 when this round opened. Nothing to fix; recorded as phantom.
- Gates (plain pipe, cwd candy-code, env -u LINES -u COLUMNS): AgentsNewAccept 21T/108A GREEN; ReadPathCensus 19T/661A GREEN; reconstructed combo (Catalogue|ForeignAgentPresetWiring|PaletteSupersetDispatch|AgentsNewAccept|ReadPathCensus) 82T/1408A/3F — identical standing identities (ChatHostLangCatalogue, CliLangCatalogue sort, FAPW:472) to 80T/1404A/3F measured pre-change at tip, pure +2T/+4A lane delta; gen-command-docs --check rc0; adjacency (NewAgentPrompt|DocFigureProseDrift|DuplicatedTestHelperDrift|StderrEmitterCensus) 202T/26048A/1F — the 1F is the ALREADY-ROSTERED foreign 'dispatch arms 59-vs-60' DocFigure arm, proven pre-existing by running it at pristine tip (my 3 files checked out, failure unchanged, restored md5-exact).
- 1d8e9c906: author+committer detain (separate greps ==1), zero Co-Authored, NEVER pushed; suite-figure/durations untouched (welder carries unchanged: pick range now b1c24fa69..1d8e9c906 or squash-at-weld call, durations.tsv row still owed).
