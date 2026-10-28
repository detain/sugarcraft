# REVIEW-1 — C-AGT-5.g (W15, GitLab CI docs step)

Branch `cc/C-AGT-5.g` @ 5b4ab4ebd (parent 26b4eac75; declared base 6875a1d90 is a strict ancestor of the parent — branch stacked on one extra weld commit; still exactly ONE lane commit in master..HEAD).

## 1. Disk-verify — PASS
- `git branch --show-current` = cc/C-AGT-5.g; tip 5b4ab4ebd exists; `git status --porcelain` empty.
- `%ae` and `%ce` each grep 1× `detain@interserver.net`; zero Co-Authored/Co-developed trailers.
- `git show --stat`: ONE file, `candy-code/docs/CI.md` +95/-0. Matches BRIEF `files.modify` exactly (docs-only). No src/test/composer file touched; forbidden files (durations.tsv, suite-figure.json, dist/, docs/protocol/) absent.

## 2. Truth audit vs master-at-base workflows + code
VERIFIED TRUE:
- `release-phar.yml:111,233` publishes `candy-code.phar` + `candy-code.phar.sha256` sidecar; `:148` `.sha256.sig` = base64 detached sodium signature over the SIDECAR body; `:146` legacy `candy-code.phar.minisig` signs the raw whole phar; `:136` step skips green without `SUGARCRAFT_SIGNING_KEY` (doc never invents env-var names — it uses none beyond masked-GitLab-variables doctrine).
- "Despite the file names nothing here is minisign format: both are raw base64 sodium signatures" — verbatim parity with `SelfUpdate.php:189-191` and `README.md:133-134`.
- `SelfUpdate::PUBLIC_KEY_BASE64` (:234) exists, base64 of 32-byte sodium public key → the recipe's `strlen($pub) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES` / `SODIUM_CRYPTO_SIGN_BYTES` legs match `verifyDetached()` (:1008-1015) exactly.
- "legacy … accepted for one further release cycle" — `SelfUpdate.php:882` "LEGACY PATH (one further release cycle)" + `:213-214`.
- Envelope: `NonInteractive.php:97 SCHEMA = 1`; success doc `{schema,result[,refusals]}` (:2103-2105), failure doc `{schema,result:null,error}` (:1406) → `jq -e '.schema == 1 and .error == null'` discriminates correctly; `null|length`=0 for absent `.refusals` is benign. Flags all real: `-p/--root/--permission-mode/--max-cost/--output-format` in `bin/candy-code:220`; `PermissionMode::Plan` (`src/Permissions/PermissionMode.php:15`). GitLab vars `CI_MERGE_REQUEST_DIFF_BASE_SHA`, `CI_MERGE_REQUEST_IID`, `CI_API_V4_URL`, `CI_PROJECT_ID`, `CI_PIPELINE_SOURCE=="merge_request_event"`, `GIT_STRATEGY`, protected/masked doctrine — all genuine. Download URL grammar `releases/download/candy-code/<version>/<asset>` matches the per-app tag convention (`phar-dev.yml` rolling `candy-code/dev` uses the same shape). Anchors `#outputs` (heading `### Outputs` at CI.md:48) and the `candy-code-version` "law above" (CI.md:45) both resolve. YAML: plain-scalar continuations and the single-quoted curl line (no embedded `'`) parse.

FINDING MAJOR-1 — `candy-code/docs/CI.md:184-185,215` (asset/version threshold):
"releases from `candy-code/v0.1.1` onward also publish `candy-code.phar.sha256.sig`" is false, and the recipe's `PHAR_VERSION: v0.1.1` pin curls a `$PHAR_BASE/v0.1.1/candy-code.phar.sha256.sig` asset that does not exist (404 → `-f` kills the example job). Timeline on disk: v0.1.0 (2026-10-20 03:30) and v0.1.1 (2026-10-20 21:03, tag 79cf113ff) are the only tagged releases; the signing pipeline first landed at ae7a46a0e (2026-10-20 19:50) publishing ONLY `candy-code.phar.minisig` + `.minisig.sha256`; `candy-code.phar.sha256.sig` entered the pipeline at 6ab223f36 (2026-10-27, "C-SES-5.c: release pipelines sign the sha256 sidecar") — a NO_TAG_YET asset at lane time. `README.md:128-132` is the canonical truth: releases from v0.1.1 onward "carry an Ed25519 signature"; the `.sha256.sig` preferred-asset sentence is NOT anchored to v0.1.1.
Cure: reword the sentence to mirror README (v0.1.1 onward carries a signature asset; the current pipeline publishes `candy-code.phar.sha256.sig` — releases built before 2026-10-27, including v0.1.1 itself, carry only the legacy whole-archive `.minisig`), and make the recipe example consistent: either keep the pin at a version that will carry the sidecar-sig (state it), or note beside `PHAR_VERSION` that a pre-2026-10-27 release needs the `.minisig` leg instead.

