# E729 — candy-vt dual-stack unification: design note (lane r8, round 83)

**Status: DESIGN ONLY. Zero implementation.** This note answers the "design note first"
half of §E729's acceptance; the orchestrator ruling on its recommendation is the
second half. E729 stays OPEN until the ruling lands.

All surface facts below were re-measured at lane base `9f731a3d5`.

## 1. What actually exists (measured)

candy-vt ships two COMPLETE, independently written emulator stacks sharing the
candy-ansi VT500 state machine (de-forked onto it by `9952e3f5c`):

| | Renderer path (root namespace) | Emulator path (sub-namespaces) |
|---|---|---|
| Cell | `src/Cell.php` (71L, 5 public) — char + fg/bg as **0–255 palette index** + attrs bitfield | `src/Cell/Cell.php` (131L, 6 public) — full SGR: RGB truecolor, blink/dim/hidden, dedicated combining-mark field |
| Grid | `src/CellGrid.php` (129L, **7 public**) — mutable grid **with dirty-region tracking** | `src/Buffer/Buffer.php` (107L, 6 public) — rows×cols with **BCE erases**, tab stops |
| Facade | `src/Terminal.php` (116L, **8 public**) — **immutable**: `feed(bytes): self`, `snapshot(?time): Snapshot`, `grid()` | `src/Terminal/Terminal.php` (318L, **23 public**) — **mutable**: `feed(): void`, `flush()`, alt-screen trio, `resize`, `create()`, `with*()` builders |
| CSI handling | `src/Parser/CsiHandlerImpl.php` (**849L**) + HandlerAdapter + OscHandlerImpl | `src/Handler/` — 8 handlers (Screen/Cursor/Erase/Mode/Osc/Scroll/Sgr/Tab) + `src/Screen/` (Screen, Scrollback ring 1000) |
| Extra VO | `src/Snapshot.php` (45L, 3 public) — per-frame value object | Screen immutable snapshot + Mode/Hyperlink/Cursor objects |

Method surface counted `public function` per class: renderer side 5+7+8+3 =
**23 public methods across four root classes** (the backlog-era "21 vs 23"
framing undercounts Cell; the honest pair is 23 vs 35 for Cell/CellGrid/
Terminal/Snapshot vs Cell/Buffer/Terminal alone — the emulator family is much
wider once Screen/Scrollback/Cursor/Sgr/Mode count in).

## 2. Who eats which stack (measured — this is the E729 ERRATUM's core)

- **Renderer stack → candy-vcr PRODUCTION**: `src/Render/Renderer.php:8`,
  `src/Render/FrameStream.php:14`, `src/Render/FrameDedup.php`,
  `src/Encode/TapeToGif.php:18`, `src/Cli/InspectCommand.php:14`, `src/Raster/*`
  — every import is `use SugarCraft\Vt\Terminal;`. The GIF rasterizer consumes
  palette-indexed cells; FrameDedup consumes dirty-region deltas.
- **Emulator stack → candy-vcr `src/Assert/ScreenAssertion.php:7`**
  (`use SugarCraft\Vt\Terminal\Terminal;`), ~50 candy-vt tests, and
  candy-pty `tests/Integration/VimSmokeTest.php:10`.
- **sugar-crush → NEITHER.** `grep -rn 'SugarCraft\\Vt' sugar-crush/` = **0**.
  The backlog §E729 acceptance clause "all downstream consumers (sugar-crush
  cell-grid tests via `SugarCraft\Vt\Terminal`)" was a FALSE premise; erratum
  appended in place (E729 stays OPEN).

## 3. The parity bridge — an oracle that already exists

`candy-vcr/tests/VtParityTest.php` (767L) feeds the SAME recorded cassette bytes
through both stacks and asserts agreement over a shared NORMALISED grid (char,
palette index, the five renderer attribute bits). Its header records the
convergence history: #1417 corrected the EMULATOR semantics (DECAWM, deferred
wrap, RIS/DECSTR, IL/DL, SCS); the parity track then brought the RENDERER
(`Parser\CsiHandlerImpl`) to the same behavior. What remains divergent is
**representation width, not semantics**, and every gap is enumerated and pinned:
truecolor storage, blink/dim/hidden, SGR 58/59, tab-stop motion vs advance,
combining marks, REP, BCE erases, the `CSI 0 L/M` adapter clamp.

Consequence for any unification: **VtParityTest is a ready-made equivalence
oracle** — the migration check for strategy B or C is largely "delete the
normaliser, compare directly".

