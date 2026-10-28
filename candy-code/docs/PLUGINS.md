# Plugin bundles

A **plugin bundle** is a directory of Claude-format extension content —
skills, slash commands and agent presets in one folder, shipped and installed
as a unit. candy-code **reads** bundles. It does not install registries,
resolve dependencies, or run anything a bundle ships beyond the content its
existing loaders already understand. That reader-first boundary is the whole
page in one sentence; the two surfaces a bundle may carry that candy-code
deliberately never execute are documented below, and they are declined by
design, not pending work.

## What a bundle is

A directory is a bundle exactly when it carries a manifest:

```
my-bundle/
├── .claude-plugin/plugin.json     (a bare plugin.json at the root is accepted too)
├── skills/<name>/SKILL.md         → the skill loaders
├── commands/<name>.md             → the slash-command pipeline
├── agents/<name>.md               → the agent-preset registry
├── hooks/…                        → DECLINED, never registered
└── .mcp.json                      → DECLINED for auto-start, offered for import
```

The manifest needs a `name`; `version` and `description` are shown where
displayed. A directory with no manifest is not a bundle and is passed over
quietly — that is what a `git` checkout of anything looks like from here. A
manifest that exists but will not parse is reported by name at launch: the
difference between "not a bundle" and "broken install" deserves a line.

## Where bundles come from

Three roots are scanned, and each bundle carries the tier its root implies:

| Root | Tier | Trust |
|---|---|---|
| `<project>/.candy-code/bundles/` | `project` | confined to the checkout, exactly like a cloned `.claude/skills` tree |
| `~/.candy-code/bundles/` | `user` | the destination `candy-code bundles install` writes to; a symlink inside it may point anywhere in home and nowhere outside it |
| every `pluginPaths` entry | `user` | the operator's explicit word: a listed path is trusted as given, and may be either one bundle or a container of bundles |

