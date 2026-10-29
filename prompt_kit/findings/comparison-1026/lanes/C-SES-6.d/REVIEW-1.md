# C-SES-6.d — REVIEW-1 — VERDICT: CLEAN (0C/0M/0 step-local MINOR)

Lane: cc/C-SES-6.d @ 8bdd36271 (parent aa38e06b1). 1 file `.github/workflows/release-phar.yml` +83/-8. Author/committer detain@interserver.net both greps, zero trailers (commit-body %B grep co-authored/generated-with = 0). Worktree clean (git status --porcelain empty).

## Re-derived claims (disk, not report)
- tools/tests full suite, worktree vendor, plain pipe: **OK (499 tests, 2639 assertions)** — matches builder exactly.
- Guard trace: WorkflowRunInterpolationTest BODY_FLOORS release-phar=10; file has 13 `run:` bodies (12 block + 1 one-line); parser walks each body (headline arm per-body per-regex, floors are minimums, empty-slice arm). New gz/checksum/publish bodies are genuinely scanned; builder's planted→1F claim is consistent by trace (one body, one accused regex, class = 3 methods).
- SelfUpdate names: PHAR_ASSET 'candy-code.phar' (:178), SIDECAR (:180), SIGNATURE 'candy-code.phar.minisig' (:194), SIDECAR_SIG '...sha256.sig' (:206); matching is `=== $asset['name']` (:824-831). candy-code-gz.phar{,.sha256,.minisig,.sha256.sig}, .packages.json, .rb, install.sh all distinct → plain stays sole self-update target. Confirmed.
- (1) gz body vs plain body, manual sed-diff: identical `set -euo pipefail`, byte-identical SOURCE_DATE_EPOCH export line, same php -d/script/--version; deltas only --out name + --manifest-out (plain) vs --gz (gz) — brief-correct.
- (2) Sign: PHP foreach ["candy-code.phar","candy-code-gz.phar"] emits 4 sig files + both minisig.sha256; keyless early-exit shared → all six skip together; publish gate on plain minisig implies both trios exist (set -e loop).
- (3) Publish assets: plain pair, gz pair, packages.json, rb, install.sh, +6 sigs signed (7/13). ✓
- (4) One run, one $TAG → `git log -1 --format=%cT` identical both builds. ✓
- (5) No false-green: test -s before cut; sha256sum/cp/gen all fail-loud under set -euo pipefail; gen-homebrew-formula.php is fail-closed exit 1 on bad hex/unwritable --out, template resolves via dirname(__DIR__) (CWD-independent — works from workspace root); packaging/homebrew/candy-code.rb.tmpl verified at BASE aa38e06b1 (C-SES-6.c pre-landed, scope honest).
- (6) phar_sha cut from dist/candy-code.phar.sha256 — the PLAIN sidecar, one line after it is written. ✓
- (7) No tap push: grep push/tap → prose only (:28-29, :292-293, notes); no new secrets (env adds TAG bindings only). §6.3 ruling verified at plan_comparison.md:4135 (Declined — asset only) and commit body quotes it. ✓
- (8) `${{ }}` in run bodies: zero (single occurrence at :61 is a YAML comment above the run block); everything crosses via step env: TAG. ✓
- build-phar --gz semantics verified in script: compress(Phar::GZ) then unlink+rename → asset lands at exactly dist/candy-code-gz.phar; ext-zlib caveat present in release notes as the brief's named risk requires.
- gz-build preflight hazard ruled out: dirty check is `git -C candy-code status --porcelain` (scoped); plain build writes only to repo-root dist/, which is /dist/-gitignored (.gitignore:42-50 documents this exact interaction).
- No candy-code src/tests/php test files → no PharCompatTest flip, no DUR, suite-figure/durations untouched. ✓

## Limits of re-execution (harness, not lane)
Reviewer-lane permission matcher refuses redirections and php -r/heredocs, so the planted-interpolation mutation and per-body bash -n could not be RE-run here; both settle by trace above (guard loops accuse each body; new lines are same-shape as shipped ones; sign-step php body is inside the pre-existing single-quote heredoc idiom, notes string carries no apostrophe/unescaped quote). Builder's own mutation proof was cp+md5-restored.

## NIT (weld note, non-blocking)
- The gz twin gets no smoke leg (plain phar has three). Not in the brief's DoD; ext-zlib runtime read is documented in notes; if a future step executes it, php handles internally-GZ phars transparently when zlib is loaded.

## Weld carries (expected)
NO durations.tsv rows, NO suite-figure delta (zero php/ts test files touched). §4 cites 8bdd36271. §7 closeout: workflow exercised only on real candy-code/v* tags (or dispatch) — CI-only change, no local run can exercise publish.