## 4. Candidate strategies

### A. Emulator-adopts-renderer (CellGrid wins) — REJECT
The renderer cell model (palette index, 5 attr bits) cannot express what the
emulator already stores (truecolor, combining marks, blink/dim/hidden). The
emulator's own suite and ScreenAssertion depend on those. This is a feature
loss disguised as unification; candy-vt's upstream-parity port target
(`charmbracelet/x/vt`-style emulator) would regress.

### B. Renderer-adopts-emulator (Cell/Buffer wins; root stack becomes a façade) — the only viable unification
Reimplement root `Terminal` on top of `Terminal\Terminal`: `feed(): self` =
clone + in-place feed; `grid(): CellGrid` = lossy Buffer→CellGrid projection
(RGB→nearest palette index, attr-bit mask); `snapshot()` = project current
Screen. Delete `Parser/CsiHandlerImpl.php` (849L) + HandlerAdapter trio.
- **Cost: XL.** candy-vcr's production hot paths (FrameStream per-frame feed,
  FrameDedup dirty regions) sit on CellGrid's dirty-region tracking, which the
  Buffer lacks — either port dirty tracking into Buffer (touches every write)
  or have the projection diff whole grids per frame (defeats vcr's dedup).
  Raster profiles, golden bytes, and the 46 `@`-suppressed vcr surfaces all
  re-verify. Public API CAN stay signature-stable (façade), but every pinned
  REPRESENTATION gap (BCE vs blank-fill, tab motion, REP) silently flips to
  emulator behavior — vcr goldens must be re-recorded, i.e. intentional
  width choices get overridden by fiat.
- **Benefit:** −849L duplicate handler stack, one semantics to maintain,
  parity test simplifies to projection-fidelity pins.

### C. Common-core + two adapters — best end-state, XL+ cost
One engine (cell model + grid + dirty tracking + handlers) with the immutable
snapshot façade and the streaming façade both as thin projections. This is the
"unified VT engine refactor" VtParityTest's header already refers to. Highest
purity, largest blast radius on BOTH downstream suites simultaneously.

## 5. Re-pin implications (campaign domain)

- candy-vt suite (607T weld figure, r82) and candy-vcr suite (≈890T, r83) are
  BOTH sensitive — sibling-lib gate = full suite of every touched lib
  (RESUME §1b).
- **sugar-crush is UNAFFECTED** (zero references): no suite-figure.json /
  README headline / durations re-pin; the weld serial stays a negative control.
- DocFigure/campaign census: this doc adds NO backlog rows; §2(a) census
  unchanged at 7 survivors.

## 6. Confronting the documented-intentional split (README:85-107)

`candy-vt/README.md` "Two Terminal classes" states the split is deliberate: the
root Terminal is *immutable fluent, produces Snapshot value objects, optimized
for per-frame snapshots*; `Terminal\Terminal` *mutates in place, optimized for
byte-stream ingest*. Measured against code: **the rationale is TRUE, not
vestigial.** vcr's GIF pipeline genuinely wants `feed→Snapshot(time)` value
semantics per frame (it retains snapshots across time); an interactive emulator
genuinely must not pay per-frame clone costs. The split is not an accident that
survived refactoring — it is a real impedance difference between a recorder and
an emulator. What IS duplicated is the CSI **handling** (849L CsiHandlerImpl vs
8-file Handler/ stack), and the parity header proves the team already paid the
drift-insurance premium once (#1417 + the parity track) and now carries the
guard for free.

## 7. Recommendation

**DECLINE unification for now (E729 → CLOSED-declined on ruling), pre-1.0.**
Reasons: (1) the two façades encode a genuine use-case split the README
justifies and the code confirms; (2) semantic convergence — the actual hazard
of duplication — is ALREADY achieved and ENFORCED by VtParityTest, so the
residual cost is code volume (~850L), not correctness drift; (3) both viable
strategies are XL with forced re-derivation of vcr goldens, on a port lib,
while the repo's remaining actionable queue is thin; (4) the campaign's own
STOP bias ("do not bias toward action") applies squarely.

**Reopen triggers** (cheap to watch): a future audit finding that one engine's
CSI semantics diverged from the other's (i.e. VtParityTest had to change
behavior on one side), or a vcr feature that needs truecolor/combining storage
in the renderer path. If a trigger fires, prototype **B's first slice** only:
a `Buffer→CellGrid` projection + parity-direct comparison behind the existing
oracle, before committing to deleting CsiHandlerImpl.
