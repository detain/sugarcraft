# REVIEW-2 (cure review) — C-AGT-5.g (W15, docs/CI.md GitLab recipe)

Branch `cc/C-AGT-5.g` @ b5f0033e9 (parent 26b4eac75; exactly ONE commit, `rev-list --count` = 1;
`git status --porcelain` empty). `%ae`=detain 1, `%ce`=detain 1 (separate greps), zero trailers.
Diff vs base: `candy-code/docs/CI.md` +115/-0, a single hunk = the new "## GitLab CI" section only.
No src/tests/tsv/composer/dist touches. (Spawn wording "README.md 1-line" is not in the design
block — `files.modify: candy-code/docs/CI.md` only; README is correctly untouched, diff 0 lines.)
Amend footprint 5b4ab4ebd→b5f0033e9 is +37/−17 confined to the two flagged paragraphs + the
PHAR_VERSION comment; recipe legs byte-untouched as claimed.

## MAJOR-1 cure — CONFIRMED, facts true against master
- Grep 0 for "onward also publish" and the old asset-threshold sentence. New era wording present:
  sidecar shape "young", entered pipelines 2026-10-27, "a release cut before it — `v0.1.1` among
  them — publishes its signature as the legacy whole-archive `candy-code.phar.minisig` and has no
  `candy-code.phar.sha256.sig`".
- Independently re-derived on disk: `git show candy-code/v0.1.1:.github/workflows/release-phar.yml`
  publishes only `.minisig` + `.minisig.sha256` (no `.sha256.sig`); 6ab223f36 (2026-10-27,
  "release pipelines sign the sha256 sidecar", touches release-phar.yml + phar-dev.yml +
  release-app-phar.yml — "pipelines" plural accurate) is NOT an ancestor of the v0.1.1 tag;
  master release-phar.yml:122,149 publishes `.sha256.sig` over the sidecar body;
  `git show candy-code/v0.1.0:...release-phar.yml` has zero signing assets, matching "Releases at
  or before v0.1.0 carry no signature asset at all". Mirror sentence matches README.md:128-134.
- PHAR_VERSION comment now states the pin's `.sha256.sig` curl 404s pre-2026-10-27 and directs
  moving the pin; "quietly skipping the signature leg is not the alternative to take" — honest,
  and no post-sidecar release exists yet to pin, so no better example value was available.

## MAJOR-2 cure — CONFIRMED, order now matches the sources
- Grep 0 for "never the reverse" / "trusts first" / "signature before hash". New paragraph:
  swap path = sidecar → temp download → digest → pinned-key leg LAST (after hash, before copy) —
  verbatim parity with SelfUpdate.php docblock "HOW THE SWAP HAPPENS" (:66-74) and the :550
  comment "Pinned-key leg, after the hash … before the copy"; publish pipeline checksum-before-
  signing matches release-phar.yml:105 ("runs BEFORE signing on purpose"); recipe's own sig-then-
  hash order is kept and credited to the page, not attributed to self-update. Invariant sentence
  ("nothing runs until the pinned key has vetted a signature over the digest gating the bytes")
  is true for both sources. "Despite the file names … raw base64 sodium" still true (:147/:149).
- Sidecar format: `sha256sum candy-code.phar > candy-code.phar.sha256` (release-phar.yml:111) —
  the recipe's `sha256sum -c` in the download CWD works; PUBLIC_KEY_BASE64 exists (:234).

## Guards (lane worktree candy-code, plain pipe, env -u LINES -u COLUMNS; PtyRunToolTest never in filter)
- `--filter 'ReadmeRosterDrift|EnvRosterDrift'` → OK: 47 tests, 38,341 assertions (2 standing
  PHPUnit deprecations). The briefed 28T/349A pair (ReadmeRosterDrift+BootstrapLaunchFormatConstants)
  is likewise green per FIX-1 and this run's superset.
- `--filter 'DocFigureProseDrift'` → 91T/3983A, 1F = `testAgentsAuthoringToolClaimsSurviveTheBuiltInDirectory`
  "thirty-seven outside the pinned word map" (:5425). JUDGED RED-AT-BASE, not introduced: cure
  9fbb73173 is NOT an ancestor of 26b4eac75, and the offending phrase sits in AGENTS_AUTHORING.md
  at the base itself — a file this lane never touches. Foreign W13 standing item, REPORT-ONLY.
- In-step guard law: no guard file needed moving; CI.md is scanned by BootstrapLaunchFormatConstants
  page-quote roster and passes.

## Jargon / structure
Campaign-ID sweep (C-AGT/C-SES/R1x/W1x/p2-miss/plan refs/TODO) in the added 115 lines: 0 hits.
Links/anchors same as r1-verified set. Pre-existing adjacent sections ("Running the headless
binary", "Linting this action") are outside this lane's diff and were not re-adjudicated here.

VERDICT: CLEAN 0C/0M
