# Visual regression golden manifest

Section G of `vcr_use_findings.md`. Each tape under `tapes/` exercises one
distinct dimension of the rasterizer/encoder pipeline. Goldens render to
`<name>.php.gif` (byte-stable, PhpGifEncoder) and `<name>.ffmpeg.gif`
(SSIM-compared, FfmpegGifEncoder).

## Render command

Every golden is rendered through `TapeToGif::create()` with the default
font (`JetBrainsMono` resolved by `FontLoader`), `fps = 30.0`, and
`fontSize = 14`. Dimensions / theme / typing speed all come from inside
the tape via `Set` directives — no CLI overrides. Equivalent to:

```sh
php bin/candy-vcr render-tape tests/golden/tapes/<name>.tape \
    -o tests/golden/<name>.<encoder>.gif --encoder <encoder>
```

To refresh every golden:

```sh
php scripts/refresh-goldens.php           # safe (warns when >3 change)
php scripts/refresh-goldens.php --force   # bypass the >3 guard
php scripts/refresh-goldens.php --dry-run # report diffs only
```

## Tapes (one per criterion)

| Tape                          | Criterion                                  |
| ----------------------------- | ------------------------------------------ |
| `01-tokyo-night.tape`         | TokyoNight theme                           |
| `02-dracula.tape`             | Dracula theme (non-TokyoNight palette)     |
| `03-plain-typing.tape`        | Plain types-and-enter                      |
| `04-sleep-heavy.tape`         | Sleep-heavy — multiple long `Sleep`s       |
| `05-ctrl-sequence.tape`       | Ctrl-sequence — `Ctrl+C` and `Ctrl+D`      |
| `06-arrow-keys.tape`          | Arrow keys — `Up`/`Down`/`Left`/`Right`    |
| `07-cjk-wide.tape`            | Wide CJK type — `Type "日本語"`              |
| `08-custom-dims.tape`         | Custom `Set Width 100` / `Set Height 12`   |
| `09-animation.tape`           | Multi-frame animation (staggered typing)   |
| `10-idle-rich.tape`           | Idle-rich — `Type` / long `Sleep` / `Type` |

All ten tapes were authored fresh for this section — no reuse from
`<slug>/.vhs/*.tape`, because those tapes typically reference
`examples/<demo>.php` files outside `candy-vcr/`. Authored tapes give
us tight control over content and let each golden stay under ~60 KB.

## Determinism

`PhpGifEncoder` is byte-deterministic for these inputs: rendering the
same tape twice produces an identical SHA-256 hash. `FfmpegGifEncoder`
is also deterministic in this environment (ffmpeg 6.1.1, palettegen +
paletteuse give bit-identical results across runs), but the
`VisualRegressionTest` allows up to a small SSIM tolerance for the
ffmpeg goldens in case the underlying ffmpeg binary version drifts on
a different runner.

## SSIM threshold

`VisualRegressionTest` asserts SSIM `>= 0.95` for the ffmpeg goldens,
matching `vcr_use.md` §8 ("SSIM threshold if we need fuzziness"). The
test is auto-skipped when ffmpeg is not on `PATH`.
