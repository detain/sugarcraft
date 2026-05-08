# Plan: cross-platform open helper (`x/exp/open`)

## Goal

Provide a tiny, dependency-free helper to open URLs and files in the
user's default application across Linux, macOS, Windows, and WSL.
Mirrors `charmbracelet/x/exp/open`.

## Scope

**In**

- Open a URL in the default browser
- Open a file in the default app for its MIME type
- Detect platform and dispatch to the right native command
- Return `bool` for success — never throw

**Out**

- Open with a *specific* app (e.g. "open this in Firefox not Chrome") — out of v1; trivial to add later
- Wait for the launched app to close — fire-and-forget only

## Where it lives

`candy-core/src/Util/Open.php`. Reasoning:

- candy-shine wants this for OSC 8 hyperlink fallback (when terminal doesn't support OSC 8)
- candy-shine depends on candy-core, not candy-shell
- keeping it in candy-core means *any* SugarCraft consumer can use it without pulling in the candy-shell CLI

Public API:

```php
namespace SugarCraft\Core\Util;

final class Open
{
    public static function url(string $url): bool;
    public static function file(string $path): bool;
}
```

## Platform dispatch table

| Platform detect | Command |
|---|---|
| `EnvDetect::isWsl()` (existing helper from x-windows plan) | `wslview $arg` if available, else `cmd.exe /c start "" $arg` |
| `PHP_OS_FAMILY === 'Darwin'` | `open $arg` |
| `PHP_OS_FAMILY === 'Linux' \|\| 'BSD' \|\| 'Solaris'` | `xdg-open $arg` |
| `PHP_OS_FAMILY === 'Windows'` | `cmd /c start "" $arg` (the empty `""` is the window title — required so `start` doesn't interpret a quoted URL as the title) |

## Implementation sketch

```php
public static function url(string $url): bool
{
    if (!preg_match('#^(https?|file|mailto|ftp|ssh)://#i', $url) && !str_starts_with($url, 'mailto:')) {
        return false;  # reject anything that isn't a known scheme
    }
    return self::dispatch($url);
}

public static function file(string $path): bool
{
    $real = realpath($path);
    if ($real === false) return false;
    return self::dispatch($real);
}

private static function dispatch(string $arg): bool
{
    [$cmd, $args] = self::commandFor($arg);
    $proc = proc_open(
        [$cmd, ...$args],
        [['pipe','r'], ['pipe','w'], ['pipe','w']],
        $pipes,
        null, null,
        ['bypass_shell' => true]
    );
    if (!is_resource($proc)) return false;
    foreach ($pipes as $p) fclose($p);
    proc_close($proc);  # fire-and-forget; child detaches itself via xdg-open/open semantics
    return true;
}
```

The command builder uses an array (not string concat) so PHP's
`proc_open` escapes args correctly without our calling
`escapeshellarg`. URL injection via shell metachars is impossible.

## Test strategy

- Inject a `proc_open` shim — `Open` takes a static `$runner = null;` that
  defaults to `proc_open` but is swappable in tests
- Tests assert: given env X, `Open::url('https://example.com')` invokes
  command Y with args Z
- One round-trip integration test on Linux CI: `Open::file('/tmp/test.txt')`
  with `XDG_OPEN=$(echo)` overridden via a shim — assert we got the right
  argv

## Caveats

1. **Linux without xdg-utils** — `xdg-open` may not be installed on
   minimal containers. We return `false` rather than throwing. Caller
   decides UX (silent fail vs. error message).
2. **WSL `wslview` availability** — ships with `wslu` package, not
   universal. Fall back to `cmd.exe /c start ""` which is always
   available via Windows interop.
3. **macOS sandboxed env** — `open` may be blocked under tight LaunchD
   sandboxes; just returns non-zero exit. We treat as failure.
4. **Security** — never pass user input into a shell string. Always use
   the `proc_open` argv form. URL scheme allowlist guards against
   `javascript:` / `data:` / `vbscript:` exfiltration vectors.

## Effort

~2-3 hours total:

- `Open.php` + platform dispatch — 1h
- Tests — 1h
- README snippet + integration into candy-shine OSC 8 fallback — 30m

Single PR.

## Dependencies

- Soft dep on `EnvDetect::isWsl()` from [x-windows](./x-windows.md). If
  that hasn't landed yet, inline a 5-line WSL detector here and unify
  later.

## Tracking

- `MATCHUPS.md` — no new row (utility, not a lib)
- `UPSTREAM_OPPORTUNITIES.md` — flip `x/exp/open` row to 🟢 on land
- candy-core README — add `Open::url` to the utility table
