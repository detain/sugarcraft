# SugarCodec

YAML codec for SugarCraft. This package is a skeleton: its namespace and wiring exist, and the parser and emitter arrive in later changes.

> Original work. There is no upstream port, so no `Mirrors charmbracelet/...` citation applies.

```sh
composer require sugarcraft/sugar-codec
```

Requires PHP ≥ 8.3. The package has no runtime dependencies beyond PHP, and it does not depend on any other SugarCraft package.

The role of this library is to read and write the YAML subset that SugarCraft tools use for configuration and data files. Two copies exist today: the hand-written YAML subset in the `candy-ssh-launcher` config reader, and the `YamlDialect` engine in `candy-kv`, which carries two parsing engines. Those copies move here in a later step, leaving one tested codec that every tool shares.

Namespace: `SugarCraft\Codec\`. Source lives in `src/`, and tests in `tests/`.
