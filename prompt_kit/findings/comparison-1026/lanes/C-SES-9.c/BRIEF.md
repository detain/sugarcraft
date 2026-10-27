### C-SES-9.c — Phar installs ship bundled presets; build-phar docblocks truthful
- item: C-SES-9 (sources: DST-1-P2, DST-1-P3)
- kind: dormant-wire · MINOR · effort S
- approach:
  - **Root cause.** `AgentPresetRegistry::bundledPresetsDirectory()` calls `realpath()` on a `phar://` path, which returns false, so the method answers null (`src/Agents/AgentPresetRegistry.php:390-395`). `isBundledPresetPath()` compares realpaths the same way (`:403-412`). The phar packs `.candy-code/agents` (`tools/build-phar.php:172`), yet ships no presets.
  - **Fix.** When `str_starts_with(__DIR__, 'phar://')`, return the un-realpath'd `phar://…/.candy-code/agents` if `is_dir()` holds, and compare by normalized prefix.
  - **Docblocks.** In `tools/build-phar.php`, delete the "KNOWN PHAR DEGRADATION" disclosure (`:89-97`) and correct the weight docblock (`:41-44`) to the measured ~118 MiB, pointing at `--gz`.
- files.create: none
- files.modify: `candy-code/src/Agents/AgentPresetRegistry.php` (bundledPresetsDirectory(), isBundledPresetPath()), `candy-code/tools/build-phar.php` (docblocks only)
- tests: modify `candy-code/tests/Agents/AgentPresetRegistryTest.php`: an arm over a scratch `phar://` archive containing `.candy-code/agents/*.md`, built the `PharCompatTest` `writePremisePhar` way in a `-d phar.readonly=0` child, lists the bundled presets.
- docs/guards: none
- serialized: none
- deps: none
- phase: normal
- ruling: none
- risks: The phar-building test arm needs `phar.readonly=0` in a child PHP; skip it when `PHP_BINARY` cannot be spawned.

