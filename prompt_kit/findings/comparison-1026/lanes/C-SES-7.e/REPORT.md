# C-SES-7.e — session.import + archive/unarchive + ephemeral (W20) — DONE

Verdict: COMPLETE on branch cc/C-SES-7.e, two commits (37e76cec7 code+tests, 14bb7487f protocol trio regen + dist), tree clean, NEVER pushed. Both commits author/committer detain@interserver.net, zero Co-Authored/Caliber trailers.

## Inbound-cap resolution (the builder's STOP question) — CONFIRM, no new cap

- TRUE reassembled inbound ceiling: `ServerConfig::MAX_CLIENT_FRAME_BYTES = 16_777_216` (src/Server/ServerConfig.php:57), enforced by vendor ratchet/rfc6455 `MessageBuffer::onData` (:162-164) with a per-frame LOOKAHEAD that includes the already-buffered total (`payload_length + getPayloadLength() > max` → CLOSE_TOO_BIG 1009) — fragmentation cannot bypass it. Wiring: Connection.php:70-77 feeds the value into BOTH the maxMessagePayloadSize and maxFramePayloadSize slots; callers: Server.php:218, Upgrade.php:50, WorkspaceHostProcess.php:176.
- The 64KiB figure the builder half-remembered is per-frame/export headroom (`EXPORT_FRAME_HEADROOM_BYTES = 65_536`) and MAX_API_BODY_BYTES is HTTP-only; MAX_CLIENT_MESSAGE_BYTES (1 MiB) is the per-field string ceiling. C-WEB-4.e's 11MiB docs (15,379,116 b64 chars) ride the 16MiB wire cap — consistent, nothing broken.
- CONFIRM-leg pin shipped: SessionImportMethodTest fragmented `session.import` (> cap total, two continuation frames) over a direct Ws\Connection closes 1009 with the handler's payload list empty — oversized imports refuse at the SAME seam as every other method, method-neutral.
- Import field bound: `SessionMethods::IMPORT_MAX_CONTENT_BYTES = MAX_CLIENT_FRAME_BYTES - EXPORT_FRAME_HEADROOM_BYTES` (16_711_680), const-equality pinned; schema maxLength refuses first (typed -32602), code defense-in-depth `import_too_large`.

## What shipped (16+3 files)

- session.import: runs the C-SES-7.a SessionImporter in-process with `withBounds(null, IMPORT_MAX)`; sourceKey `rpc:sha256(first-line)[:32]` identity law (grown append via ledger, shrunken refused `sourceShrank`, reshaped head = new session); typed import_refused; fresh import answers open:TRUE (hub->open + feedFor), replay/grow close+reopen; announce created vs updated from ledger lookup BEFORE import.
- session.archive / session.unarchive: wrap EnhancedSessionStore::archive()/unarchive() (WHERE-clause idempotent), unknown → -32004 session_not_found, announce session.updated, answers fresh summary; list {archived?} tri-state through hub->list (?bool, tray vs live law); create {ephemeral:true}: no store row, no lease, save()/usage sink/permission grants/settle passes gated off (builder's SessionHost gates verified), hub drops on close; SessionSummary + archived/ephemeral booleans (Definitions.php; base JSON had them, source now agrees).
- Regen: gen-protocol-schema --write (schema.json + SERVER.md rows), --check up to date; web gen-protocol.mjs → generated.ts (session.import/archive/unarchive rows); dist rebuilt via vite and committed same protocol-lane precedent as C-WEB-4.e.
- Web: sessions.ts (showArchived + tray walk load, applyArchived law, ephemeral event skip with positive control, archive/unarchive/create(ephemeral)); newSession.ts pendingIncognito; SessionSidebar.vue (Incognito button, Show-archived checkbox, Archive/Restore/Delete v-if gates, incognito/archived markers). `@click="create()"` on the New button — MouseEvent-as-incognito hazard avoided.

## Gates (actuals)

- Targeted phpunit: SessionHub|SessionArchiveMethods|SessionImportMethod = 36T/456A OK in the drift run; WsUpgrade|InternalErrorLogging|Conformance|PathJailCensus|GlobDialect|DuplicatedTestHelperDrift = 61T/792A OK. 2 PHPUnit deprecations PRE-EXISTING at base (SessionListBodySearchTest alone reproduces).
- Drift pair ProtocolSchemaDrift|ServerProtocolDocumentation green post-regen. PathJail census did NOT need a flip (import takes content over the wire — no new path sink). No new env vars → EnvRoster untouched.
- vitest serial FULL candy-code-web: 78 files / 618 tests, all pass.
- vue-tsc --build: error set IDENTICAL to a clean base-0cd7dc95b worktree compile (20 inherited errors, 6 files untouched); sole diff = pre-existing dormantRoster TS2532 shifted 56→59 by the session.import roster entry (+3 lines). Empty delta proven, not assumed.

## Incident disclosure (important for the welder)

A concurrent second incarnation of this lane's builder was live in the SAME worktree at ~18:1x–18:24 while this fixer session wrote the specs: file edits interleaved (its describes vs mine), it reverted the generated trio to base at 18:24:13 and committed the full code+test tree as 37e76cec7 (author correct) 12s later, then went silent. This session deduped the resulting duplicate test describes (component: kept 4 richer tray/incognito arms + 2 unique gates/marker arms; store: kept 3 earlier row-action tests + 2 unique skip/reload tests — 21 targeted tests green), regenerated the trio, rebuilt dist, and committed 14bb7487f. If the twin wakes, it must find the tree clean and STOP (branch tip carries the step).

## Welder owes

durations.tsv rows for tests/Server/SessionImportMethodTest.php + tests/Server/SessionArchiveMethodsTest.php; suite-figure/README re-pin (2 new test files: 12+10 methods per lane tests); web sidebar spec is an edit (no new row). Known-red-not-ours (untouched files, present in broad runs): ChatTest title-suggestion, SwallowingCatchSrcCensus (SkillTool), ReadmeJsonErrorContractDrift exit-table leg, KeyHelpTest :1364, and the 20 inherited vue-tsc errors above.
