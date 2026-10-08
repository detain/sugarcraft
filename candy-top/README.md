# candy-top

A terminal system monitor in the spirit of [btop](https://github.com/aristocratos/btop), built in pure PHP on top of the SugarCraft TUI libraries. candy-top renders live CPU, memory, network, disk, and process views using the charting and dashboard primitives from `sugar-charts` and `sugar-dash`, driven by the SugarCraft `Model`/`Cmd` event architecture from `candy-core`.

## Requirements

- PHP 8.3+
- Composer
- A modern terminal (truecolor recommended)

## Install

```sh
composer require sugarcraft/candy-top:@dev
```

## Status

Scaffolding — see PLAN (`plan_top.md` in the SugarCraft monorepo).

## License

MIT
