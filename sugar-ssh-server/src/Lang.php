<?php

declare(strict_types=1);

namespace SugarCraft\SshServer;

use SugarCraft\Core\I18n\Lang as BaseLang;

/**
 * Per-library translation facade for sugar-ssh-server.
 *
 * Wraps the shared {@see \SugarCraft\Core\I18n\T} registry with the
 * `'sshserver'` namespace baked in. Translated strings live in
 * {@see ../lang/en.php} and its fifteen siblings. (audit MINOR 19 named the
 * old namespace here, which had been renamed away with the library.)
 *
 * **Nothing here selects a locale implicitly (audit MAJOR 15).** The base
 * class threads no locale argument, so an unarmed server renders every
 * message this facade produces in English whatever the sixteen catalogues
 * on disk contain. The one shipped code path that calls `T::setLocale()`
 * is `Server::withLocale()` — armed by you at boot, applied at `serve()`
 * entry, and only there. Selection stays a deliberate operator decision,
 * made once at entry-script start through either door:
 *
 *     $server->withLocale('de');
 *     // or, without the Server door:
 *     \SugarCraft\Core\I18n\T::setLocale('de');
 *
 * Do not reach for `T::detect()` here. It reads `LC_ALL`, `LC_MESSAGES` and
 * `LANG`, and `LANG` reaches a `ForceCommand` child only if sshd's
 * `AcceptEnv` allows it - which means the connecting client would be
 * choosing the language your server answers in, on a path where you also do
 * not know whether the name it sent is real. `Session->$lang` carries that
 * same peer-asserted value: it is input to log, not a setting to obey.
 *
 * Coverage is uneven and pinned so it can only improve: eleven of the
 * thirty-three keys are still English placeholders in all fifteen translated
 * locales (roster in `tests/LangRosterTest.php`).
 *
 * @extends BaseLang
 */
final class Lang extends BaseLang
{
    protected const NAMESPACE = 'sshserver';
    protected const DIR = __DIR__ . '/../lang';
}
