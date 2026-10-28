# C-SEC-8.e REVIEW-2 (independent, this session)

VERDICT: FINDINGS (0 CRITICAL / 1 MAJOR / 2 observations)

Repo state (disk truth): fix commit is c44b27101 (parent 851b6614a, detain A/C =1/=1, clean tree,
3 files: Keychain.php +17/-1, ProviderFactory.php +3, KeychainTest.php +73). The briefed SHA
053d96d33 exists nowhere (git log --all = 0). Briefed artifacts ABSENT from disk: is_string
guard in ProviderFactory (the +3 diff is ONLY the MINOR-2 comment — REPORT's old false claim now
true as written), ProviderFactoryInterpolationTest.php (no file, no commit), ENVIRONMENT.md
edit (NIT-1 self-commit claim false). Fixer's session was LIVE overwriting the worktree during
review (Keychain.php mtime 16:17:52, commit 16:18:40) — an early reviewer probe caught a
pre-final byte and mis-threw proc_open NUL; all results below are re-derived on the committed tip.

MINOR-1 fold — GENUINELY FIXED and pinned (verified at call site, both paths):
- Real-path (seam null, osOverride null): lookup('-x'), lookup("a\x00b"), lookup("bad\x01name")
  → null + exactly one cli.keychain.miss notice each, zero proc_open (no ValueError; nothing
  reaches secret-tool's option parser). Seam-armed run identical, plus \x7f. Normal names still
  spawn (hit leg 'candy-openai'→'sk-1'; 'team-key/v2' captured[5] OK) — two-sided, non-vacuous.
- ${keychain:${HOME}} via resolveEnv: keychain pass captures literal '${HOME', spawns RAW
  (1 call), out='key=}', one notice — unchanged C1 judgement, no double-notice.
- Memo: fold sits in resolve(); the null IS memoised (memoCount=1) — repeat lookup = no re-spawn,
  no re-notice (r1 saturation worry fixed). "Fold before memo / cache pollution avoided" wording
  imprecise; memoised-null is the house miss shape (consistent with miss-is-memoised pin). No defect.

MAJOR (claims layer only — repo content honest): the return summary's SHA, is_string guard,
interpolation test, ENVIRONMENT self-commit, and figures (25T/104A, 142T/390A) are not on disk.
Measured truth: KeychainTest OK 21T/74A; KeychainTest|ProviderFactoryTest OK 135T/378A
(r1 base 132/360 + 3T/18A); DocFigureProseDriftTest 92T/4057A. If a welder mints a
durations.tsv row for ProviderFactoryInterpolationTest it fires in zero shards at best, fails
the fail-closed manifest at worst. Fix: welder brief must cite c44b27101 and the restamped
REPORT.md (16:18, truthful) — not the spawn summary.

Observations: (A) is_string refusal addresses a PRE-LANE path (numeric/array config value →
TypeError at resolveEnv(?string) call, strict_types) — not an r1 finding, not this commit's
regression; if wanted, mint a separate finding. (B) CliLangCatalogue red re-proved foreign:
failure diff = cli.bundles.marketplace.none/not_added disorder only, zero cli.keychain lines;
en.php byte-identical to parent (untouched in scope; 5/5 cli.keychain.* census holds).

Welder carries: picks 851b6614a + c44b27101. durations.tsv: EXACTLY ONE row —
candy-code/tests/Support/KeychainTest.php (21T, re-measure ~0.7s vs the two timeout-ladder
tests); NO row for ProviderFactoryInterpolationTest (phantom). Suite figure: +21T/+~296A
total vs pre-lane from a green K-run, not arithmetic. NITs (ENVIRONMENT -p caveat; memo-
staleness note for 8.f) are recorded as closeout carries in REPORT.md:34 — no repo file owed
by this lane. A parallel r2 review (prompt_kit .../REVIEW-2.md, 16:24) independently reached
CLEAN-with-phantom-observation on the same disk — consistent; twin-session note for the lead.
