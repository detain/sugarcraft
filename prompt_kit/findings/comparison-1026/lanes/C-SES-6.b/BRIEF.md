### C-SES-6.b — Reproducible phar build + package manifest
- item: C-SES-6 (sources: p2-miss: reproducible phar builds)
- kind: gap · effort S
- approach:
  - **The gap.** `release-phar.yml:6-15` discloses lock-less `dev-master` resolution, and phar entries carry checkout-time mtimes.
  - **Fix.**
    - Before adding files (`:224` sorts them; `:270` adds them), `touch()` every packaged file to `SOURCE_DATE_EPOCH` when it is set.
    - Record `name => reference` for every package in `vendor/composer/installed.json` into the phar metadata under `packages`.
    - A new `--manifest-out=PATH` writes the same map as JSON.
- files.create: none
- files.modify: `candy-code/tools/build-phar.php` (option parse, file loop, setMetadata)
- tests: modify `candy-code/tests/Integration/PharCompatTest.php`: a metadata `packages` arm on the scratch phar; two builds with the same `SOURCE_DATE_EPOCH` hash equal on the miniature arm.
- docs/guards: none
- serialized: none
- deps: C-SES-9.c (the same file)
- phase: normal
- ruling: none
- risks: Touching files in a checkout changes mtimes for later git operations. Build from a scratch copy, or document that the build runs in CI only.

