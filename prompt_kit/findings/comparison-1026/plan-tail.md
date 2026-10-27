## 6. Owner rulings

### 6.1 Decided (2026-10-26)

| Ruling | Decision | Steps |
|---|---|---|
| R-MENU-1 | Approve the 5-menu tree as drawn (Session · Agent · Tools · View · App); bare `/generate` hidden from the menu, "Generate image…" is its door. | P-MENU.b, P-MENU.d |
| R-MENU-2 / R-MENU-3 | Keep the `Currently: <pane>` indicator as a door to View ▸ Panes; the Panes submenu stays open after a toggle. | P-MENU.e |
| P-CTX dock | Auto-dock the Context pane once on terminals ≥120 cols after the first reply. | P-CTX.e |
| R13 (remote / GitHub) | All allowed: `gh` for `/pr` and `/review --pr`, plus web PR/CI chips; the issue-comment PR bot (association-gated); serve TLS, QR pairing and Web Push with an in-tree QR encoder and RFC 8291/8292 crypto (no new deps); Admin-scope web log tail and a confirmed `server.shutdown`. | C-AGT-5.h, C-AGT-6.d, C-AGT-6.e, C-WEB-5.* |
| rv-sec-1 reversal | `-p` defaults to Manual/deny like Claude Code; locally spawned `/bg` daemons keep bypass. | C-SEC-7.a |
| autoReview | On by default whenever a review backend exists (each review is a paid call). | C-SEC-7.e |
| Live share links | Ship anonymous live read-only links (token holders see new messages until revoke or expiry). | C-SES-3.d |
| Serve telemetry | `serve` exports OTel spans when `OTEL_EXPORTER_OTLP_ENDPOINT` is set. | C-SES-4.d |
| R-EXT-1 | Honour a skill's `allowed-tools` **as written, for all tiers**: listed tools are pre-approved while the skill runs (Claude Code behaviour). Project and bundle skills load only under project trust, as today. candy-code's own 8 built-in skills are **trimmed** to the tools and effort each truly needs (no Bash pre-approval unless the skill runs shell). | C-EXT-2.b |
| R-EXT-2 | Model-written skills go live immediately with an announce-and-undo notice. | C-EXT-3.e |
| Third-party `!cmd` | Bundle and MCP-prompt shell forms are opt-in per origin (two user-tier list keys). User-tier `~/.claude` and `~/.opencode` commands may run `!cmd` as the tier gate allows today; correct `docs/COMMANDS.md:28` to match. | C-SEC-3.b |
| MCP | Background stdio calls allowed (later calls to that server queue behind the lock); `mcp import --write user` allowed (user tier, collisions refused); `mcp serve` is stdio-only with a read-only roster, Ask → refuse, no session/prompt exposure. | C-MCP-3.d, C-MCP-4.d, C-MCP-5.a |
| `-p --append-system-prompt` | Allowed, fenced, never an override; update PROMPT_ENGINEERING.md. | C-CTX-4.g |
| LSP downloads | `candy-code lsp install <lang>` may download pinned, checksummed servers from an allowlist (npm with `--ignore-scripts`). | C-TOOL-5.c, C-TOOL-5.d |
| Message picker | Idle Esc Esc opens it, as in Claude Code. | C-TUI-3.d |
| Homebrew tap | **Declined**: the formula ships as a release asset only, and the push leg stays off. | C-SES-6.d |
| R4 vim VisualLine | **Declined**: C-TUI-1.b is dropped; `V` keeps aliasing `v`. | — |

### 6.2 Take the design's default (no owner call needed unless the owner objects)

- C-PRV/C-CTX defaults as written in `design-prv-ctx.md`:
  - the idle-fold default;
  - the keep-warm default;
  - the model-catalog merge order;
  - CLI-provider isolation on by default for the Claude Code and Codex CLI routes.
- C-WEB-5 implementation sources: an in-tree QR encoder and in-tree Web Push crypto (no new dependency). The push payload defaults to redacted generic text.

### 6.3 Still open (LATE items; ask before their wave starts)

