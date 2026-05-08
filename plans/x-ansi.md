# Plan: rolling `Util\Ansi` extensions (`x/ansi`)

## Goal

Keep extending `candy-core/src/Util/Ansi.php` opportunistically as
downstream libs need new sequences, *without* extracting it into a
separate `candy-ansi` lib until a clear external consumer demands it.

## Why not extract now

- Current size: 499 lines. Manageable.
- Only consumers are candy-core internals (Renderer, Cursor, Cmd helpers)
- No third-party PHP project has asked to depend on it standalone
- Extraction cost (new repo skeleton, composer wiring, dep updates across 40+ libs) outweighs value at this point

We re-evaluate after `x-vt`, `x-mosaic`, and `sugar-spark` land — those
are the libs most likely to want a public ANSI tooling surface. If two
or more of them grow private re-implementations, that's the signal to
extract.

## Gap analysis vs `charmbracelet/x/ansi`

| Surface | candy-core today | Upstream | Action |
|---|---|---|---|
| SGR encoding (fg/bg/attr) | ✅ | ✅ | none |
| CSI cursor moves (A/B/C/D/H/f) | ✅ | ✅ | none |
| OSC 0/1/2 (window/icon title) | ✅ | ✅ | none |
| OSC 4 (palette set) | 🟡 | ✅ | add for x-vt |
| OSC 8 (hyperlink) | ✅ (in candy-shine) | ✅ | move into Util/Ansi |
| OSC 52 (clipboard) | ✅ | ✅ | none |
| OSC 1337 (iTerm2 inline image) | 🔴 | ✅ | add for x-mosaic |
| Sixel header / data / terminator | 🔴 | ✅ | add for x-mosaic |
| Kitty graphics protocol (APC chunks) | 🔴 | ✅ | add for x-mosaic |
| WezTerm imgcat passthrough | 🔴 | ✅ | add for x-mosaic |
| Kitty keyboard push/pop/request | ✅ | ✅ | none |
| DEC private modes (full set) | 🟡 | ✅ | extend for x-vt |
| DCS string encoding | 🔴 | ✅ | add for x-vt |
| APC string encoding | 🟡 (via Kitty kbd) | ✅ | generalize for x-vt |
| Full ANSI parser (state machine) | 🟡 (`Util/Parser.php` is bubbletea-tuned) | ✅ | x-vt builds the full parser |

## Action items

Each item is a small standalone PR (~20-60 lines + tests). Land each
inside the relevant downstream lib's PR rather than as a dedicated
ansi-only PR.

### A1 — Sixel encoding helpers (lands with x-mosaic)

```php
public static function sixelDcsHeader(int $aspect = 1, int $bgMode = 1, int $hScale = 1): string;
public static function sixelData(string $bytes): string;
public static function sixelTerminator(): string;
public static function sixelColorIntroducer(int $idx, int $r, int $g, int $b): string;
```

DCS-introduced; payload is sixel-encoded pixels (6 vertical pixels per
char, 0x3F-base ASCII). Encoder logic is mosaic's job; these helpers
just emit the wrapping DCS sequences.

### A2 — Kitty graphics protocol (lands with x-mosaic)

```php
public static function kittyGraphicsBegin(array $opts): string;  # APC G a=T,f=100,...
public static function kittyGraphicsChunk(string $base64, bool $more): string;
public static function kittyGraphicsEnd(): string;
public static function kittyGraphicsClear(int $imageId = 0): string;
```

Chunked APC sequences. `$opts` keys: `a` (action), `f` (format), `s/v`
(width/height), `i` (id), `q` (quiet).

### A3 — iTerm2 / WezTerm inline image (lands with x-mosaic)

```php
public static function iterm2InlineImage(string $base64Png, array $opts = []): string;
```

OSC 1337 with `File=<base64>;width=Npx;height=Npx;...`. WezTerm
reuses this OSC unchanged.

### A4 — DEC private modes (lands with x-vt)

Add named constants for every mode the bubbletea/lipgloss/glamour stack
emits but we currently emit as raw integers:

```php
public const DECCKM      = 1;     # cursor keys application mode
public const DECAWM      = 7;     # auto-wrap
public const MOUSE_NORMAL = 1000;
public const MOUSE_BUTTON = 1002;
public const MOUSE_ANY    = 1003;
public const MOUSE_SGR    = 1006;
public const ALT_SCREEN_BUFFER = 1049;
public const BRACKETED_PASTE = 2004;
public const SYNCHRONIZED_OUTPUT = 2026;
```

Plus encoder helpers: `Ansi::decSet(int $mode)` / `Ansi::decReset(int $mode)`.

### A5 — DCS string encoding (lands with x-vt)

```php
public static function dcs(string $payload): string;
public static function apc(string $payload): string;
public static function pm (string $payload): string;
```

Plain wrappers — `\x1bP` … `\x1b\\`. Used by mosaic + vt.

### A6 — Move OSC 8 helpers from candy-shine to Util/Ansi (deferred until candy-shine refactor wave)

```php
public static function hyperlinkOpen(string $uri, ?string $id = null): string;
public static function hyperlinkClose(): string;
```

Currently inlined in `candy-shine/src/Renderer.php`. Move to
`Util/Ansi` so `Util\Open` and any other consumer can use them.
Replace the candy-shine inlining with `Ansi::hyperlinkOpen($uri)`.
Backwards-compat: candy-shine's existing static helper delegates to
the new Util/Ansi method.

## Test strategy

- Each helper gets a snapshot test asserting the exact byte sequence
- Tests live under `tests/Util/AnsiTest.php` (existing); grouped into
  `dataProvider` blocks per item (A1, A2, ...)
- Cross-reference: each test method docblock cites the upstream
  charmbracelet/x source file + line that defines the canonical bytes

## Effort

Rolling. Per item:

| Item | Effort | Lands with |
|---|---|---|
| A1 Sixel | ~2h | x-mosaic |
| A2 Kitty | ~2h | x-mosaic |
| A3 iTerm2 | ~1h | x-mosaic |
| A4 DEC modes | ~2h | x-vt |
| A5 DCS/APC/PM | ~1h | x-vt |
| A6 OSC 8 move | ~2h | candy-shine refactor (separate) |

## Dependencies

- A1, A2, A3 are blockers for [x-mosaic](./x-mosaic.md)
- A4, A5 are blockers for [x-vt](./x-vt.md)
- A6 is independent

## Tracking

- `MATCHUPS.md` — no row (Util\Ansi is a candy-core internal)
- `UPSTREAM_OPPORTUNITIES.md` — flip `x/ansi` row to 🟡; never goes 🟢
  unless we extract
- `candy-core/CALIBER_LEARNINGS.md` — note the gap-analysis table here
  so future contributors know what's intentionally missing
