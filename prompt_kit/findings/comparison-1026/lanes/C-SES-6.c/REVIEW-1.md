# C-SES-6.c REVIEW-1 (r1, tip 38ae2f185, base cf0cfd0be)

VERDICT: FINDINGS — 1 MAJOR, 2 NIT.

Scope: numstat re-derived on disk = EXACTLY 6 files +1345/−0 (README, install.sh,
gen-homebrew-formula.php, rb.tmpl, 2 tests) — the spawn brief's "36 files" claim is
FALSE-to-disk; every file is on the design list; no dist/generated.ts/suite-figure/
durations in the commit. Modes 100755/0644 correct.

MAJOR-1 (step-local): §1.8 in-step guard law broken — `TreeWideGuardRosterTest` reds
2F at the tip (reproduced here; outside the lane's 197T filter set, so the builder's
gate missed it): `Cli/HomebrewFormulaTest.php => glob($this->root.'/*')` and
`Cli/InstallScriptTest.php => RecursiveDirectoryIterator($path,\FilesystemIterator::SKIP_DOTS)`
are unresolved walker sites with no license. Fix: add both file+expression rows to
WALKS_A_DIRECTORY_THE_TEST_MADE (teardown cleanup of test-made fixture trees; lane-fg
precedent, alphabetical), re-run guard green in the same commit.

NIT-1: install.sh:308 GitHub-leg curl lacks `--` before header-derived "$next"
(BASE_URL leg guards '-' prefixes at :132); value is GitHub-controlled over TLS —
defense-in-depth only. NIT-2: README:157 "no environment variables" overstates —
$HOME (:150) and $PATH (:446) are read by design; suggest "no configuration env vars".

Security re-derived clean: sig-then-digest-then-install order (:404→:430→:440, staged
mv last); pinned key literal byte-equals SelfUpdate::PUBLIC_KEY_BASE64:234 and is
test-pinned; verify.php mirrors verifyDetached (strict b64, 64/32-byte lengths, exact
payload); minisig-over-phar mirrors SelfUpdate legacy leg (memory-limit raised same
role); refusal tests assert exact leg messages + non-existence of the install — each
dies loudly under leg-removal; tampered-sidecar cannot pass via digest forgery behind
minisig (pinned-key leg). file:// --base-url swaps only the list origin; asset URLs
used-as-given in BOTH modes, verification identical — real paths not weakened.
Generator fail-closed 8 garbage cases write nothing; §6.1 honored: renderer only, no
tap/push. getenv/CANDY_CODE_/SUGARCRUSH_ census: zero (grep + pinned test).

Gate reproduced: 197 tests / 55,444 assertions OK (plain pipe, env -u LINES -u
COLUMNS); 20 own tests (13+7 — REPORT's "12" is off by one); the 2 PHPUnit
Deprecations ride the filter-classes run too (global, not lane). Non-attributable:
ForkedChildReaperAdoptionTest 1F names Sessions/BackgroundShellRegistryTest.php
(pre-existing at base); ReadmeSuiteFigureDrift staleness pair inherited. Durations
rows ×2 correctly listed as GATE duty per §1.5. Phantom law: branch+ref+tree all
verified on disk.
