# Chafa→SugarCraft campaign — findings ledger (post-audit residuals)

Campaign: W0-W11 chafa port remediation (plan `plan_chafa.md`, untracked; closed 2026-10-21 at master `365c58086`).
This file records the residual minors the campaign left open AND their disposition after the 16-lane
review-audit + the fix-audit remediation program (lanes 1-9, reviews rev-A..E: ALL APPROVE, 0 critical / 0 major).
Evidence: `/tmp/opencode/chafa-study/review-audit/l1..l16/` (audit), `/tmp/opencode/chafa-study/audit-fix/` (fixes).
Campaign hard laws (SHA existence, author law 198/198, §0.14 digest-disclosure, id joins, zero-golden default)
verified CLEAN by the audit — nothing below is a law violation.

## Queued for the weld doc commit (prose-only weld-minors WM1-WM4)

| Id | File:line | Residual | Disposition |
|---|---|---|---|
| WM1 | `sugar-image/src/Symbol/SymbolMap.php:1313` | "unobservable approximation" wording — the exhaustive-coverage proof (isZeroWidthCodepoint ⟺ chafa width>0, 1251 entries) makes the agreement EXACT, not approximate | QUEUED: reword to exact-agreement in the weld doc commit (rev wave: prose-only minor, no behavior) |
| WM2 | `sugar-image/src/Quantizer.php:197,209,211` | three PRE-EXISTING chafa `+4` line-cites drifted (a54ecdf7f's "verified-exact kept" claim overstated) — true targets 399-401 / 402 / 404-409 | QUEUED: cite re-anchor in the weld doc commit |
| WM3 | `sugar-image/src/Dither/Ditherer.php` (diffuseError docblock) + fold-test class doc | "conserves everything chafa-conservable at the row ends" overclaims: the trailing-edge dy=0 horizontal arm is dead under the PHP clamp (FS 7/16, Stucki 12/42, Atkinson 2/8 recovered) | QUEUED: soften to strictly-positive recovery wording |
| WM4 | `sugar-image/src/Renderer/Renderer.php` supportsAlpha note (optional) | "chafa ladder" phrasing could name chafa's actual default | QUEUED (optional): name INDEXED_16 as the ladder default |

## Nits reviewed and NOT taken (recorded decisions, do not re-mint)

- `br1` pre-existing self-read leg — no-op under current call graph; left as-is.
- `sugar-colorprofile/src/ColorProfile.php:49` / `src/ColorDepth.php:34` FGBG_BGFG mentions — pre-existing historical wording; left.
- AnimatedWebpDecoder at-cap (exactly 64MiB) leg unpinned — DISCLOSED in FA-5 lane report; bounded post-read ceilings make it benign; left.
- `sugar-video/src/Render/LumaRamp.php` CHARS2 figure: audit-l12/E10 re-check shows micro-13's 15-char claim itself stale — current 10-char doc truth already shipped via W10 4b221dd98; no action.

## Governance/record gaps found by the audit (closed via plan errata sweep 2026-10-21, GA-4 lane)

- Stale review-status cells (~19 ☐ never flipped S3-S7 + KANA/dup-seam/micro rows) — flipped in place with dated notes in `plan_chafa.md` §4.7.
- Evidence artifacts cited-but-never-archived: rev-s6-5 REVIEW.md, S6.3 range-diff/interdiff, w1/s1-10 dir; fx51 grep-invisibility; C-07 disclosure placement (5th paragraph, accepted-in-substance) — all recorded as honest historical gaps in the plan's dated closeout block.
- §4.6 second-reviewer 0/6 HIGH + W6 per-lane rev fleet never dispatched — BACKFILLED by l6/l10 audit lanes 2026-10-21.
- §4.14 unsanctioned multi-id commits (6: 4d335132d, 6c79124e7, 0e2bedb74, 991a0f76f, 831057058, 9d95635a0) — ACCEPTED-WITH-REASON (same-step/same-file clusters per §1 matrix; docs-closeout enumeration aids joins).
- Fix-audit lane master SHAs: TBD-by-welder (this ledger cites none; branch tips live in refs `fix-audit/1..9`).
