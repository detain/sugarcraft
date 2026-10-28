# C-TUI-7.a — Round 3 Review (fix tip fa64a39b9 atop e003ffa15; base b38baf0f8)

VERDICT: CLEAN — 0C/0M, zero step-local minors. Round-1 MAJOR root-caused and pinned.

## (1) Fix diff e003ffa15..fa64a39b9 — exactly 4 declared files
src/Support/StartupSummary.php (deriveGatherKey = sessionId\0root; render lookup :116 + store write :154 share it; $cache memo keeps full deriveKey so mode/provider/model repaints stay correct; store invalidation = every frame with prefix bagKey."\0"), HandlesRunControl.php comment-only, StartupSummaryGatherTest.php keystone roster += deriveGatherKey, StartupSummaryTest.php new pin. Docblock truths flipped at $gathered/render/store — honest.
Sibling-isolation walk: frame key = sess\0root\0mode\0provider\0model (4 separators), so bagKey+"\0" is an exact boundary match. PHP filesystem layer rejects NUL in paths (projectRoot cannot contain \0) and sessionId is \0-free; a frame of another session+root can never satisfy str_starts_with. Frames where mode/provider/model are all empty still carry the trailing "\0" — no orphan-key leak. The trio (deriveGatherKey write/read/invalidate) is internally closed: $gathered has exactly two consumers (grep-verified :116/:154); sole store caller is the HandlesRunControl:289 arm; sole gatherCmd producers Chat.php:1928 (init) + :11862 (Clear/Switch arms) — unchanged, R1-verified.

## (2) Pin non-vacuous — M1/M3 re-reasoned
M1 (lookup→full $key): store's bag-key write and render's full-key read never match (bag key is a strict prefix, keys unequal) → bag invisible forever → new pin's "landed bag renders full rows" leg + the gather test's behavioural full-branch pins red (~4, matches FIX-1). M3 (invalidate→single current-full-key unset): new pin memoizes a Default-mode frame pre-flip, re-lands epsilon under Plan, flips back → under M3 the stale Default frame serves 'delta' → assertStringContains('epsilon') AND assertStringNotContains('delta') red in exactly the new pin; all pre-fix-era tests stay green (they never re-store after a flip) — the pin is the unique discriminator. Pin also carries the survival leg that fails outright under the pre-fix full-key bag (cheap-only paint after toggle), so it is a true regression pin, not decoration. uniqid root isolates the static caches across tests.

## (3) Full-lane recheck
b38baf0f8..fa64a39b9 = 14 files, identical to R1-accepted declared set (+ GatheredMsg create + RouteTraitParity roster + census/behaviour test flips). No Renderer.php, no suite-figure.json/durations.tsv/dist. Both commits %ae and %ce detain@interserver.net on separate greps, zero trailers. Tree clean after gates (git status empty).

## (4) Gates re-run (cwd candy-code, -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe)
- --filter 'StartupSummary' → "Tests: 24, Assertions: 475" OK (exact).
- --filter 'StartupSummaryGatherTest|RouteTraitParity|AppModel' → "Tests: 71, Assertions: 820" OK (exact).
- Exit 0 both. Note (foreign, NOT a lane finding): each run carries "PHPUnit Deprecations: 2" from tests/Config/AccessorDeprecationTest non-public data providers (rulings-era file, zero lane diff lines) reaching the filter via StartupSummary-named dataset cases — weld glance only.

## Weld roster (per brief, not findings)
durations.tsv row StartupSummaryGatherTest.php owed; RouteTraitParity union hunk vs master 27757dd94 (ModelCatalogReadyMsg + master TelemetryRetryTickMsg kept, lane's StartupSummaryGatheredMsg after StatusLineTickMsg) resolved by welder; ReadmeSuiteFigureDrift staleness pair = weld re-pin.