A bundle whose path resolves outside the tree it was found in (a project
symlink pointing at someone else's home, say) is refused, named, and reported
through the same launch-refusal channel as every other project-tier escape.

`pluginPaths` lives in your user config and is user-tier only — a checked-out
repository cannot add to it. See [SETTINGS.md](SETTINGS.md).

## What each surface does

Bundles **map onto the existing loaders** rather than opening a parallel
plugin system, and a bundle's content lands at every loader's **lowest
precedence seat**: a plain `.claude/skills`, `.claude/commands` or
`.claude/agents` directory always beats a bundle's same-named entry, per tier.

- **Skills** (`skills/<name>/SKILL.md`) load through the same discovery as
  every foreign skill and appear badged `[bundle]` in the skill listing, with
  their own colour token so a bundled skill is never mistaken for a native
  one.
- **Slash commands** (`commands/<name>.md`) ride the foreign-command
  discovery that already backs `.claude/commands` — same frontmatter and
  `$ARGUMENTS` rules; the `` !`cmd` `` form expands to a refusal instead of a
  shell, see the declined surfaces below and [COMMANDS.md](COMMANDS.md).
- **Agent presets** (`agents/<name>.md`) register like any other foreign
  preset; the file format is the Claude one by definition. A bundle preset
  loses a name collision to any plain foreign directory, then to the project
  tier as usual.

Everything the [precedence section of SKILLS.md](SKILLS.md) says about
shadowing applies per-surface; the bundle badge is how you tell which side of
a shadow you are looking at.

## The declined surfaces

A bundle may ship things candy-code will not run. The first two declines fire
**once per session at launch**, naming the bundles affected, as ordinary
system rows in the transcript:

- **Hooks.** Bundle hook content is never registered — neither the `hooks/`
  directory, nor a `hooks` path declared in the manifest, nor an **inline
  `hooks` object** inside `plugin.json` (C-EXT-7: the declined-surface
  reader answers with the manifest path — `hooksPaths()` names where the
  hooks ride inside it — while the decline notice names the affected
  bundles). Lifecycle hooks execute
  arbitrary programs at every session event; the hooks that do run are
  declared in the project's own `hooks.yaml` under
  [HOOKS.md](HOOKS.md) trust, not smuggled in by a third-party folder.
- **`.mcp.json` — and its manifest twins.** A bundle's MCP declarations are
  never auto-started: neither a shipped `.mcp.json`, nor the manifest's
  `mcp`/`mcpServers` keys as path strings, nor an **inline `mcpServers`
  object** inside `plugin.json` (the declined-surface reader answers with
  the manifest path — `mcpFile()` names where that declaration lives —
  while the decline notice names the affected bundles).
  The only file `candy-code` starts servers from is the project root's
  `.mcp.json`, behind its trust gate ([MCP.md](MCP.md)). If you want a
  bundle's servers, read the file yourself and import deliberately:

  ```bash
  candy-code mcp import claude <path-to-bundle>/.mcp.json
  ```

  which prints a config block and writes nothing, exactly as it does for any
  other foreign MCP file.
- **Shell forms.** A `` !`cmd` `` inside a bundle's command file is refused at
  expansion — in the transcript, not by a launch notice. The tier the bundle
  happens to install under decides nothing, and `trustedProjectCommands` does
  not unlock it either (C-SEC-3; the [COMMANDS.md](COMMANDS.md) tier table
  carries the row). A per-origin opt-in is the planned follow-up. If those
  surfaces are unacceptable to you, the
  bundle is a stranger; deleting its directory takes it all back.

## Installing and listing

```bash
candy-code bundles list                   # every bundle this directory sees,
                                          # per-surface counts and both declines
candy-code bundles install <path>         # copy a bundle directory into
                                          # ~/.candy-code/bundles/
candy-code bundles install <git-url>      # depth-one clone into the same place
candy-code bundles install <plugin>@<marketplace>
                                          # gated install from an added marketplace
candy-code bundles marketplace add <path|git-url>
                                          # pin a marketplace catalog under ~/.candy-code/
candy-code bundles marketplace list       # the added rows and their pins
candy-code bundles marketplace remove <name>
candy-code bundles marketplace update [name]
                                          # re-clone, diff, move the pin
candy-code bundles update [bundle]        # re-install recorded bundles from their rows
candy-code bundles search <query>         # every catalog entry matching a needle
candy-code bundles recommend              # entries the project's own files point at
```

`list` answers without a provider, an API key or a terminal, and
`--output-format json` emits the machine document. `install` refuses anything
without a manifest, never overwrites (an existing name is an error, not a
backup prompt), and names the installed bundle after its manifest `name` when
it has one — a name beginning with a dot is refused, because discovery's glob
never sees a dot-named directory and an invisible install is worse than none.
Both arms land content-only trees: the copy skips every `.git` directory and
the clone sheds its own before the rename. A git clone runs as a bounded,
non-interactive child: `--depth 1`,
no tags, terminal prompts and askpass disabled, killed with its process group
if it overstays. Its only destination is the user bundle root — a clone can
land in `~/.candy-code/bundles/<name>` and nowhere else, under a name that can
not traverse out of it.

## Marketplaces

A marketplace is a directory whose `.claude-plugin/marketplace.json` names
itself and lists plugins, each with a source: a path strictly inside the
checkout, a `github` `owner/repo`, or a plain `https` git URL.
`bundles marketplace add` clones or opens such a source, reads the catalog,
resolves a full 40-hex commit for it, and writes one registry row under
`~/.candy-code/marketplaces.json`; remote checkouts land beside it under
`~/.candy-code/marketplaces/<name>/` with the `.git` stripped. A source with
no resolvable commit is refused outright — a marketplace nobody can pin is a
catalog every install from it would silently drift on.

That pin is the install gate. `bundles install demo@marketplace` only runs if
`marketplace` is a registered row with a pinned commit and an entry named
`demo`; otherwise it refuses with the sentence naming why. A gated install
stages exactly like a direct one (path copy or depth-one clone, both
content-only), then writes a provenance record
(`<bundle>/.candy-code-source.json`) naming the marketplace, the entry and
the commit — the row `bundles update` later refreshes against.

`bundles marketplace update` re-clones the row's source into a scratch beside
the old checkout, prints which plugins arrived and left the catalog, swaps
the checkout by rename, and only then moves the pin. If the catalog renamed
itself the update refuses — the registry name is the identity every install
record keys on. Local-path rows answer "update it in place" and do nothing
else. `bundles update` (with or without a bundle name) re-installs each
recorded bundle from its row: the old tree moves aside, the fresh tree lands
under the name, and the aside copy is removed last and without ever following
a link, so no upstream tree can aim a removal at your home directory. A
bundle whose upstream renamed itself is refused rather than re-keyed, and
bundles installed from a bare path or URL simply carry no record — the scan
answers that they were left alone.

`bundles search <query>` and `bundles recommend` read the same catalogs and
write nothing: the first matches name, source, description, category and
keywords; the second scores catalog keywords against the signal words of the
project files sitting in the working directory (a `package.json` points at
the JavaScript-family keywords, a `Dockerfile` at the container family), and
never names an entry whose bundle is already installed. Both answer to
`--output-format json`.

`marketplace remove` retires the row; its checkout directory stays on disk,
because deleting trees is not what a remove verb is for.

## See also

- [SKILLS.md](SKILLS.md) — discovery roots and precedence for skill content
- [COMMANDS.md](COMMANDS.md) — the slash-command file format bundles reuse
- [MCP.md](MCP.md) — what `.mcp.json` is, and what trusting one means
- [HOOKS.md](HOOKS.md) — the hook system bundles deliberately do not join
- [SETTINGS.md](SETTINGS.md) — the `pluginPaths` key
