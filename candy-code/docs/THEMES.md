# Themes

A theme sets the colors of the shell: the frame, the borders, the highlight
on the selected row, and the markdown in replies. candy-code ships six built-in
themes and reads custom ones from your home directory. Every color is checked
for legibility against your terminal's real background before it is painted, so
a theme cannot make text unreadable.

## Choosing a theme

Set the `theme` setting, or switch in the session with `/theme <name>`. Names are
matched without regard to case.

```json
{
  "theme": "tokyoNight"
}
```

The built-in names are:

| Name | What it is |
|---|---|
| `dark` | The default. A dark palette for a dark terminal. |
| `light` | A light palette for a light terminal. |
| `dracula` | The Dracula palette. |
| `tokyoNight` | The Tokyo Night palette. |
| `ansi` | The eight-color ANSI palette, so the app's colors come from your terminal's own scheme. |
| `adaptive` | `dark` or `light`, picked from your terminal's background. See below. |

`theme` is a project-settable key, so a project's settings file can name a theme
for everyone who opens it. A project cannot supply the colors of a custom theme
(see [Custom themes](#custom-themes)); a name with no file in your home falls back
to the default.

### `adaptive`

`adaptive` resolves on every paint, so it follows the terminal you are in now
rather than the one that was current when you chose it. candy-code decides between
`dark` and `light` in this order:

1. The `CANDY_CODE_BACKGROUND` environment variable, or the `terminalBackground`
   setting, when either says `light` or `dark`. A statement from you beats a
   measurement.
2. The terminal's own answer to a background-color query, sent when the app
   starts. The reply can arrive a few frames after launch, and the palette
   switches when it does.
3. The `COLORFGBG` environment variable, if your terminal sets it.
4. Otherwise, `dark`.

[ENVIRONMENT.md](ENVIRONMENT.md) has the exact rules for the first and third
sources.

## Custom themes

A custom theme is a JSON file in the theme directory of your home:

```text
~/.candy-code/themes/<name>.json
```

The directory is read from your own home, never from the project you are working
in, so a checked-out repository cannot add a theme by shipping a file.

The file name, without `.json`, is the theme name. A name must start with a letter
or a digit. After that it may contain letters, digits, `.`, `_`, `+` and `-`.
Custom names are listed after the built-in ones in the theme picker. A custom
file that has the same name as a built-in theme is ignored in favor of the
built-in, so a file cannot replace `dark`.

The list of themes is read once, when the app starts. A file you add or remove
during a session appears the next time you launch.

### File schema

The file is one JSON object with two optional keys.

| Key | Type | Meaning |
|---|---|---|
| `extends` | string | The built-in theme to start from: `dark`, `light`, `dracula`, `tokyoNight` or `ansi`. Defaults to `dark`. `adaptive` is not a valid base. |
| `tokens` | object | Colors to override, keyed by token name. Each value is a color in `#rrggbb` form: a `#`, then six hexadecimal digits, in either case. |

The token names are:

`foreground`, `background`, `primary`, `secondary`, `accent`, `muted`, `error`,
`warning`, `success`, `info`, `border`, `separator`, `cursor`.

Tokens you leave out keep the value from `extends`. The markdown colors in
replies come from the `extends` base as well, so a custom theme changes the
chrome and keeps the reply styling of its base.

### What is refused, and what happens

A theme file is cosmetic, so a mistake in it never stops the app from starting.
Each problem produces a notice at launch and costs only what it affects:

- A `tokens` entry with an unknown name is ignored.
- A color that is not `#rrggbb` is ignored, and the token keeps its base value.
- An `extends` naming something other than a built-in base, such as `adaptive`
  or a misspelling, starts from `dark`.
- A `tokens` value that is not an object is ignored.
- A file that is not a JSON object, or that is empty, paints its `extends` base
  with nothing overridden, and the notice says why.
- A file that cannot be read paints the default theme, and the notice says so.

A file whose real path leaves the theme directory, for example through a symlink
that points elsewhere, is refused rather than followed.

### Legibility

Every token is projected against your terminal's background before it is used.
When a color is too close to the background to read, candy-code moves its
lightness toward the legible side until the text reaches a 4.5:1 contrast ratio
(the WCAG 2.1 AA threshold for body text). A pale custom color on a light
terminal is therefore adjusted, not painted as written. The row highlight is
checked the same way.

## Worked example

This theme starts from `tokyoNight` and changes three colors. Save it as
`~/.candy-code/themes/midnight-amber.json`:

```json
{
  "extends": "tokyoNight",
  "tokens": {
    "primary": "#ffb454",
    "accent": "#ff8f40",
    "border": "#4c566a"
  }
}
```

Then select it:

```json
{
  "theme": "midnight-amber"
}
```

or, in a running session, `/theme midnight-amber`. The file is listed at the next
launch, so a file added during a session is not offered until then.

To check a file, run `/theme` with no argument: it lists the current theme and
every name the app can use, custom files included. A file the app refuses
produces a notice at launch, and the theme still paints its base.

## See also

- [SETTINGS.md](SETTINGS.md): the `theme`, `terminalBackground` and `keybindings` settings.
- [ENVIRONMENT.md](ENVIRONMENT.md): `CANDY_CODE_BACKGROUND` and `COLORFGBG`.
- [ACCESSIBILITY.md](ACCESSIBILITY.md): the contrast floor the theme projection enforces, and what it does not cover.
- [KEYBINDINGS.md](KEYBINDINGS.md): the other keys you can change in the interface.
