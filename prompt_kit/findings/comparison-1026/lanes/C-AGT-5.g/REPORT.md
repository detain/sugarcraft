# C-AGT-5.g — lane report (docs-only)

VERDICT: DONE. Commit `5b4ab4ebd` (cat-file-verified, full SHA
`5b4ab4ebdbda5cb51fd72480e82073bbc76fe9e0`) on branch `cc/C-AGT-5.g`, parent
`26b4eac75`, tree clean, never pushed. One file: `candy-code/docs/CI.md` +95 —
new "## GitLab CI" section between "Running the headless binary…" and "Linting
this action": illustrative `.gitlab-ci.yml` (diff-text job → verify-and-review
job with `GIT_STRATEGY: none` → post job as sole write-token holder),
signature-before-hash order, plan-mode + max-cost, jq over the envelope, and
the fork/MR + prompt-injection doctrine restated. No new *Test.php (no
durations duty). No guard rows moved (none of my text quotes a promoted launch
format; CI.md's existing `PAGE_QUOTE_IS_PROSE` row in
BootstrapLaunchFormatConstantsTest covers the page unchanged).

Asset-name facts read from `.github/workflows/release-phar.yml` at base, not
memory: tag trigger `- 'candy-code/v*'` (:25); `(cd dist && sha256sum
candy-code.phar > candy-code.phar.sha256)` (:111); `candy-code.phar.minisig` =
base64 detached sodium sig over the RAW PHAR, "KEPT ADDITIVELY" (:126-128,
:146-147); `candy-code.phar.sha256.sig` = sig over the SIDECAR BODY, "Current
`self-update` verifies this leg and only then trusts the sidecar" (:122-125,
:148-149); publish assets list (:233-235). README:120-136 confirms
`v0.1.1`-onward signing, minisig "accepted for one further release cycle",
"raw base64 sodium… neither is in minisign format". php:8.3-cli sodium claim
verified externally (official image layer: `docker-php-ext-enable sodium`).

Guard run: `--filter 'BootstrapLaunchFormatConstants|DocFigureProseDrift'` →
109 tests, 1 FAILURE —
`DocFigureProseDriftTest::testAgentsAuthoringToolClaimsSurviveTheBuiltInDirectory`
("spelled count 'thirty-seven' is outside the pinned word map", :5425).
PROVEN INHERITED: reproduced identically at base with my CI.md byte-swapped
back to HEAD (arm reads docs/AGENTS_AUTHORING.md + src/Tools/BuiltIn only,
never CI.md). Fix belongs to the lane that grew the built-in directory;
left untouched per file-ownership law. Disclose for weld/gate.
