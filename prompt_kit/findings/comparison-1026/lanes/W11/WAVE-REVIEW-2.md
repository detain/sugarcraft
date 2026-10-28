# W11 wave review — round 2

VERDICT: CLEAN

Tip judged: 23fa8ab8e (verified == master). Round-1 MINOR-1 cure verified complete; NIT-2/3/4 and §7 duties accepted-carried per WAVE-FIX-1.md — not re-raised.

## 1. Cure (23fa8ab8e) — COMPLETE + CORRECT
- Files: candy-code/lang/en.php (+1 key) · candy-code/src/Attachments/FileMentions.php (sprintf → Lang::t('chat.attach.pdf_pages', {path,pages,limit}), `use SugarCraft\Code\Lang;`) · WAVE-FIX-1.md ledger. Author/commit %ae %ce = detain@interserver.net both, zero trailers.
- Byte-identity: en.php:1906 template == retired sprintf format with %s→{path}, %d→{pages}/{limit}; sugar-core T::interpolate is strtr with (string) casts, and both numeric legs are int-typed (estimatePdfPages():int; DOCUMENT_MAX_PAGES const) so (string)===%d. Proof: FileMentionsTest:154 pins the exact pre-cure sentence ('…101 pages, over the 100 page limit.') byte-untouched and passes through the new Lang::t path (gate green below).
- Placeholder interpolation: keys path/pages/limit match braces exactly; unmatched-placeholder policy (left intact) not in play.
- en-only CORRECT per §6.3 ruling h (plan:4156): the 11 community locale files land whole-keyset LAST at C-TUI-6.h (W41); candy-code/lang/ contains only en.php today, so LangParity (dir-derived locale set) holds; no locale file touched by the cure.
- §4 C-CTX-6.b docs/guards promise (plan:1364: lang keys pdf_pages + document_withheld) now fully true — no plan erratum needed; the fix took the promised route (round-1 cure option 1), sibling document_withheld (en.php:1905) precedent mirrored.
- Single-source: retired literal exists nowhere in src (grep 0); key minted once each.

## 2. Gates (cwd candy-code, plain pipe, env -u LINES -u COLUMNS, PtyRunToolTest never in filter)
- --filter FileMentions → OK, 17T/61A (2 env deprecations, base-shape) — EXACT expected.
- --filter 'LangParity|GlobDialect|DocFigure' → OK, 112T/7190A — EXACT expected; +1A vs r1's 7189 is the new key's LangParity placeholder arm (disclosed in WAVE-FIX-1, reproduced).
- No new issues introduced. Generators: bare `php` invocation refused by this reviewer's bash map (same disclosed limitation as r1); cure diff carries zero generator input surface (no registry/command/tool/settings/protocol/_data/ansi files), so --check drift risk nil; §7 battery duty already recorded in WAVE-FIX-1.

## 3. Step ledger re-confirm — 10/10 present + ancestors
All 10 hashes: `merge-base --is-ancestor` OK AND `✅ <hash>` mark present in plan §4 — 51812d155 · b72a169f2 · fa14b82c6 · e07d05447 · f90523394 · 46d64b18a · b2b049b5b · 1999f4114 · 08e91c647 · 15ec4b791.

## Hygiene / standing roster (report-only, unchanged)
- No cc-cmp worktree or cc/ branch for any W11 step; the 8 live cc-cmp-* worktrees/branches are in-flight OTHER-wave lanes (same set as r1).
- Not W11's: ReadmeSuiteFigureDrift staleness → §7 · ChatHostLangCatalogue → C-PRV-1.b seam · web typecheck 19 · ProjectTierRefusalInventory 2F · SwallowingCatchSrcCensus SkillTool.php::execute.

Wave 11 ships. Closeout owes the §7 duties already ledgered in WAVE-FIX-1.md.
