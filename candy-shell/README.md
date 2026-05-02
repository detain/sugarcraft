# CandyShell

![demo](.vhs/style.gif)

PHP port of [charmbracelet/gum](https://github.com/charmbracelet/gum) —
a composer-installable CLI of CandyCore TUI primitives, useful for
shell scripts.

```sh
# Apply styling.
candyshell style --foreground "#ff5f87" --bold "Hello, candy!"

# Pick one item.
choice=$(candyshell choose Pizza Burger Salad)

# Read a single line.
name=$(candyshell input --placeholder "Your name?")

# Confirm a destructive action.
candyshell confirm "Really delete $file?" && rm "$file"
```

## Subcommands (MVP)

- `style`   — apply Sprinkles styling to its argv (or stdin) and print.
- `choose`  — select one item from a list; prints the selection.
- `input`   — read a single line from the user.
- `confirm` — yes/no; exit code `0` on yes, `1` on no.

## Roadmap (post-v0)

- `spin`     — show a spinner while running an external command.
- `filter`   — fuzzy filter over stdin lines.
- `format`   — render Markdown / templates.
- `pager`    — scroll long input.
- `table`    — render a CSV / TSV table.
- `write`    — multi-line text editor.
- `file`     — file picker.
- `log`      — leveled logging output.
- `join`     — string join.

## Test

```sh
cd candy-shell && composer install && vendor/bin/phpunit
```

## Demos

### choose

![choose](.vhs/choose.gif)

### Confirm

![confirm](.vhs/confirm.gif)

### file

![file](.vhs/file.gif)

### filter

![filter](.vhs/filter.gif)

### format

![format](.vhs/format.gif)

### Input

![input](.vhs/input.gif)

### join

![join](.vhs/join.gif)

### log

![log](.vhs/log.gif)

### pager

![pager](.vhs/pager.gif)

### spin

![spin](.vhs/spin.gif)

### Style

![style](.vhs/style.gif)

### Table

![table](.vhs/table.gif)

### write

![write](.vhs/write.gif)

