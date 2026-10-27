### C-SES-9.d — Release workflows pass tags/inputs via `env:`
- item: C-SES-9 (sources: DST-1-P4)
- kind: fix · NIT · effort S
- approach:
  - **Root cause.** `${{ github.event.inputs.tag }}` and `${{ steps.*.outputs.* }}` are interpolated straight into `run:` scripts:
    - `release-phar.yml:50-51`, `:62`, `:88`, `:91`, `:206`;
    - `release-app-phar.yml:64-65`, `:97`, `:114`, `:122`, `:159`, `:182`, `:200-201`, `:221`, `:235-236`;
    - `release-candy-top-phar.yml:60-61`, `:72`, `:213`.
    
    A ref name containing `'` breaks out of the script.
  - **Fix.** Move each value into the step's `env:` (`TAG`, `APP`, `EVENT`) and reference `"$TAG"` in the script.
  - **Guard.** A root `tools/tests` guard fails on `${{ github.event.inputs.` or `${{ steps.` inside any `run:` body of the three files.
- files.create: `tools/tests/WorkflowRunInterpolationTest.php`
- files.modify: `.github/workflows/release-phar.yml` (tag, checkout, build, release steps), `.github/workflows/release-app-phar.yml` (scope, checkout, build, sign, checksum, release steps), `.github/workflows/release-candy-top-phar.yml` (tag, checkout, release steps)
- tests: create `tools/tests/WorkflowRunInterpolationTest.php`. It runs in the `tools-guards` job, not in the candy-code durations TSV.
- docs/guards: none
- serialized: [workflows]
- deps: none
- phase: normal
- ruling: none
- risks: A behaviour change shows only on a real tag. Check with `actionlint` if available, otherwise by review.

