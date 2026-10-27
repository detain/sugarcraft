# Review — uncommitted `candy-code/COMPARISON.md` (2026-10-26 refresh)

**Summary: MAJOR 2 · MINOR 9 · NIT 6 (all six brief checks complete).**

Scope: `git diff candy-code/COMPARISON.md` against HEAD `5e9195687`. Read-only review; the doc was not edited.
Code paths are relative to `candy-code/` unless prefixed `web:` (= `candy-code-web/src-web/`).
Line numbers `COMPARISON.md:Lnnn` are the working copy.

Counts: **MAJOR 2 · MINOR 9 · NIT 6**

---

## Findings

### MAJOR

**M1. PER-N3 (cc + web) and §6.5 Permissions say saved grants cannot be revoked over the wire; `/permissions revoke` does exactly that, in the TUI and on serve**
- Location: `COMPARISON.md:L432` (PER-N3 cc and web cells), `COMPARISON.md:L4014-4015` (bullets), `COMPARISON.md:L944` (§6.5 Permissions row, both the gap and the suggested approach).
- What is wrong:
  - `/permissions` lists the session's "always" grants, numbered.
  - `/permissions revoke <n>|all` takes them back.
  - `permissions` has a host body, so `command.exec` runs it on serve, and the session host applies the effect and persists it.
  - What is genuinely missing: editing a grant; a revoke control in the web Rules panel; any in-app edit of config-saved (`p`) rules.
- Evidence:
  - `src/Host/Commands/PermissionsCommand.php:29-33` (docblock: lists grants, `revoke <n>|all`), `:42-43`, `:152-171` (revoke implementation).
  - `builtin-commands/1200-permissions.php:19-20`.
  - `src/Host/SessionHost.php:1339-1341` (`SetPermissionGrants` applied and saved on served sessions).
  - `permissions` appears among the 41 server-run built-ins (`CommandMethods::runsIn` → `BuiltInCommands::forSpelling()->hostCommand`, `src/Protocol/Methods/CommandMethods.php:119-122`).
- Fix (glyphs stay 🟡; cell and bullet move together):
  - **PER-N3 cc cell:** `🟡 \`/permissions\` lists the session's "always" grants and \`/permissions revoke <n>|all\` drops them (TUI and serve); no edit`
  - **PER-N3 cc bullet:** `🟡 — \`/permissions\` lists the session's "always" grants, numbered, and \`/permissions revoke <n>|all\` removes them; the command has a host body, so a served session applies and saves the revocation too. Grants cannot be edited, and rules saved to config with \`p\` change only by editing the file. — _Evidence:_ \`src/Host/Commands/PermissionsCommand.php:29-33,152-171\`; \`src/Host/SessionHost.php:1339-1341\``
  - **PER-N3 web cell:** `🟡 Rules panel shows rules and session grants read-only; typed \`/permissions revoke <n>|all\` runs on the host; no revoke button`
  - **PER-N3 web bullet:** `🟡 — The Rules panel renders the effective rules and session grants with no edit or revoke control; a typed \`/permissions revoke\` runs server-side. — _Evidence:_ \`web:components/panels/RulesPanel.vue\`; \`src/Host/SessionHost.php:1339-1341\``
  - **§6.5 Permissions, gap column:**
    - Replace `saved grants cannot be edited or revoked over the wire`
    - with `session grants are revoked only by typing \`/permissions revoke\` (no edit, no Rules-panel control) and config-saved rules only by editing the file`.
  - **§6.5 Permissions, approach column:**
    - Replace `grant revocation over \`permission.rules\``
    - with `revoke and edit controls in the Rules panel over the existing \`/permissions revoke\` effect`.

**M2. §4.2 claims the memory slot refreshes after `/memory add`; the same table, MEM-05 and the code say it is frozen per session**
- Location: `COMPARISON.md:L594`. It contradicts:
  - `COMPARISON.md:L585`: "`<project-memory>` **index** (… frozen per session)".
  - MEM-05 cc (`COMPARISON.md:L183`): "frozen per session".
