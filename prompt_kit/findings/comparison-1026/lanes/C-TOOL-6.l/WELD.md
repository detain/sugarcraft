# C-TOOL-6.l — WELD record (welder, 2026-10-31)

## Preflight (evidence re-stat by welder, from the FILES)
- REVIEW-1.md 9,172 B — line 5 verbatim: "VERDICT: CLEAN (0 CRITICAL / 0 MAJOR / 0 step-local MINOR / 3 NIT — none blocking weld; NITs are narrative/semantic notes below)"; :42 "WELD-ELIGIBLE: **YES**."
- REPORT.md 4,582 B — line 1 "STATUS: COMPLETE — 4 picks, server + tests + row flag + web pane; trio/dist RESTORED for welder regen".
- NO FIX-1 on disk (dir carries exactly REPORT.md + REVIEW-1.md at preflight) — 0 step-local MINORs, consistent.
- Master pre-weld HEAD 843dff284 == lane base (no master movement — sole welder in flight). Lead dirt (`.opencode/agents/cc-cmp-lead.md` M, AGENT_LAW_SNIPPET.md, LEAD_LAWS.md, foreign junk file) present EXACTLY as briefed; NOTHING staged.
- Own heading `#### C-TOOL-6.l —` present UNMARKED at plan :4057 (startswith-token probe; marked-count 321 BEFORE).
- Lane law greps (pre-pick, at lane tip b623ca99b): per-pick %ae detain=1 · %ce detain=1 · Co-Authored=0 → 4/4/0 by SEPARATE COUNT greps.
- Trio+dist paths absent from ALL FOUR picks (per-pick --name-only: d4f4c989a 3 files · 9874d426b 1 · 26d3c92e9 3 · b623ca99b 10 incl sanctioned mirrors web-strings.en.json + strings.en.generated.ts + en.php).
- SERVER-MANUAL-SECTION.md ABSENT on disk ⇒ manual section UNOWED (r1 Q3 matched disk).
- Preservation baselines PRE-REGEN (SERVER.md): 6.d `## Windows` slice :1581-1589 md5 a96a7db6dc0ad643b0681b63e2b94582 · 6.i "Client-hosted tools." para slice :643-654 md5 761aa2a0cd2b919a76eddc697383c7ec. Trio line-counts: schema 8,005 · SERVER.md 1,621 · generated.ts 2,362. node_modules pre-seeded (vite+vitest bins present); NO npm ci/composer/refresh-deps.

## Picks (explicit list from lane log, IN ORDER, no -x, no range)
d4f4c989a→886f8a60e · 9874d426b→5746d97ab · 26d3c92e9→235262f97 · b623ca99b→0e5444713. ZERO conflicts. Reflog: exactly 4 cherry records + weld records, zero foreign. Law %ae/%ce SEPARATE COUNT greps 4/4 detain + Co-Authored-By 0. Merged span = 16 paths.

## Trio+dist regen carry (weld-owned per 6.i precedent)
gen-protocol-schema --write: schema +68 (8005→8073) + SERVER.md +1 pure generated row (1621→1622, manual UNOWED — disk-verified). gen:protocol: generated.ts +22 (2362→2384). npm run build on pre-seeded node_modules: index.html/manifest.json/sw.js M, assets i18n-Bc3kfy5v→CFrqbToD · index-COFHJQGR.css→Cg5UjlKP.css · index-Lga6Dnd-.js→DQpCiax8.js. PRESERVATION: Windows slice md5 a96a7db6dc0ad643b0681b63e2b94582 pre==post (:1581→:1582) · manual para md5 761aa2a0cd2b919a76eddc697383c7ec pre==post (:643→:644). ProtocolSchemaDrift GREEN-AT-REGEN: trio co-run OK 45T/505A 0F (+1A vs r1 504 disclosed).

## VITEST REGEN-INTERACTION FINDING (step-local, handed to lead — NOT cured here)
Post-regen serial vitest (3 files): 28 passed / 4 failed. All 4 = client.ts idempotencyKey stamp on shells.input payloads, driven by the lane's OWN schema `"idempotent": true` now present in generated IDEMPOTENT_METHODS (:1970). Failing tests: shellsInput.spec "sends text and key in ONE request and answers the wire shape" + "a key-only send carries NO text param"; processesSection.spec "Enter sends the draft WITH its Enter in one call..." + "the named-key buttons send their key alone". CAUSALITY (cp+md5 harness, same-shell): pre-regen HEAD generated.ts ("shells.input" grep 0) → 32/32 PASS; regen restored (md5 13b5acff7358e1d812f8dec8caeea760) → 28/4 deterministic run-2 identical. Builder/r1 32/32 was valid at the RESTOREd stale table (regen-forbid law). Server-side wire is correct (accepts idempotencyKey; every PHP gate green) — the 4 TS expectations need a regen-aware flip; §7 hand-off. vue-tsc --noEmit rc0 EXEC (upgrades r1's UNVERIFIED leg: no type breakage).

## Gates (merged tip)
ShellsInputTest:: 31T/144A 0F 0S LIVE-FIFO-RAN · trio 45T/505A 0F · Lang pair 15T/4256A · WebStrings pair 9T/588A · TWGR BARE 17T/3989A (ZERO rows added) · DTH BARE 14T/204A · ReadPath|GlobDialect 32T/1137A (ONE base-inherited 1F, NEITHER number) · PAIRING 39T/192A GREEN · Layered 99T/423A 0F fold-NOTHING · generators --check rc0 ×4 · en.php dupscan 0 · Bash.php untouched, "bash -c " blob-count 1 (:538) · engines 9-file zero-diff by name · residue grep 0.

## Closeout
Marks 321→322 (python startswith). Absorption: git cherry master cc/C-TOOL-6.l = four '-' zero '+'; worktree+branch pruned; survivor EXACTLY cc/C-TOOL-6.g. §3 untouched. NO push. WARN NEXT WELDER: 6.g trio canvas carry + WELD-MUST-GREEN pairing + preserve THIS weld's trio state + the 6.d/6.i prose slices + expect 28/4 on the three web specs until §7(1) lands.
