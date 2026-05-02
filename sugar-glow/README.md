# SugarGlow

PHP port of [charmbracelet/glow](https://github.com/charmbracelet/glow) —
a Markdown CLI viewer that composes **CandyShine** (rendering) and
**SugarBits Viewport** (scrolling).

```sh
$ sugarglow README.md           # render and print (default)
$ sugarglow -p README.md        # open in a fullscreen pager
$ git log -1 --pretty=%B | sugarglow -p
```

In pager mode standard reader keys come straight from `Viewport`:
`↑/↓/k/j`, `PgUp/PgDn`, `Ctrl+U / Ctrl+D` (half page), `Home/g`,
`End/G`, plus `q` / `Esc` / `Ctrl+C` to exit.

## Test

```sh
cd sugar-glow && composer install && vendor/bin/phpunit
```
