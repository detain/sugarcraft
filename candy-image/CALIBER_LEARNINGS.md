# Caliber Learnings

Accumulated patterns and anti-patterns from development sessions.
Auto-managed by [caliber](https://github.com/caliber-ai-org/ai-setup) — do not edit manually.

- **[pattern:cli-shim-plus-seam]** candy-image keeps candy-gifview's harness shape: the bin is an autoload probe + `exit(Cli::main($argv, STDOUT, STDERR))`; a `?\Closure $play` seam swaps the blocking animation `Program::run()` for a callback that receives the wired `Player`, so argv handling, refusal exits, and the animate wiring are all pinned in-process with `php://memory` streams.

- **[pattern:refuse-with-provenance]** A viewer over a moving lib must not fake flags. chafa spellings whose backing seam does not exist on the base (--symbols/S6.2, --passthrough=screen/S7.1, --probe=off) exit 1 with a message naming the missing step; --format=chafa exits 1 by *design* (F-18 is the chafa-independence item). Accepted-without-effect is reserved for honest no-ops (--polite: the SC render path is already inline/synchronous) and is tabled in the README compat map.

- **[decision:namespace-of-candy-image]** The mechanical suffix rule would give `SugarCraft\Image\` — already owned by the sugar-image library. The app keeps `SugarCraft\CandyImage\` and the collision ruling is recorded in `PROJECT_NAMES.md`, not silently.