- What is wrong:
  - The project-memory block is primed in the parent process and memoised per session.
  - The only memo refresh point is a rewritten history (`/clear`, compaction, rewind).
  - `/memory add` rewrites no history and calls no memo `forget`. A note added mid-session therefore joins the `<project-memory>` index only at the next refresh point.
- Evidence:
  - `src/Runtime.php:5852-5869` (`memorySnapshot()` → `sessionPromptMemo()->remember(...)`).
  - `src/Runtime.php:5773-5784` (`primeSessionPrompt`: "project memory … read ONCE, in the process that outlives the turn").
  - `src/Backend/EngineBackend.php:2856`.
  - `src/Context/SessionPromptMemo.php:167-172` ("THE ONE refresh point is a REWRITTEN HISTORY").
  - No caller of `SessionPromptMemo::forget()` exists outside `observeTurn()` (`:204`), and `src/Host/Commands/MemoryCommand.php:56-90` touches no memo.
- Fix: replace L594 with:
  `- User and model notes in the repo store carry their own author labels. A note added mid-session joins the \`<project-memory>\` index at the next refresh point (\`/clear\`, compaction, rewind) or in the next session.`

### MINOR

**m1. The Δ counts are stale by two (§2 note and §5.1)**
- Location: `COMPARISON.md:L103`, `COMPARISON.md:L762`.
- What is wrong:
  - The doc says 242 rows carry Δ and 72 of them differ in status code.
  - Counting the live §3 gives **244 rows with Δ, 74 with a different status code** (all 409 rows).
  - The two extra rows are MCP-N3 and PER-N3, whose oc2 cells gained Δ after the counts were written.
  - (Taxonomy rows alone give 230 and 63, so neither scoping matches the doc's figures.)
- Evidence: parse every `^| <ID> |` row, then for each cell where `oc2.split()[1]=='Δ'`, compare its glyph with the oc1 glyph.
- Fix:
  - L103: `_OpenCode 2.1.4 against 1.19.4: 244 rows carry Δ in the 2.1.4 column, and 74 of them have a different status code (see §5.1)._`
  - L762: `**244 rows carry Δ and 74 of them have a different status code from 1.19.4**`
  - If NIT n3 is applied (adding Δ to UI-N7 and OBS-N2), use 246 and 76 instead.

**m2. §1 says there are no IDE surfaces, but the cells give candy-code ACP editor support**
- Location: `COMPARISON.md:L49` ("no IDE, desktop, mobile or chat surfaces").
- What is wrong:
  - PLAT-06 cc lists an "ACP editor agent" (L122).
  - WEB-08 cc is ✅ (Zed/Neovim, L496) and WEB-07 cc is 🟡 (JetBrains via ACP, L495).
  - §1's own Surfaces row (L67) and §4.12 (L735) list ACP for Zed/JetBrains/Neovim.
- Evidence: `src/Cli/Acp.php:143-148` (ACP host built on `Bootstrap::workspace()`).
- Fix: replace `no IDE, desktop, mobile or chat surfaces` with `no VS Code extension, native JetBrains plugin, desktop, mobile or chat surfaces (editors connect only through ACP)`.

**m3. §1 and §6.5 say the model reads PDFs "only via `pdftotext`", against TL-20, MOD-22 and MOD-01 cc**
- Location: `COMPARISON.md:L63` (§1 Tools & safety, Trails column) and `COMPARISON.md:L938` (§6.5 Shell & tools).
- What is wrong:
  - `@`-mentioned PDFs reach the model as native document blocks on Anthropic and Vertex-Claude. TL-20 cc L376, MOD-22 cc ✅ L149 and MOD-01 cc L128 all say so.
  - Only the Read tool answers a PDF with a `pdftotext` hint.
  - L938 also says tool-result images are seen "only on Anthropic wires", but TL-19 cc (L375) says "Anthropic and Vertex-Claude".
- Evidence:
  - `src/Providers/AnthropicProvider.php:230-233` and `src/Providers/VertexProvider.php:411-412` (`supportsDocuments()` true for Claude models).
  - Every other provider returns false: Gemini `:259`, Bedrock `:445`, OpenAI `:253`, Sglang `:1031`, Custom `:285`.
  - `src/Attachments/FileMentions.php:262` (`%PDF-` sniff).
  - `src/Tools/BuiltIn/Read.php:279-282` (Read answers a PDF with a notice plus `pdftotext`).
- Fix:
  - L63: replace `the model reads PDFs only via \`pdftotext\`` with `Read answers PDFs with a \`pdftotext\` hint and \`@\`-mentioned PDFs ride document blocks only on Anthropic and Vertex-Claude`.
  - L938: replace `The model reads PDFs only via \`pdftotext\` and sees tool-result images only on Anthropic wires` with `PDFs and tool-result images reach the model natively only on Anthropic and Vertex-Claude wires (elsewhere a \`pdftotext\` hint or a caption)`.

**m4. TL-22 web implies a served `/loop` works; AUT-08 web, §1 and §6.1 say it never fires**
- Location: `COMPARISON.md:L378` (TL-22 web cell: "`/loop` and `/schedules` run server-side via command.exec") and `COMPARISON.md:L3496` (bullet: "schedules fire in serve's background tick").
- Contradicts:
  - AUT-08 web ❌ (L517).
  - `COMPARISON.md:L56` ("a `/loop` typed in the browser registers but never fires").
  - `COMPARISON.md:L899` (§6.1 item 3).
- Evidence:
  - `src/Host/Commands/LoopCommand.php:70` (`persisted: false`).
  - `src/Background/ScheduleRegistry.php:396` (`continue; // the Chat pump owns the ephemeral half`).
- Fix (glyph stays 🟡):
  - **Cell:** `🟡 \`/schedules\` runs server-side and serve fires persisted rows; a served \`/loop\` registers but never fires; no model tool or panel`
  - **Bullet:** `🟡 — Both specs carry host commands, so the web runs them as typed commands. Serve's background tick fires persisted \`schedules.json\` rows; a \`/loop\` row is ephemeral and only the TUI pumps those, so a served \`/loop\` never fires. — _Evidence:_ \`candy-code/builtin-commands/2910-schedules.php\`; \`src/Host/Commands/LoopCommand.php:70\`; \`src/Background/ScheduleRegistry.php:396\``

**m5. AGT-03 cc lists the wrong tool grants; the bullet, §4.4 and the code disagree with it**
- Location: `COMPARISON.md:L256`. The cell says "`coder` Write/Grep/Glob, `tester` Write/Edit".
- Contradicts:
  - The bullet `COMPARISON.md:L2317`, which lists the full grants.
  - §4.4 `COMPARISON.md:L614` ("coder and tester carry Write/Edit/Grep/Glob").
- Evidence: `src/Agents/AgentDefinition.php:55` (coder: Read, Edit, Write, Grep, Glob, ApplyPatch, Bash, Skill) and `:197` (tester: Read, Write, Edit, Grep, Glob, Bash, Skill).
- Fix (cell only; the bullet is already correct): `✅ Built-in presets get real file tools: \`coder\` and \`tester\` carry Read/Edit/Write/Grep/Glob/Bash (\`coder\` also ApplyPatch)`

**m6. §5.2 says "Eight releases", but the range it covers holds ten**
- Location: `COMPARISON.md:L856`.
- What is wrong:
  - The section is titled 2.1.302 → 2.1.311 and itself cites changes from 302, 303, 304, 305, 307, 308, 309, 310 and 311.
  - The research also records 2.1.306 (two fixes). That makes ten published versions, not eight.
- Evidence: `prompt_kit/findings/comparison-1026/comp-claude-code.md:8` (lists 302–311 with 303 and 308 fix-only, and 306 with two fixes) and `:103` (the 2.1.306 entry).
- Fix: `Ten releases (2.1.302–2.1.311; 2.1.303 and 2.1.308 are fixes only, 2.1.306 has two fixes) of hardening and consistency work, …`

**m7. §6 misses an open served-session gap: EDT-N2 web ❌ (the AI-comment watcher runs only in the TUI)**
- Location: §6.1 (`COMPARISON.md:L895-905`) and §6.7 item 4 (`COMPARISON.md:L957`). Neither lists EDT-N2, although the cell (L400) and bullet (L3709) say `watchFiles` is never read on serve.
- Evidence: `src/Chat.php:18283` is the only runtime reader of `AiCommentWatcher::enabled()`. Outside `src/Support/AiCommentWatcher.php`, only `src/Config/Settings/Definitions/InterfaceSettings.php:102-108` references it, and nothing in `src/Host` or `src/Server` does.
- Fix: in §6.7 item 4, append `; \`watchFiles\` AI-comment prompts on served sessions (EDT-N2)` before the final period.

**m8. TL-04 web says the ask card previews every hunk, but ApplyPatch asks stay raw JSON (as EDT-03 web says)**
- Location: `COMPARISON.md:L360` (TL-04 web: "the ask card previews every hunk").
- What is wrong: this conflicts with EDT-03 web (`COMPARISON.md:L391`: "ApplyPatch asks stay raw JSON") and with §6.7 item 6 (L959). The bullet (L3316) already says the card falls back to raw JSON.
- Evidence: `web:lib/lineDiff.ts:36-61` (`diffPairsFor` handles Edit and Write only, so ApplyPatch → `null` → raw dump) and `web:components/PermissionCard.vue:37`.
- Fix (glyph stays ✅): `✅ Edit \`edits\` and ApplyPatch run server-side with +N −M diff cards; Edit asks preview every hunk (ApplyPatch asks stay raw JSON)`

**m9. GIT-02 web says "Inherited" while §6.1 says `autoCommit: turn` runs only from the TUI; its bullet is empty**
- Location:
  - `COMPARISON.md:L435`: the GIT-02 web cell is just "🟡 Inherited".
  - `COMPARISON.md:L4038`: the bullet is `🟡 — —`.
  - `COMPARISON.md:L898`: §6.1 item 2 names `autoCommit: turn` but cites only MEM-02, MEM-08 and UI-34.
- Evidence:
  - `src/Chat/Handlers/HandlesTurnCompletion.php:320-324` (turn-mode commits are scheduled from Chat's turn completion only).
  - `src/Cli/Bootstrap.php:5874-5883` (edit mode runs through `AutoCommitHook` inside the shared `hooks()` chain).
- Fix:
  - **Cell:** `🟡 \`autoCommit: edit\` commits on served turns (AutoCommitHook); \`turn\` mode commits only from the TUI's turn completion`
  - **Bullet:** `🟡 — Edit-mode commits ride the shared hook chain, so served Write/Edit calls commit; turn-mode commits are scheduled only from the TUI's turn completion. — _Evidence:_ \`src/Cli/Bootstrap.php:5874-5883\`; \`src/Chat/Handlers/HandlesTurnCompletion.php:320-324\``
  - **§6.1 item 2:** change `(MEM-02, MEM-08, UI-34)` to `(MEM-02, MEM-08, UI-34, GIT-02)`.

### NIT

**n1. The WEB-08 cc bullet carries a "not run" caveat**
- Location: `COMPARISON.md:L4633` ("… ids echoed exactly. Documented, not run here.").
- Fix: delete ` Documented, not run here.`

**n2. §4.4 attributes broadcast to the Ctrl+X leader; broadcast is Ctrl+G**
- Location: `COMPARISON.md:L619` ("pause / cancel / background / broadcast (Ctrl+X)").
- Evidence:
  - `src/App/App.php:467-472` (`Ctrl+G` broadcast) and `:474-475` (Ctrl+X chords `c p s o b`).
  - AGT-21 cc bullet `COMPARISON.md:L2497` ("Ctrl+G broadcasts to the batch").
- Fix: `pause / cancel / background (Ctrl+X chords) and broadcast (Ctrl+G)`.

**n3. UI-N7 and OBS-N2 oc2 cells differ from oc1 but carry no Δ**
- Location: `COMPARISON.md:L487` and `COMPARISON.md:L539`. The oc2 cell is ✅ while the oc1 cell is `· Not assessed`.
- What is wrong: the legend (L26) defines Δ as "Differs from the OpenCode 1.19.4 cell". Either rule is defensible, since oc1 was not assessed, but MCP-N3 and PER-N3 (also 🆕, oc2-only research) do carry Δ, so the column is inconsistent.
- Fix, either option:
  - add `Δ` to both cells (`✅ Δ …`) and use 246/76 in m1;
  - or leave them and state in the §3 Coverage note that Δ is omitted where the 1.19.4 cell is `· Not assessed`.

**n4. OBS-N1 web says spend is all it has, but typed `/usage` runs on the host**
- Location: `COMPARISON.md:L538` (OBS-N1 web: "Status bar shows session spend only").
- What is wrong: SES-15 web (L249) and OBS-03 web (L534) say typed `/usage` prints the ledger rollups server-side.
- Evidence: `usage` is among the 41 host-run built-ins; `src/Host/Commands/UsageHostCommand.php:73-74`.
- Fix (glyph stays ❌): `❌ Status bar shows session spend; typed \`/usage\` prints day × model rollups; no per-skill/agent/MCP attribution or advice`

**n5. §6.1 item 8 overstates the mid-turn refusal**
- Location: `COMPARISON.md:L904` ("refuses every built-in while a turn runs"); CTX-11 web (L227) says the same.
- What is wrong: workflow-control commands are exempt.
- Evidence: `src/Host/SessionHost.php:792` (`if ($this->isBusy() && !$this->isWorkflowControl($text))`).
- Fix: "refuses every built-in except workflow controls while a turn runs".

**n6. Four of the 11 new 🆕 gaps, and two older ones, are absent from §6**
- §6 covers MCP-N2, PLG-N3, OBS-N1, SKL-N2, SH-N2, PER-N3 and OBS-N2 among the new rows.
- It omits:
  - WEB-N6 (deep links, cc ❌);
  - SKL-N1 (self-learning skills, cc 🟡);
  - MCP-N3 (client-hosted tools, cc ❌; §5.1 L831 even names it as a v2 lead);
  - UI-N7 (experiments toggles, cc ❌);
  - AGT-01/AGT-02 (no primary build/plan agent switch: cc 🟡, web ❌).
- Fix, optional:
  - append `; no client-hosted tools (MCP-N3)` to the §6.5 MCP gap;
  - append `; no deep-link handler (WEB-N6)` to §6.5 Distribution & surfaces;
  - add `no primary-agent switch (AGT-02)` to §6.4 Agents;
  - add `skills the agent saves and versions itself (SKL-N1)` to §6.3 Skills.

---

## Verified OK

### Prose against cells (§1, §2 notes, §3 intro, §4, §5, §6, Appendix B)
Everything not listed above agrees with the cells and bullets.

**§1**
- The 69-method and 8-uncalled lists (L53).
- 16 `ui_only` built-ins and 41 server-run, with the exact names (L54).
- Context-panel contents, the TUI-only `@mcp:`/MCP prompts/`/mcp login`/`/skill-name`, the undrained notice sink, no served auto-memory or dream pass, and `/btw` refused mid-turn (L55).
- PER-12 web and AUT-08 web (L56).
- The lead/parity/trail table against PRM-09/10, INS-06, MEM-13, MOD-N3, CTX-N1, AGT-07/20, HK-02, SKL-11/15, MCP-06/07, PLG-02, TL-12/15/21, EDT-04/07, SH-08, MOD-02/05/06/09/25, AUT-11, SES-09/10, UI-07/09/10/23/25/30/33, WEB-N3 and PLAT-03.

**§2 and §3**
- §2 footnote: 53 🆕 rows, and every column sums to 356.
- §3 intro: 409 rows (356 + 53).
- Cells over 130 characters are all ≤132 including the leading glyph, so the 130-character summary rule holds.

**§4**
- §4.1: every candy-code and web row matches INS-03/07/08/14/15/16/17/18/19/20.
- §4.2: matches MEM-02/05/06/08/09/10/14, apart from M2.
- §4.3: matches SKL-02/03/06/07/09/11/12/15.
- §4.4: matches AGT-03/06/09/13/15/19/20/21, apart from n2.
- §4.5: 64 specs = 57 slash + 7 hidden, 6 aliases, palette 42 + 7, 9 control-plane names.
- §4.6: 12 events and the `CRUSH_*` env payload.
- §4.7–§4.12: all consistent.

**§5 against the oc1, oc2, claude and claw columns**
- §5.2 "one status code moved, CMD-12" and §5.3 "no OpenClaw status code moved": verified by diffing glyphs against HEAD.
  - The only claude glyph move is CMD-12 🟡→✅; claw has none.
  - oc1 has only SES-09; the 14 oc2 moves are those §5.1 describes.
- §5.2/§5.3 🆕 attribution: four Claude rows (MCP-N2, OBS-N1, WEB-N6, PLG-N3), three OpenClaw rows (SKL-N1, SKL-N2, SH-N2) and four OpenCode rows (MCP-N3, PER-N3, OBS-N2, UI-N7), 11 in all.
- Every §5.1 gain, regression and catch-up/ahead/behind bullet matches the oc2 cell it names, including:
  - WEB-N4 ❌→✅, WEB-N3 🟡→✅, CTX-N1 🟡, MOD-13, MOD-22;
  - CTX-09/MEM-08/AUT-09 🔌;
  - INS-01 ❌, TL-16/EDT-06/07 ❌, MOD-24 ❌, CMD-06 ❌, SKL-06 🟡, CTX-06 ❌, MCP-02 ❌, AGT-07 ❌, GIT-04 🟡;
  - TL-N2 ❌, PER-05 (own rules), PER-N3 🟡, MCP-08 🟡, MCP-N3 🟡, TL-N1 🟡, TL-11/UI-20 🟡.
- §5.3 claw cells (SKL-14, SKL-04/05, PRM-06, AGT-12, UI-19, INS-01 🟡, HK-07 ❌, TL-N2 🟡, CTX-05, CTX-N4, SES-09, WEB-09, TL-05, GIT-08, AGT-18, PLAT-05, TL-N3, MEM-15, MOD-03) agree with the narrative.

**§6 is open-only**
- Every listed item was re-checked as still open; none is shipped apart from the revocation half of PER-N3 (M1).
- Dormant-code list:
  - `App::dispatchSkill` has no caller (`src/App/App.php:2528,2555`).
  - The skill asset loader has no caller (`src/Skills/SkillLoader.php:1054`).
  - `McpClient::setAgentPreset` has callers only in tests (`src/MCP/McpClient.php:178`).
  - The TUI never paints `ToolProgress` (`src/Chat.php:3702-3710,4230-4239`).
  - Serve arms the notice sink (`src/Cli/Serve.php:592` → `Bootstrap.php:1678-1679`), and the only drain is `src/Chat.php:18636`.
  - `canGenerateVideo` is computed in `web:stores/connection.ts:91` and read by no component.
- §6.1:
  - 1: notice sink, as above.
  - 2: turn-end work. Prompt suggestions (`suggestionCall`) run only from `src/Chat.php:12662`; turn-mode autoCommit only from `HandlesTurnCompletion.php:320-324`.
  - 3: ephemeral `/loop` (`ScheduleRegistry.php:396`).
  - 4: Claude settings refuse the launch (`src/Hooks/ClaudeSettingsHooks.php:46-51,98`).
  - 6: `command.list` loads command files without trust (`CommandMethods.php:136` → `CommandLoader.php:605` default `false`).
  - 7: `media.fetch` reads only the session store (`MediaMethods.php:302`).
  - 8: `/btw` mid-turn (`SessionHost.php:792-793`).
  - 9: OTLP is mounted only in `Bootstrap::chat()` (`Bootstrap.php:1257`).

**Voice**
- No commit SHA, wave or lane tag, "fixed since", "remediated" or "now that" in any prose, cell or candy-code/web bullet.
- The old dated re-score notes and the "fourth front" history paragraph are gone.
- The only caveat left is n1.
- Appendix B dates are method statements, which is acceptable.

**Appendix B** matches the header table, the 53/42/11 🆕 split and the report filenames.

### Changed cells checked against live code (candy-code and candy-code-web)

| Row · col | Glyph | Evidence |
|---|---|---|
| MOD-03 cc | 🟡→✅ | `src/Providers/GeminiProvider.php:29,42`; `ProviderFactory.php:483,766` |
| MOD-10 cc | ✅ (9 types) | `src/Providers/ProviderFactory.php:483` |
| MOD-11 web | ❌→✅ | `web:components/StatusBar.vue:46-55`; `src/Host/Commands/ModelHostCommand.php` |
| MOD-12 web | 🟡→✅ | `web:components/agents/AgentView.vue:95` (`run.model`) |
| MOD-14 web / MEM-10 web | ❓→🟡 / 🟡 | `src/Cli/Serve.php:592`; `Bootstrap.php:1678-1679`; sole drain `src/Chat.php:18636` |
| MOD-22 web | ❌→🟡 | `src/Attachments/FileMentions.php:262` |
| TL-19 cc | ✅→🟡 | `src/Tools/BuiltIn/Read.php:433-445` (Anthropic-family wire only) |
| TL-20 cc / web | ✅→🟡 / ❓→🟡 | `supportsDocuments()`: `AnthropicProvider.php:230`, `VertexProvider.php:411`; the others false |
| INS-01/02/04/05/15 web | 🟡→✅ | `web:components/panels/ContextPanel.vue:50,104-112`; `web:stores/contextSources.ts:49` |
| INS-17 web | ❌→✅ | `src/Host/Commands/InitHostCommand.php`; `init` is in the server-run set |
| INS-20 cc | ❌→✅ | `src/Cli/Bootstrap.php:9000`; `MemoryRuleSettings.php:132` |
| MEM-09 cc | 🟡→✅ | `src/Chat.php:15641`; `MemoryCommand.php:75` |
| MEM-10 cc | 🟡→✅ | `lang/en.php:1898` (`Recalled {count} {notes}`) |
| SES-06/08/17 web | 🟡/🧪/🧪→✅/✅/🟡 | `web:stores/sessions.ts:91,97,115`; `web:components/SessionSidebar.vue:89,187-191` |
| SKL-06 cc | 🟡→✅ | `src/Chat.php:9586,11296-11318` |
| SKL-06 web | ❌→🟡 | `src/Host/TurnController.php:1055` (`SkillMentions` on serve); the slash rewrite is Chat-only |
| SKL-08 web | 🟡→✅ | 8 `SKILL.md` files under `src/Skills/BuiltIn/` |
| SKL-09 cc | ❌→🟡 | `src/Cli/Subcommands.php:1470,2586` |
| SKL-11 web | 🟡→❌ | `src/Skills/SkillFrontmatter.php:50` |
| SKL-15 web | 🟡→🧪 | `src/App/App.php:2528,2555` |
| HK-07 cc | ✅→🟡 | `src/Hooks/ClaudeSettingsHooks.php:46-51,98` (`ENTRY_KEYS`) |
| HK-02 cc | 12 events | `src/Hooks/HookEvent.php` (12 cases) |
| CMD-01 web | 41/16 | live `CommandRegistry`: 64 specs, 57 slash, 41 host, 16 client, 7 hidden |
| CMD-02 web | trusted overrides TUI-only | `src/Protocol/Methods/CommandMethods.php:136`; `CommandLoader.php:605` |
| TL-08 web | ❌→✅ | `src/Tools/BuiltIn/Ls.php:61` |
| SH-08 cc / web | 🟡 / ❌→✅ | `src/Chat.php:4230-4239`; `web:stores/reducer.ts:509`; `web:components/ToolCard.vue:69` |
| PER-03 web | 🧪→🟡 | `web:components/panels/RulesPanel.vue:8,42` |
| PER-11 web | ❌→🟡 | `web:components/panels/ContextPanel.vue:174`; `web:stores/contextSources.ts:74` |
| GIT-01/09 web | ❌/🧪→🟡 | `web:components/panels/ChangesPanel.vue:10-37` |
| UI-09 cc | 🟡→✅ | `src/Commands/KeyBindingRegistry.php:935-949`; `src/Renderer.php:6413` |
| UI-09 web | ❌→🟡 | `web:stores/promptHistory.ts:12` (cap 50); `web:components/Composer.vue:147-172` |
| UI-10 cc / web | 🟡→✅ | `src/Attachments/MentionIndex.php:8,29`; `src/Protocol/Methods/FsMethods.php:7,41` |
| UI-12 web | ❌→🟡 | `web:components/Composer.vue:115` (paste only, no drop handler) |
| UI-16 web | ✅→🟡 | no `navigator.clipboard` or copy control in `web:components`/`views` |
| UI-23 cc | ✅→🟡 | `src/Config/StatusLineCommand.php:623` (stdin closed, no JSON) |
| UI-33 cc | ❌→✅ | `src/Support/StartupSummary.php:99-104` (four rows) |
| UI-N3 / TL-28 web | 🧪→🟡 / 🟡→✅ | `web:components/panels/GeneratePanel.vue:49,75-85` (txt2img only) |
| WEB-N3 cc | 🟡→✅ | `src/Host/RemoteSessionHost.php:60,86-95` |
| CTX-04 web | ❌→✅ | `web:components/StatusBar.vue:95-99` |
| CTX-11 web | 🟡 | `src/Host/SessionHost.php:792-793` |
| OBS-02 cc | 🟡 | `src/Cli/Bootstrap.php:1257` (only in `chat()`) |
| CFG-06 / OBS-04 / PRM-07 web | →🟡 / →✅ / →✅ | `web:components/AboutModal.vue:8-18,90-101` |
| AUT-08 web | ❌ | `src/Host/Commands/LoopCommand.php:70`; `src/Background/ScheduleRegistry.php:396` |
| WEB-02 cc | 69 methods, 35 events | `docs/SERVER.md` generated tables (69 and 35 rows); the web leaves 7 + `server.shutdown` unrequested |

### The 11 🆕 rows (candy-code and candy-code-web ratings)

- **MCP-N2 ❌/❌:** no `tasks/get`, `tasks/result` or elicitation in `sugar-mcp-client/src` or `candy-code/src`. OK.
- **MCP-N3 ❌/❌:** none of the 69 methods lets a client register a tool. OK.
- **PLG-N3 ❌/❌:** no recommendation code. OK.
- **OBS-N1 ❌/❌:** `UsageHostCommand.php:73-74` groups by day and model only. OK (wording NIT n4).
- **OBS-N2 ❌/❌:** no heap or diagnostics capture. OK.
- **UI-N7 ❌/❌:** no experimental settings definitions. OK.
- **SKL-N1 🟡/🟡:** consistent with SKL-14 (dream drafts plus `/skills accept`/`reject`, server-run). OK.
- **SKL-N2 ❌/❌:** `src/Skills/SkillMatcher.php:24-31` ("N more skills" line, no search). OK.
- **SH-N2 🟡/❌:** model-only `ShellOutput`/`ShellStop`; the Background panel lists `/bg` sessions. OK.
- **WEB-N6 ❌/🟡:** `web:router.ts:10,21` (hash `/s/:id`, no dir or prompt params). OK.
- **PER-N3 🟡/🟡:** glyphs OK; the text is wrong (M1).