FINDING MAJOR-2 — `candy-code/docs/CI.md:183,186-189` (verification order attributed to the sources):
The section promises to "verify it the way `candy-code self-update` verifies it" and claims the self-updater "verifies that signature and only then lets the sidecar gate the bytes", generalizing to "signature before hash, never the reverse, because a party … can always sign their own poisoned bytes when the order is flipped" (commit message adds "the order release-phar.yml and self-update document"). The cited sources document the OPPOSITE order: `SelfUpdate.php` class docblock "HOW THE SWAP HAPPENS" (:66-74) — the phar sha256 is "compared with hash_equals()"; "If a signing key is pinned … the detached signature leg runs NEXT"; the code comment at :551-553 is explicit: "Pinned-key leg, after the hash (the temp bytes are the real artifact now) and before the copy". And `release-phar.yml:105-107` says "the checksum runs BEFORE signing on purpose". The real invariant — which README:128-129 states correctly — is that NO SWAP happens unless the pinned key vets the sidecar signature; the pinned leg gates the rename, not the hash comparison. (Also the "never the reverse" rationale is wrong as doctrine: self-update's own hash-first-then-pinned-leg order is sound because the swap is gated by the signature.)
Cure: drop the inverted attribution and restate the invariant: "the self-updater refuses the swap unless that signature verifies under the pinned key (its hash gate runs first on the temp bytes; the pinned-key leg must still pass before anything is exchanged). This recipe checks the signature before the bytes instead, so an unsigned sidecar is never trusted even momentarily." Keep the recipe's own order (it is safe); keep "a key served by the channel it authenticates is not an anchor" (true, matches pinned-key law).

## 3. Guard conformance
Plain pipe, `env -u LINES -u COLUMNS`, cwd candy-code:
- `--filter 'ReadmeRosterDriftTest|BootstrapLaunchFormatConstantsTest'` → `Tests: 28, Assertions: 349 … OK (deprecations only)` — the PAGE_QUOTE_IS_PROSE scan of `docs/CI.md` (BootstrapLaunchFormatConstantsTest:344) passes with the new quoted prose. No guard file was touched — and none needed touching: DocFigure has no CI.md arm (grep 0), no in-step guard obligation arose.
- `--filter 'DocFigureProseDriftTest'` → `Tests: 91, Assertions: 3983, Failures: 1` — the sole failure is `testAgentsAuthoringToolClaimsSurviveTheBuiltInDirectory`: "the spelled count 'thirty-seven' is outside the pinned word map" (:5425). This is the disclosed foreign standing red (W13 loop owner), AGENTS_AUTHORING-page arm, zero relation to CI.md. REPORT-ONLY, not a finding.
- PtyRunToolTest not in any filter (law honored).

## 4. Jargon / links / structure
No C-AGT/R13/E-ID plan-lane vocabulary in shipped prose (IDs live only in the commit subject, licensed). No links to untracked plan files; all links are same-dir docs pages that exist. Code fences balanced; GitLab YAML is illustrative-labeled ("nothing here runs in this repo's CI") per the brief's "the YAML is illustrative".

## Foreign standing reds observed (report-only)
DocFigure 'thirty-seven' word-map arm (this run confirmed it firing).

VERDICT: FINDINGS — 2 MAJOR, both in the new CI.md prose; scope, authorship, hygiene, and all other cross-reads clean.
