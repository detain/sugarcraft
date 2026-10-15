# Caliber Learnings

Accumulated patterns and anti-patterns from development sessions.
Auto-managed by [caliber](https://github.com/caliber-ai-org/ai-setup) — do not edit manually.

- **[pattern:cli-shim-plus-seam]** The bin is an autoload probe + `exit(Cli::main($argv, STDOUT, STDERR))` — every decision (usage exits, preset token, decode-failure copy, viewport sizing) lives in `src/Cli.php` so PHPUnit can drive it in-process. The `?\Closure $play` seam swaps the blocking `Program::run()` for a callback that receives the wired `Player`; pins assert `$player->preset`/`->frames`/`->renderer` without a terminal.

- **[pattern:descriptor-one-literal]** `SizeIoctl::query(1)` must spell STDOUT's descriptor as the literal — `(int) STDOUT` is the resource id (2 = STDERR on a CLI process). `stream_isatty(STDOUT)` (core) gates the query so the app needs no ext-posix. Pinned structurally by `tests/BinSizeQueryPolarityTest.php` (token-stream scan over bin AND Cli.php — text scans would accuse the docblocks that quote the bug).

- candy-gifview was split out of candy-flip at rename campaign step 1.3: it carries the viewer bin, the `cli.*` locale keys (16 locales, namespace `gifview`), and `BinSizeQueryPolarityTest`. GIF decode/playback classes stay in candy-flip — do not duplicate them here.
