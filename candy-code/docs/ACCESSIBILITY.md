# Accessibility

CandyCode ships real accessibility machinery, not a promise: a screen-reader
presentation mode, a launch-only reduced-motion switch, an enforced contrast
floor on every colour the theme paints, and audible cues that ride the
notification stage. Each of those is described below with the file that
implements it, so the contract is checkable rather than aspirational.

There are two switches. The `screenReader` setting (see
[SETTINGS.md](SETTINGS.md)) is live and sheds presentation decoration for a
narrating reader while also taking the audible-cue half. The
`CANDY_CODE_REDUCED_MOTION` environment variable (see
[ENVIRONMENT.md](ENVIRONMENT.md)) is the launch-only motion half alone.

## Screen-reader mode (`screenReader`)

A screen reader narrates the characters a terminal holds, not their styling:
a reverse-video selection bar, a box-drawing frame and a cycling braille
spinner are all silence or noise to it. With the switch on, the TUI sheds
exactly that decoration. The read path is `ScreenReaderMode::enabled()` —
a thin door on `UiSettings::bool()`, which is memoised by the settings layer
and re-armed on every save, so the switch is live by construction: a save
applies on the next read, and nothing caches a second answer that could go
stale.

What the mode changes, and what it deliberately keeps:

- **Frames, rules and the diff gutter draw as blank paper.**
  `ScreenReaderMode::border()` replaces the thirteen border slots with
  spaces — but carries the border's titles verbatim, embedded ones
  included. The title text is the one part of a bordered box a reader
  *can* narrate, so it is exactly what survives the shed frame.
- **Box Drawing glyphs become spaces.** `ScreenReaderMode::glyph()` maps
  every code point of the Box Drawing block (U+2500–U+257F: the frame,
  divider and junction runes the renderer paints) to a space, so a
  line-drawn panel reaches the reader as content on blank paper instead of
  a rattle of strokes. Text, padding and other glyph blocks — arrows,
  checkmarks — pass through untouched; they carry meaning a reader can
  say.
- **Pane and dock icons drop.** `ScreenReaderMode::paneIcon()` answers the
  empty string: the icon names the pane no better than the text label
  beside it already does, so the reader is handed the label alone.
- **The list cursor is a plain `>`** instead of the house triangle
  (`ScreenReaderMode::cursor()`). Both are width 1, so the marker column
  does not shift when the mode flips mid-session.
- **Selected menu rows carry the selection as text** — a `>` marker and a
  position rendered as `n/of` in plain digits and one slash
  (`ScreenReaderMode::menuRow()`). Selection never rides reverse video
  alone. The position form is locale-neutral by design: no translation
  keys sit behind it.
- **The agent-live spinner pins to its first frame.** Every surface that
  draws the spinner indexes the glyph table through one gate
  (`AgentLiveRegistry::staticSpinner()`), so the animation stops on all of
  them at once. Elapsed-seconds movement still repaints — only the glyph
  stops advancing.

With the mode **off**, every one of these helpers is a byte-identical
pass-through door — the same string, the same border, unchanged — so the
plain renderer is exactly what ships when you never touch the switch.

## Audible cues

- **Each permission prompt sounds a BEL** beside the wait.
  `ScreenReaderMode::cue()` returns the BEL as a command (a `Cmd::raw()`
  closure the `Chat` update() arms return and the Program writes on their
  own turn) — never from `view()`, because rendering is pure and a BEL
  hidden in a painted frame would fire on every repaint diff. The cue is
  `null` when the mode is off, so an unwired call site is a no-op, not a
  stray byte.
- **The notification stage upgrades while the mode is on:**
  `TerminalNotifier::fromConfig()` turns a `notify: off` into `bell`, so
  turn ends and waiting approvals always sound and never the visual
  `osc9` popup. The upgrade is consulted per call and tracks the live
  switch; the *configured* mode of `notify` itself comes from the
  launch-time merged config, which is why that key carries a restart
  badge.
- **A disclosed stacking:** while the upgrade is in effect, an ask with
  `notify: off` sounds twice — the upgraded bell and the prompt's own cue
  BEL. Changing that is a behaviour change reserved to its own ruling.

## Reduced motion

`CANDY_CODE_REDUCED_MOTION=1` (also `true` or `yes`, case-insensitive)
pins the agent spinner to a static frame without shedding anything else.
`Bootstrap::chat()` reads it once at launch and calls
`AgentLiveRegistry::setStaticSpinner()`; the gate answers on when the
launch switch was set **or** the `screenReader` setting is on — the live
equivalent of this variable is that setting's spinner half. Unset, `0`,
empty or any other word leaves the animation on. As with the setting,
only the glyph stops advancing; elapsed-seconds movement still repaints.

## Contrast

- **One floor for every colour: 4.5:1.** `Theme::CONTRAST_MIN` is the
  WCAG 2.1 AA threshold for body text, deliberately not split into
  WCAG's two tiers. The obvious split — 4.5:1 for text and 3.0:1 for
  shapes like box lines and status dots — was measured and ruled out:
  sugar-style paints a box's *title* in the border colour, so an
  unfocused pane's `border` token draws both the `─` run and the word
  sitting in it. There is no colour on this shell that is only ever a
  shape, and 4.5:1 subsumes 3.0:1.
- **The floor is enforced, not audited.** When a theme resolves against
  the terminal, a palette colour that fails 4.5 on a surface it is seen
  on (the background, and the row-highlight fill for the text painted
  over it) is nudged along its own HSL lightness axis, away from the
  background, until it clears — `Theme::legibleOn()` is the same nudge
  for a colour a theme did not declare. Nudging rather than substituting
  is what keeps a theme looking like itself on an odd terminal.
- **Row highlights obey it from the other side.** The highlight fill's
  lightness shift is a maximum that gives itself back in steps — to zero
  if it must — whenever the shift would put the text on top of it below
  the floor, while keeping the fill distinguishable enough to read as a
  band rather than as the terminal's own background.
- **The terminal's own colours are honoured.** The `adaptive` theme asks
  the terminal for its background over OSC 11 and resolves on read, so
  the answer that lands some frames after launch still takes effect
  mid-session without a cache to invalidate. On terminals whose ambient
  colour profile is ASCII (a poor palette), the spinner swaps to an
  ASCII frame set — braille dots a terminal cannot draw are not a
  fallback.
- **`NO_COLOR` is honoured.** A truthy `NO_COLOR` (any non-empty value
  other than `0` or `false`) drops the colour profile to ASCII, keeping
  bold, faint and italic, per [no-color.org](https://no-color.org). It
  outranks the force variables. The full rules, including `CLICOLOR`, are
  in [ENVIRONMENT.md](ENVIRONMENT.md).

## See also

- [SETTINGS.md](SETTINGS.md) — the `screenReader` and `notify` keys, their
  tiers and when a change applies.
- [ENVIRONMENT.md](ENVIRONMENT.md) — `CANDY_CODE_REDUCED_MOTION`.
- [ARCHITECTURE.md](ARCHITECTURE.md) — the renderer and theme plumbing the
  doors above sit in.
