# SugarReleases

Phar self-update engine and launch-time update notice for SugarCraft apps.
Extracted from candy-code's `SelfUpdate` / `UpdateCheck` / `WindowsSwap` so every
phar-shipped app (candy-code first, candy-top next) rides the same fail-closed
laws. First-party: there is no upstream Go port.

```sh
composer require sugarcraft/sugar-releases
```

## Quickstart

```php
use SugarCraft\Releases\ReleaseSource;
use SugarCraft\Releases\SelfUpdate;
use SugarCraft\Releases\UpdateCheck;
use SugarCraft\Releases\UpdateOptions;

$source = ReleaseSource::new('acme/tools', 'widget')   // tags widget/v1.2.3, asset widget.phar
    ->withComposerPackage('acme/widget')
    ->withPublicKey($base64Ed25519PublicKey);          // '' = keyless state

// `widget self-update [--check] [--rollback] [--allow-unsigned] [--channel=stable|dev]`
$exit = SelfUpdate::new($source)->run(
    UpdateOptions::new()->withCheck(),
    $currentVersion,                                    // 'v1.2.3' or 'dev-<sha>'
);

// at launch: refresh a 24 h cache over the event loop, paint the PREVIOUS check's answer
$check = UpdateCheck::new($source, $stateDir, $currentVersion);
$check->refreshIfStale();
echo $check->cachedNotice() ?? '';
```

Exit codes: `0` up to date / swapped / rolled back; `1` refusals and failures
(and `--check` finding an update available); `2` usage errors
(`UpdateOptions::validate()`).

## The laws (pinned by the tests)

- **Phar mode only.** A Composer install (`\Phar::running(false)` empty) is a
  loud refusal naming the command that fits the install shape, exit 1 — never a
  silent no-op.
- **Latest** means the newest non-draft, non-prerelease `<tagPrefix>v*` release
  carrying BOTH the phar and `.sha256` sidecar assets (stable), or the rolling
  `<tagPrefix>dev` release judged by commit against a `dev-<sha>` stamp (dev).
  The release list is walked through `Link: rel="next"` up to five pages.
- **Verify before rename.** Sidecar first (lowercase 64-hex, name must match),
  phar streamed to a temp file in the phar's own directory, sha256 compared with
  `hash_equals()`; with a key pinned, the Ed25519 signature leg runs next
  (signature over the sidecar body preferred, legacy raw-bytes asset accepted
  behind a deprecation notice, neither is a refusal). `--allow-unsigned` never
  skips the sha256 gate. Every download leg judges HTTP status.
- **One-shot rollback.** The running phar is copied to `<phar>.old` before the
  swap; `--rollback` renames it back (consuming it), touching no network. The
  live file's mode is carried onto temp and `.old`. A shutdown hook sweeps the
  temp path.
- **Windows.** The verified bytes stage at `<phar>.new` and a detached
  `WindowsSwap` helper retries the rename (200 ms cadence, 60 s budget) until the
  process exits, logging to `<phar>.swap.log`.
- **Time bounds.** Connect + per-read ceilings and a low-speed stall pair, no
  total request timeout on the phar download. The launch notice uses one total
  timeout on a single small GitHub read and never blocks the render.

## Host seams

| Seam | Purpose |
|---|---|
| `ReleaseSource` | repo, app name, tag prefix, phar asset, pinned key, Composer package, user agent |
| `SelfUpdate::withTranslator(Closure)` | map the `selfupdate.*` keys of `lang/en.php` onto the host's catalog (`{app}`, `{package}` are filled) |
| `SelfUpdate::withErrorStream()` | where report lines go (default STDERR) |
| `SelfUpdate::withSwapLauncher(Closure)` / `WindowsSwap::launch(..., $spawnSpec, $env)` | host process-containment for the detached helper |
| `UpdateCheck::withTranslator()` / `withLogger()` / `withOptOutEnv()` | notice wording (`update.available`), once-per-process degradation log, opt-out env (default `<APP>_NO_UPDATE_CHECK`) |
| `with{Http,ReleasesUrl,PharPath,PublicKey,Windows}` | test seams (fake release server as a Guzzle handler, stub phar, OS leg) |

## Tests

```sh
composer install && vendor/bin/phpunit
```

The suite mounts a fake release server as a Guzzle handler (zero sockets) and
swaps stub archives; a few pins spawn real child PHP processes (memory-limit
raise, a 48 MB sidecar-signed swap inside a 32 MB child, the Windows helper).
Ed25519 pins skip their positive leg when ext-sodium is absent.
