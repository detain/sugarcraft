# SugarIgnore

gitignore-style ignore matching over **injected file contents** — no filesystem access, so a TUI, a git server and a tree browser can share one engine. Extracted from candy-code's `IgnoreRules`.

```sh
composer require sugarcraft/sugar-ignore
```

## Quick start

```php
use SugarCraft\Ignore\IgnoreRules;

$rules = IgnoreRules::fromPaths([
    '.gitignore'          => file_get_contents('.gitignore'),
    'pkg/.gitignore'      => "!keep.log\n",
    '.git/info/exclude'   => "scratch.txt\n",
]);

$rules->ignores('app/debug.log', false); // ".gitignore: *.log"  (the deciding rule) or null
$rules->isIgnored('pkg/keep.log', false); // false
$rules->ignores('build', true);           // directory-only rules need $isDir
```

Paths are root-relative, or absolute under the optional second argument of `fromPaths($files, $root)`, which is stripped.

## API

| Method | Purpose |
| --- | --- |
| `IgnoreRules::new(?string $root = null)` | Empty ruleset (nothing ignored). |
| `IgnoreRules::fromPaths(array $files, ?string $root = null)` | Build from `path => contents`; each file is scoped to its own directory. |
| `->withFile(string $path, string $contents)` | New instance carrying one more (or replaced) ignore file. |
| `->ignores(string $relPath, bool $isDir): ?string` | The deciding `<file>: <pattern>`, or `null` when not ignored. |
| `->isIgnored(string $relPath, bool $isDir): bool` | Boolean form. |
| `->undecidableRules()` / `->undecidablePatterns()` | Rules too costly to decide, resolved toward hiding. |

## Semantics

`#` comments and `\#`, blank lines, trailing whitespace unless escaped, `!` negation (later wins), trailing `/` directory-only, leading/inner `/` anchoring, bare patterns at any depth, `*` `?` `[...]` `[!...]`, `**/`, `/**`, `a/**/b`, backslash escapes, nested ignore files (deeper wins), `.git/info/exclude` below every other file, and git's rule that nothing under an ignored directory can be re-included.

Not supported on purpose: global excludes and git config, case folding, the index (tracked files count as ignored if a pattern matches). Matching is case-sensitive.

## Hostile patterns

A cloned repository supplies both the ignore file and the paths, so a pattern is hostile input. Simple shapes (`*.log`, `**/foo`, `foo/**`) run on PCRE; anything that could backtrack runs on a lazily built DFA (`Automaton`) with no backtracking. A pattern whose cost still exceeds the budget is *undecidable*: it fails closed (a hide rule hides, a negation does not re-include) and is recorded on `undecidableRules()` rather than logged.

## Tests

```sh
cd sugar-ignore && composer install && vendor/bin/phpunit
```
