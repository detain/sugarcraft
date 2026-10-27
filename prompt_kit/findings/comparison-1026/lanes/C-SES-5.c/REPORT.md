# C-SES-5.c — Workflows: release pipelines sign the sidecar

- Verdict: COMPLETE. Commit `205766e13` on branch `cc/C-SES-5.c` (base 2392ed1d2), 3 files, +97/−46.
- Author/committer `detain@interserver.net` verified via SEPARATE `%ae`/`%ce` greps; zero Co-Authored trailers; plain `-m`; never pushed/pulled; no caliber.

## What changed (all three phar pipelines: release-phar.yml, phar-dev.yml, release-app-phar.yml)

1. **Checksum moved BEFORE Sign** in all three (step bodies reordered, `if:` gates and env bindings preserved; C-SES-9.d's env-migration shape untouched — no new `${{ }}` pasted into any `run:` body).
2. **Sign step additionally signs the sidecar body**: base64 detached sodium signature over `<app>.phar.sha256` written to `<app>.phar.sha256.sig` — the asset current candy-code `self-update` (C-SES-5.b, `SIDECAR_SIGNATURE_ASSET`) verifies against the pinned public half.
3. **Legacy `.minisig` stays additive** (raw-phar-byte signature: candy-top and older candy-code verify it). Its companion `.minisig.sha256` leg moved into the sign step, since it can only be computed after the signature file it names exists.
4. **Asset lists** in all three publish steps append `<app>.phar.sha256.sig` inside the existing `[ -f minisig ]` gate — keyless state: the single early-exit sign step skips green and no signature file exists, so all signature legs skip together (the brief's risk line).
5. **phar-dev.yml:306 verify line fixed** (now the signed-notes sentence): the old `verify: sha256sum -c $app.phar.minisig.sha256` proved nothing about authenticity. The new line states the honest chain — check `<app>.phar.sha256.sig` (sodium detached over the sidecar body) against the public half pinned in the app's source, THEN `sha256sum -c <app>.phar.sha256`; explicitly names the legacy `.minisig` as integrity-only, never authenticity. Header asset-URL block updated to list the `.sha256.sig`.
6. release-app-phar.yml header signing paragraph truth-extended (sidecar signature named, `.minisig` additive).
7. release-phar.yml sign-step docblock truth-flipped: it claimed `self-update` REQUIRES the `.minisig` — stale post-C-SES-5.b; now describes the sidecar-signature leg as the one current clients verify, `.minisig` as the kept-legacy leg.
8. release-candy-top-phar.yml NOT touched (out of the brief's files list; verifies raw-byte minisig — unaffected by the additive change).

## Gates (all in the worktree, after the edit)

- `php candy-code/vendor/bin/phpunit --bootstrap tools/tests/_bootstrap.php --no-configuration tools/tests --filter WorkflowRunInterpolation` → **OK (3 tests, 134 assertions)** (green at base too).
- Full tools battery `--no-configuration tools/tests` → **OK (489 tests, 2578 assertions)** exit 0.
- YAML validity: `ruby -ryaml YAML.load_file` OK ×3; `python3 yaml.safe_load` OK ×3.
- Functional dry-run (temp dir): keyless state skips green with zero sig files; keyed state produces `.minisig` + `.sha256.sig` + `.minisig.sha256`; the sidecar sig verifies (`sodium_crypto_sign_verify_detached` → true) over a sidecar whose digest matches the built bytes; app-parameterized (`$APP`) leg and the publish-gate asset array + `%b` notes sentence all exercised.
- Step order asserted by grep: Checksum precedes Sign at 104/114 (release-phar), 229/240 (phar-dev), 170/184 (release-app-phar).

## Files changed

- `.github/workflows/release-phar.yml`
- `.github/workflows/phar-dev.yml`
- `.github/workflows/release-app-phar.yml`

No new test files (no durations TSV row owed); no `*Test.php`; suite-figure untouched; nothing outside the three ymls modified.
