# candy-top logo campaign brief

The candy-top app logo is drawn by `candy-top/src/View/Banner.php`: the text
"CANDY TOP" in the "ANSI Shadow" block face (70 cols x 6 rows), solid `█` cells
in a red that darkens row by row (#E62525 → #801414), box-drawing shadow strokes
in a darkening grey. It sits centred at the top of btop-style panel frames:

```
╭─┐¹cpu┌──┐menu┌┐preset ██████╗r█████╗─███╗───██╗██████╗2██╗3:3██╗─████████╗─██████╗─██████╗────────────┐- 2000ms +┌─╮
│                      ██╔════╝██╔══██╗████╗  ██║██╔══██╗╚██╗-██╔╝─╚══██╔══╝██╔═══██╗██╔══██╗──────────────┐3.9 GHz┌╮│
│                      ██║     ███████║██╔██╗ ██║██║■■██║■╚████╔╝■■■■■██║■■■██║■■■██║██████╔╝■■■■■■   9% ⣀⣀⣀⣀⣀  46°C││
│                      ██║     ██╔══██║██║╚██╗██║██║1%██║7°╚██╔╝  0%  ██║C│C██║ 5%██║██╔═══╝  0%  37°C│C32  0%  37°C││
│                      ╚██████╗██║  ██║██║ ╚████║██████╔╝4°C██║   1%  ██║C│C╚██████╔╝██║│C25  1%  34°C│C33  0%  34°C││
│                       ╚═════╝╚═╝  ╚═╝╚═╝  ╚═══╝╚═════╝35°C╚═╝0100%  ╚═╝C│C1╚═════╝3╚═╝│C26  1%  35°C│C34  0%  35°C││
│                  ╭─┐tab→┌─────────────────────────────────────────────────────────────────────╮ 35°C│C35  1%  35°C││
│                  │   [general]       1cpu        2mem        3net        4proc        5gpu    │ 37°C│C36  0%  37°C││
│                  ├─────────────────────────────┬──────────────────────────────────────────────┤ 34°C│C37  0%  34°C││
```

Goal: a NEW replacement logo/name for "candy-top" (must spell candy-top / CANDY TOP
legibly) in TRUE COLOR ANSI — more artistic, styled, colorful. Keep it a size that
can plausibly sit atop a terminal UI (roughly ≤ 80 cols wide, ≤ 10 rows tall is a
good target; the current one is 70x6).

## Output rules (all paths relative to /home/sites/sugarcraft/candy-top/.assets/logos/)

- Files: `logo-<slug>-<colors>-<width>x<height>.ansi`, `<colors>` ∈ `tc` | `256` | `16`.
  width/height = visible cell columns x rows.
- Make the `tc` (24-bit, `\e[38;2;r;g;bm` / `\e[48;2;...m`) version first, VIEW it
  (`cat` it; also verify every line's visible width with a script — lines must
  line up, no ragged edges, reset `\e[0m` at end of each line), then derive a
  256-color (`38;5;n`) version and a 16-color (30-37/90-97, 40-47/100-107) version.
- Append ONE JSON line per file to `logos.jsonl` (same dir), fields:
  `{"filename":..,"slug":..,"colors":"tc|256|16","width":N,"height":N,"description":"..","tags":["slug-style-tag",...]}`
  (a handful of kebab-case tags describing that logo's particulars). Append with
  `>>` in a single write per line — other agents append to the same file.
- Animated ANSI is allowed but must finish displaying in ≤ 5 seconds total.
- Tools/scripts go in `tools/` (same dir). Write them to be reusable: palette and
  design (glyph art / masks) as swappable data, colour-depth downgrade (tc→256→16)
  as a reusable step, width verification built in.
- Do NOT touch anything outside `candy-top/.assets/logos/` (the repo has unrelated
  uncommitted work). Do not git commit.
