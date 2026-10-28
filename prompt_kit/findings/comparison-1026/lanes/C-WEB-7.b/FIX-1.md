# C-WEB-7.b — FIX-1 (round-2: rebase onto master + pendingTrusts rename adaptation)

Fixer: cc-cmp fixer · 2026-10-28 · verdict-first, 20 lines max.

## Verdict
DONE. Branch cc/C-WEB-7.b rebased onto master 4b771634d; the WEB-7-P1 trust-announce
spec is green on the rebased tree; the branch is now mergeable as-is (28/28).

## New branch tip
f9d81da62 (amended 11db41b8e ← rebase of 06364f419 onto master; master is ancestor;
zero textual rebase conflicts, as the welder predicted; tree clean;
%ae==%ce==detain@interserver.net, zero Co-Authored trailers; diff vs master ==
exactly the 9 declared lane files — no dist, no storage.ts, no figure files).

## What changed for the rename (questions.ts only)
- watch getter `context.pendingTrust` → `context.pendingTrusts` (C-SEC-5.b f90523394
  shelf-to-list); callback iterates rows, announces each `status==='asked'` row once.
- single `announcedTrustAskId: string|null` latch → `announcedTrustAskIds: Set<string>`
  keyed on askId (re-delivery of a row never re-announces; a second door opening
  beside it announces independently). Sentence wording byte-unchanged.
- docblock + commit-message line truth-flipped to the plural shelf.
- Spec/palette fixtures needed NO change: the questions.spec trust fixture already
  carries the master row shape (target 'mcp'); palette/composer specs never read
  the trust surface.

## Discrimination proof
Planted the old singular watch back on the rebased tree → exactly the weld failure
(questions.spec trust test FAIL, 1F/5P); restored → green. Mutation reverted
(byte-diff confirms only the intended adaptation remains).

## Gates (rebased worktree, serial --no-file-parallelism, env -u LINES -u COLUMNS)
questions.spec + composerFilesImages.spec + composerHistory.spec +
paletteClearHistory.spec: 4 files, 28/28 passed, 0 failed (twice — post-rebase and
post-amend). typecheck `vue-tsc --build`: 19 errors, byte-identical set to master's
own tree (contextPanel.spec x8, contextSources x4, statusBar x2, reducer x4,
contextTrustRouting x1 — all pre-existing post-C-SEC-5.b/C-EXT-2.c, zero in lane
files). No dist rebuild (weld duty); no stash used.
