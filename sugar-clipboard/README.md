# SugarClipboard

Clipboard access for SugarCraft. This package is a skeleton: its namespace and wiring exist, and the clipboard classes arrive in later changes.

> Original work. There is no upstream port, so no `Mirrors charmbracelet/...` citation applies.

```sh
composer require sugarcraft/sugar-clipboard
```

Requires PHP ≥ 8.3. The package has no runtime dependencies beyond PHP, and it does not depend on any other SugarCraft package.

The role of this library is to read and write the system clipboard from a terminal program. The main backend is OSC 52, the terminal escape sequence that asks the terminal emulator to set its clipboard, so the copy works over SSH and inside multiplexers as long as the terminal allows it. The OSC 52 code comes from `candy-git-server`, where it has no consumers today, and it moves here as the backend of this library.

Namespace: `SugarCraft\Clipboard\`. Source lives in `src/`, and tests in `tests/`.
