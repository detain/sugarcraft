# CandyForms

Foundation lib for form primitives — TextInput, TextArea, ItemList, Viewport,
FilePicker, Field interface, Confirm, Form — extracted from sugar-bits and
sugar-prompt.

## Install

```sh
composer require sugarcraft/candy-forms
```

## Role

CandyForms is the foundation layer that sugar-bits and sugar-prompt will
re-export from. It contains the raw component implementations:
TextInput, TextArea, ItemList, Viewport, FilePicker, and the Field interface,
plus the Confirm and Form composite components.

## Status

🟡 Extraction in progress — Phase 1 scaffold. Real implementations will
migrate from sugar-bits and sugar-prompt in Phase 2.

## Dependencies

- `sugarcraft/candy-core` — Elm-architecture TUI runtime
- `sugarcraft/candy-sprinkles` — Declarative styling

## License

MIT