C-TOOL-6 has three calls, each with a proposed default in `design-tool.md`:
- the Script tool's language and sandbox floor (C-TOOL-6.c);
- whether user-tier script tools are allowed (C-TOOL-6.e);
- whether client-hosted tools are allowed (C-TOOL-6.h).

C-TUI-6 has six calls, each with a proposed default in `design-tui.md`:
- the Windows shell;
- the Windows self-update helper;
- web i18n scope;
- which locale to ship and how strict translation parity is;
- the voice endpoint;
- the push-to-talk key.

## 7. Closeout gate (one gate agent, after the last wave)

The gate is a LOOP (§1.6): run steps 1–4, then hand every red or drift to a fix agent, then re-run from step 1. Repeat until a full pass is green with no drift, then do steps 5–7.

1. **Full battery, plain-pipe synchronous.**
   - Run under `env -u LINES -u COLUMNS`; never tmux, setsid or tee.
   - The candy-code serial must be green.
   - Run vitest with `--no-file-parallelism` after `(cd candy-code-web && npm ci)`.
   - Spot-check e2e on the web flows that changed the protocol.
2. **Figure re-pin trio:**
   - `candy-code/tests/Config/Support/suite-figure.json`, via `php tests/Config/Support/refresh-suite-figure.php <junit>` (cwd `candy-code`), after a green log;
   - the `candy-code/README.md` headline figure;
   - the root `scripts/parallel-tests-durations.tsv`, with a row for every new `*Test.php`.

   Then check that the shard sum equals the figure.
3. **Regenerate, failing loud on drift:**
   - `php candy-code/tools/gen-command-docs.php --check`, `php candy-code/tools/gen-tool-docs.php --check` and `php candy-code/tools/gen-settings-doc.php --check` (each defaults to `--help` and exits 0, so always pass the flag);
   - `php candy-code/scripts/gen-protocol-schema.php --check`, then `(cd candy-code-web && npm run gen:protocol)`, after which `generated.ts` must be byte-clean;
   - `(cd candy-code-web && npm run build)`, after which `candy-code-web/dist/` must be clean;
   - `php tools/check-path-repos.php --no-lib-path-repos`, `php tools/check-child-lifetimes.php` and `php tools/check-one-type-per-file.php`.
4. **Drift-guard sweep:**
   - ReadmeRoster, EnvRoster, TrustKey, ConfigWriteProducer, KeyBinding, ToolDoc, ProtocolSchema, LangParity and DocFigureProse;
   - GlobDialectDifferential, with the corpus figure unchanged;
   - the census rosters.
5. **COMPARISON.md refresh:**
   - Re-score every cell a landed step moved, in both the §3 cell and its Appendix A bullet, glyphs equal.
   - Delete the closed gaps from §6. Don't annotate them; the doc stays in current-state voice.
   - Re-run `python3 -I prompt_kit/findings/comparison-1026/apply_comparison.py candy-code/COMPARISON.md --check`.
6. **Review-loop proof:** every step loop and every wave loop ended on a fresh review with 0 critical and 0 major, recorded under `prompt_kit/findings/comparison-1026/lanes/<step>/` and `lanes/W<n>/`. Keep a ledger at `prompt_kit/findings/comparison-1026/CLOSEOUT.md` with step → commit, per-wave counts, and residuals.
7. **Hygiene:** `git worktree list` shows the main checkout only, and no `cc/*` branches remain.

## 8. Not in scope

- **C-WEB-6 SDK, IDE and desktop clients** (owner: not picked): publishing the TS protocol client, a VS Code extension, a desktop shell, `candy-code://` deep links, chat-channel daemons.
- **C-SEC-9 managed tier and multi-user** (owner: not picked): `/etc/candy-code` managed settings, instructions and hooks; per-user serve tokens.
- **`candidates.md` § Overflow:**
  - native subscription OAuth logins (vendor terms);
  - thread-bound sub-agent sessions;
  - device nodes, voice wake, email and calendar (use MCP servers);
  - cloud execution (the Docker serve path is the answer);
  - live heap-dump diagnostics;
  - parity notes that need no work.
