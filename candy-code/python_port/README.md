# candy-code Python port (experimental)

> **Experimental. The package does not import.** Two modules name files that do not exist:
> `src/__init__.py` imports `candycode.chat`, and `src/candycode/renderer/__init__.py` (and
> `src/candycode/renderer.py`) import `candycode.renderer.chat_renderer`. Neither module is in
> the tree, so `import candycode` fails. The import paths are left as they are for now.

This directory is an incubating Python sketch of parts of the candy-code TUI: the message,
role, theme, tool-result and renderer types, plus the pane and diff helpers. It is not wired to
the runtime, has no test suite, and is not covered by the PHP drift guards.

The supported implementation is the PHP one in `candy-code/src/`. Read the root
[README](../README.md) for how to run it.
