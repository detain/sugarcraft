# REVIEW-1 — C-AGT-4.b (/agent use <preset> + Alt+A primary-agent cycle)

VERDICT: CLEAN — 0 CRITICAL / 0 MAJOR / 0 MINOR (step-local or otherwise). NITs + weld-carry notes below.

Ancestry/hygiene: tip c4fb3580f, parent d3d4b54f4 ancestor of master (re-verified at current master 0a7582f13). %ae=%ce=detain@interserver.net, zero Co-Authored, tree clean. Diff = 17 files +1002/−6 exactly as claimed.

Scope: all brief-declared paths landed except AgentsCommand.php + 0800-agents.php — justified dead-code/no-op derivations (AgentsHostCommand:99 intercepts 'use' before AgentsCommand::execute is ever reached; 0800 hint derives from en.php cmd.agents.hint, updated). Two files beyond the list: src/Chat/Handlers/HandlesKeys.php (8-line Alt+A arm — physically the declared [chat: key dispatch] region) and tests/Commands/KeyBindingRegistryTest.php (in-step 154→155 count flip per hub law). Palette regions untouched (C-AGT-5.b collision law holds). Renderer edits confined to modeBadge neighbourhood.

Trust law: effects carry NAME only, never an Agent object; switch resolves via agentManager->get (launch-trusted roster); presentsAsPrimary re-reads sourcePath frontmatter every cycle — forged/untrusted objects cannot present-then-grant. 'use' reserved before liveAgentRunFor lookup. Untested edge: preset literally named 'use' shadowing a live run has no pin (hard fixture; NIT).

Mid-turn refusal: dedicated use_turn_running IS production-reachable — Alt+A chord arm runs mid-turn through the split inFlight policy; typed path gets the generic refuseInFlightCommand notice above the door, faithful Alt+M precedent. Not a gap.

SessionHost: primaryAgentOverride() record-only, zero src readers — matches brief wording ('stores it … as SetModel does'); the consumer is C-AGT-4.c's declared ServerContext::summary() work. ACCEPT with carry: 4.c MUST wire the reader.

Gates re-run (cwd=candy-code, plain pipe, -u LINES -u COLUMNS): PrimaryAgentSwitch 11T/35A OK; Agents|SessionHostCommands|AgentsNewAccept 65T/272A (builder's 76T/307A = that + switch file, exact); KeyBindingDrift|Registry|CommandDocsDrift 265T/3152A OK; full battery filter 700T/165,968A/6F — failure names byte-identical to standing set re-proven independently at master 0a7582f13 (66T/96,545A/6F, inputs byte-identical d3d4b54f4..master). Zero lane-caused reds.

Vacuity: KeyBindingDrift census law reddens on missing observation (registry row without closure) and its closure is label-driven via chord() (HandlesKeys arm removal → alpha assert red). Narrow legs: wholesale-replace test goes red under double-narrow; revert/hand-over pins each discriminate a named neuter. Non-vacuous (structural; no mutations run per read-only law).

§1.8: guards moved in-commit (registry count, README Keys row, AGENTS.md section, COMMANDS.md regen). §1.9: no glob/denial-shaped fixtures. E646 clean. New lang key tui.status.agent sits outside declared [lang:] prefixes — soft law, note only.

Weld carry: durations.tsv row for candy-code/tests/Chat/PrimaryAgentSwitchTest.php; suite-figure/README pair re-pin (+11T expected).

NITs: (1) 'use'-shadow reservation unpinned (HandlesKeys-adjacent law, comment present); (2) cyclePrimaryAgent ghost-current comment says 'restarts the cycle from default' while behavior skips to first presenting preset (PrimaryAgentSwitch? — wording only, pinned behavior is the sort order).
