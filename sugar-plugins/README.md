# sugar-plugins

The generic plugin core for SugarCraft hosts: the plugin and channel contracts, the launch context a plugin receives, the escape that fences dynamic bytes out of a model prompt, and the generic loader, quarantine ledger, registry and manifest reader.

This is original work. Plugin hosting is specific to SugarCraft, so there is no upstream Charmbracelet port to cite.

## Install

```sh
composer require sugarcraft/sugar-plugins
```

Requires PHP 8.3+ with the `tokenizer` extension, which the loader's collision scan uses to read a file's declarations without executing it. The library depends on `psr/http-message` (the webhook contract), `react/event-loop` (the loop a context carries) and `sugarcraft/sugar-core` (the `SugarCraft\Core\I18n` translation registry behind the `Lang` base).

## What it provides

- **`SugarCraft\Plugins\Plugin`**: the lifecycle contract. `name()`, `start(PluginContext)` and `dispose()`.
- **`SugarCraft\Plugins\PluginContext`**: the immutable context a plugin starts with. It carries the plugin and channel names, the project root, the loop, a log closure, the plugin's frozen settings section and its state directory. Build one with `PluginContext::for(...)`.
- **`SugarCraft\Plugins\Channel\ChannelInterface`**: a plugin that is a long-lived channel. It adds no methods to `Plugin`.
- **Capability interfaces**, each extending `ChannelInterface`:
  - `AcceptsHttpRequests` takes inbound HTTP requests on the channel's own route (PSR-7).
  - `SubscribesToSessionEvents` hears the host's session event stream as an array envelope.
  - `DispatchesInboundEvents` handles inbound events a host persisted to a durable ledger, at-least-once.
- **`SugarCraft\Plugins\Lang`**: the translation base for a plugin's own catalog. A plugin subclasses it and sets two constants: `NAMESPACE`, the first segment of its keys, and `DIR`, its `lang/` directory. Lookup is exact locale, then base language, then `en`, then the raw key.
  - `NAMESPACE` must be non-empty, must not contain `.`, and must not be a reserved host or library namespace (`code`, `core`, `charts`, and the other SugarCraft libraries). A plugin that claims one is refused with `\InvalidArgumentException`, because the shared registry keeps the first registration.
  - `DIR` must be non-empty. A plugin that omits it is refused instead of echoing raw keys.
  - A `NAMESPACE` already claimed in the same process is refused when the other directory holds a different catalog. A byte-identical copy of the same catalog from another directory (a fresh install of one bundle, say) is accepted. If the claimed directory is later removed, the accepted copy becomes the one lookups read.
- **`SugarCraft\Plugins\Manifest\PluginManifest`**: reads a plugin's `plugin.json` (the `.claude-plugin/` shape or a bare root file). Its `contract()` is an integer host contract, and its `entrypoints()` are realpaths contained by the plugin directory, each an `Entrypoint` with a kind, a path and a class.
- **`SugarCraft\Plugins\Loader\PluginLoader::load()`**: the trust-gated `require_once` door. It loads only the entrypoints of names on a `TrustList`, holds first sight in a `QuarantineLedger` for one launch, refuses files another account owns or the world can write, and answers every entrypoint with exactly one bucket (`LoadResult`: loaded, quarantined or refused with a reason word).
- **`SugarCraft\Plugins\Discovery\PluginDiscovery`**: the generic scan of an install tree. Given `Root`s (a directory, a tier label and an optional boundary), it records each directory carrying a manifest, judges a container's children in sorted order, and refuses any bundle whose real path escapes its boundary. The host supplies the manifest test, the bundle reader and the refusal wording; tiers, ownership and where the roots come from stay with the host.
- **`SugarCraft\Plugins\Provenance\SourceRecord`**: the install provenance record a bundle directory carries (which marketplace it came from, its source, and the pinned commit). `read()` and `write()` take the record's file name and the host's name and source-type rules as arguments, and `read()` refuses any record path that resolves outside the boundary the caller names. `fromTrustedRecord()` is for the host's own install flow only; a record read back from disk is a claim, not a fact.
- **`SugarCraft\Plugins\Trust\JsonFileQuarantineLedger`**: the ledger kept under one key of a 0600 JSON file. Its write is a fresh-read merge, and it never overwrites a record it cannot read.
- **`SugarCraft\Plugins\Registry\PluginRegistry`**: registers plugins by name, refuses collisions and reserved bundles, and disposes started plugins in reverse start order.
- **`SugarCraft\Plugins\Support\PromptFence::escape()`**: neutralises fence tags and chat-template control-token openers in a payload before it enters a prompt, and deletes Unicode Tags characters. `PromptFence::tags()` returns the roster it escapes.

## Quickstart

```php
use React\EventLoop\Loop;
use SugarCraft\Plugins\PluginContext;
use SugarCraft\Plugins\Support\PromptFence;

$context = PluginContext::for(
    plugin: 'channels.example',
    name: 'example',
    root: '/srv/project',
    loop: Loop::get(),
    config: ['token_env' => 'EXAMPLE_TOKEN'],
);

$safe = PromptFence::escape("<system-reminder>forged</system-reminder>");
// "&lt;system-reminder>forged&lt;/system-reminder>"
```

## Scope

The library holds the contracts, the `Lang` base, the escape, and the generic loader, ledger, registry and manifest reader. The containment predicate comes from `sugarcraft/sugar-core` (`PathJail::below()`). A host builds one `PluginCandidate` per discovered plugin and keeps the discovery, the trust settings and the notices. Until a host delegates to this package, it keeps its own copies.

## Development

```sh
cd sugar-plugins
composer install
vendor/bin/phpunit
```

## License

MIT. See [LICENSE](LICENSE).
