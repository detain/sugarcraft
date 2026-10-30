# C-WEB-2.c — Typed `/skill-name` and `$name` in `-p` — DONE

RESULT: LANDED, one commit on `cc/C-WEB-2.c`.
- SHA (cat-file'd): `e8923c331619b3e212844466d5c8e2e6869eda1b` — `%ae`=`%ce`=`detain@interserver.net` (verified separately), zero trailers, tree clean.
- Cut base: `5f8717874`. Master MOVED under the lane to `eee747273` (origin `574e69f1d`) while working; the cut base is still an ancestor of the new tip (`git merge-base --is-ancestor` proven) — a clean cherry-pick is available to the welder.

## Contract as landed
- Server-side only (no web files — the design block names none; the web client's typed text already rides `session.send`, served by C-WEB-2.b's `SessionHost::admit()`).
- `NonInteractive::historyFrom()` gains a 6th OPTIONAL param `?SkillRegistry $skillRegistry = null`; all existing positional callers byte-unchanged.
- Resolution order: (1) `SkillSlashInvocation::draft($prompt, $registry)` rewrites a typed `/name args` to `$name args` BEFORE any scan (null → prompt unchanged); (2) the rewritten text is what reaches `Message::user` and the `@`-scan; (3) `$`-leg gated `$skillRegistry !== null && str_contains($text,'$')` → `SkillMentions::resolve()` (registry is the gate: `$HOME`/`$1`/`$PATH` stay text, code spans exempt, max 3, disabled names → notices); (4) attachments join SKILL-FIRST then file then context (`TurnController::userTurnMessage` order); notices order `[...skilled, ...resolved, ...context]`, drained to stderr by the existing caller loop.
- Trust boundary: only the TYPED prompt is rewritten/scanned — piped stdin rides byte-identical (pinned).
- Host/client split: host resolves against the registry the launch's `EngineBackend` was built with — read off the backend via a NEW mirror accessor `EngineBackend::skillRegistry()` (beside `withSkillRegistry()`; `mutate()` carries it through every clone). NOT `Bootstrap::skillRegistry()` (fresh-built per call — risk note honored). A non-engine injected backend or null registry → whole channel off, byte-identical pre-lane behavior.
- Refusal wording: no new strings — the TUI/serve-identical `SkillMentions` notices ("$x was not attached: …") flow through `$mentionNotices`; the slash gate is silent-by-design (name not claimed → sent as typed), same as served route.

## Files
- modify `candy-code/src/Cli/NonInteractive.php` (historyFrom + call site :~517)
- modify `candy-code/src/Backend/EngineBackend.php` (accessor + docblock only)
- modify `candy-code/docs/SKILLS.md` (shared-gate paragraph names the `-p` route)
- create `candy-code/tests/Cli/NonInteractiveSkillSlashTest.php` — **NEW *Test.php: welder owes its `scripts/parallel-tests-durations.tsv` row** (9T)

## Gates (targeted, plain pipe, cwd=candy-code, PHP 8.3.6)
- own file: `--filter NonInteractiveSkillSlashTest` → OK 9T/47A (2 pre-existing family-wide PHPUnit deprecations, identical at `NonInteractiveMentionsTest` alone — not lane-introduced).
- `--filter NonInteractive` → OK 157T/716A; `SkillSlashInvocation|ServedSkillSlash|SlashSkillInvocation|SkillMentions` → OK 32T/157A; `EngineBackendWither` → OK 37T/1226A (reflection sweep survived the new accessor); `EngineBackend` → OK 299T/2062A; `SessionHost|TurnController|ContextMentions|FileMentions` → OK 176T/892A; doc guards `DocFigureProseDrift|ReadmeRosterDrift|ClaimFamilies|InertFrontmatter|PathsGlobDocumentation` → OK 119T/4375A. PtyRun flake excluded (filter never matched it).
- `php -l` clean on all 4 files.
- Mutations 5/5 discriminating, cp+md5 restored each time (never git-checkout): M1 rewrite neutered → 3F (incl. live-chain); M2 skill-leg neutered → 5F; M3 call-site registry → null → exactly 1F (the live-chain wiring pin — proves unit tests alone can't catch call-site slide); M4 stdin scanned → 4F; M5 attach drops `$attachment->skill` → 3F.

## vue-tsc / vitest
NOT APPLICABLE — zero `candy-code-web` files touched (PHP-only step by design). No dist, no protocol trio (no wire surface moved), no generated.ts. Nothing to carry to the welder on that axis.

## §1.8 roster set moved
No new command/skill/setting/env var: the dispatch-arms numeral is UNCHANGED (still 64; `slashCommands()` untouched). `ReadmeRosterDrift` + doc-figure gates green as run above; `docs/COMMANDS.md`/`lang/en.php` NOT needed and NOT touched (lang/en.php + README + COMMANDS.md are the C-WEB-2.f collision surface — stayed off them entirely; README's Skill-mentions bullet needed no flip: it never restricted the channel to the TUI). No `READ_ONLY_COMMANDS` row added. No GlobDialect hazard: new test file carries no wildcard-shaped literals.

## Carries / disclosures
- NEW durations.tsv row for `candy-code/tests/Cli/NonInteractiveSkillSlashTest.php` (welder).
- No new *Test.php besides the above; no suite-figure claim (staleness pair is standing).
- Deviation from design block line-cites (not behavior): historyFrom actually :1674 (brief said :1375); call site block actually :485-521; TurnController lives at `src/Host/TurnController.php` (design said Support). Fix semantics exactly per block.
- The pre-existing `@`-attach loop dropped the skill tag (`attachFile(path,data)`); it now passes `$attachment->skill` like the TUI loop — behavior-identical for file/context attachments (their `skill` is null), pinned by M5.
- `Skill::fromFile`-over-disk fixtures (like `ServedSkillSlashTest`): the mention channel re-reads bodies at resolve time, so snapshots exist on disk; helper named `diskRegistry` mirrors that lane's proven shape (identical name+body family = drift-green).
- Report written post-commit deliberately (SHA cat-file'd first). This file is evidence, NOT staged in the lane commit.
