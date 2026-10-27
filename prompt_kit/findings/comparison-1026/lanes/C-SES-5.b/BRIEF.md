### C-SES-5.b — Signature over the sha256 sidecar (stream-safe verify)
- item: C-SES-5 (sources: DST-3-P1, DST-3-P5 (minisig naming/format))
- kind: fix · MAJOR · effort M
- approach:
  - **Root cause.** `verifySignature()` reads the whole ~119 MiB phar with `file_get_contents($temp)` (`SelfUpdate.php:678`) to call `sodium_crypto_sign_verify_detached` (`:714`). That is fatal under the default 128M `memory_limit`.
  - **Fix.** Prefer a NEW release asset, `candy-code.phar.sha256.sig`: an Ed25519 signature over the sha256 sidecar's body.
    1. Verify that signature over the sidecar text, with the same pinned key.
    2. Keep the streaming `hash_file()` gate for the phar itself.
  - **Legacy.** Releases that carry only `.minisig` (signed over the raw bytes) fall back to the old path for one release cycle, raising `memory_limit` to the file size plus 64 MiB just for that leg, with a deprecation note on stderr.
  - **Docs.** State plainly that `.minisig` and `.sha256.sig` are raw base64 sodium signatures, not minisign format.
- files.create: none
- files.modify: `candy-code/src/Cli/SelfUpdate.php` (a new `SIDECAR_SIGNATURE_ASSET` const, releaseAssetUrls(), verifySignature()), `candy-code/lang/en.php` (`cli.selfupdate.sig*`), `candy-code/README.md` (the Install verify paragraph)
- tests: modify `candy-code/tests/Cli/SelfUpdateTest.php`:
  - the sidecar-signature happy path;
  - a tampered sidecar is refused;
  - a child `PHP_BINARY -d memory_limit=32M` swapping a 48 MB staged phar exits 0 on the new-asset path;
  - the legacy `.minisig` path still verifies.
- docs/guards: README.md only
- serialized: [lang:cli.selfupdate.sig] [docs:README.md]
- deps: C-SES-5.a
- phase: normal
- ruling: none
- risks: `candy-top/src/Cli/SelfUpdate.php` verifies raw-byte signatures too. C-SES-5.c keeps emitting `.minisig`, so candy-top is unaffected (see Notes).

