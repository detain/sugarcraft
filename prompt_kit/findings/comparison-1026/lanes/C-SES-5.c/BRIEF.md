### C-SES-5.c — Workflows: release pipelines sign the sidecar
- item: C-SES-5 (sources: DST-3-P1 (signing half), DST-3-P5 (release-note verify line))
- kind: fix · MAJOR · effort S
- approach:
  - **The added signature.** In all three phar pipelines, move the checksum step BEFORE signing. Signing then additionally signs the `.sha256` sidecar's body into `<app>.phar.sha256.sig`, which is uploaded as an asset.
  - **Additive.** The legacy `.minisig` stays: candy-top and older candy-code releases verify it.
  - **The verify line.** Fix `phar-dev.yml:306`'s release-notes "verify" line. Today it tells users `sha256sum -c *.minisig.sha256`, which proves nothing about authenticity.
- files.create: none
- files.modify: `.github/workflows/release-phar.yml` (sign, checksum, release-asset steps), `.github/workflows/phar-dev.yml` (sign, checksum, notes, asset steps), `.github/workflows/release-app-phar.yml` (sign, checksum, asset steps)
- tests: none (workflow-only; C-SES-9.d's interpolation guard must stay green)
- docs/guards: none
- serialized: [workflows]
- deps: C-SES-9.d (the same `run:` blocks are rewritten there first)
- phase: normal
- ruling: none
- risks: In keyless state (no `SUGARCRAFT_SIGNING_KEY`), both signature steps must skip green together.

