<?php

declare(strict_types=1);

namespace SugarCraft\Code\Cli;

use SugarCraft\Code\Channels\BundleChannelLoader;
use SugarCraft\Code\Hooks\ClaudeSettingsHooks;
use SugarCraft\Code\Hooks\HookConfig;
use SugarCraft\Code\Hooks\HookManager;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Support\ContainedPath;

/**
 * The P1 channel-plugin-core wiring for bundles the user has trusted BY NAME:
 * the `trustedBundleChannels` read, the D1 trust-gated hooks arm, and the
 * once-per-process hook-file reader that arm shares with the project loop.
 *
 * Extracted from `Bootstrap` under the size ratchet's M6 ruling path when the
 * P1 plugin-core lane pushed the file past its ceiling — the behavior is
 * byte-for-byte what that lane shipped, relocated here so `Bootstrap.php`
 * sits under the ratchet again (the arm's three transcript-seam notices'
 * StderrEmitterCensus prefixed-writer credit moved with the bytes). The
 * methods flatten into `Bootstrap`, which stays their only consumer and keeps
 * answering `Bootstrap::trustedBundleChannelNames()` for the serve startup,
 * the permission-settings reader symbol and the safe-mode flags tests.
 */
trait TrustedBundleHooks
{

    /**
     * The ONE setting that says which named bundles may have their declared
     * `entrypoints.channel` PHP files loaded IN-PROCESS. C-SEC-3.b laws apply
     * unchanged — user tier only, no layering, entries are bundle NAMES from
     * the manifests of bundles already discovered from the roots the operator
     * picked. {@see Bootstrap::TRUSTED_BUNDLE_COMMANDS_CONFIG_KEY} (which only ever
     * launched an external binary) is the precedent; this door is wider: a
     * require_once runs the bundle's code as candy-code itself, which is why
     * the launch refuses anything the manifest never named and the loader
     * re-asserts containment at the read.
     */
    private const TRUSTED_BUNDLE_CHANNELS_CONFIG_KEY = 'trustedBundleChannels';

    /**
     * The bundle NAMES allowed to load their declared channel entrypoints
     * in-process, frozen for the life of this process — the third gate on that
     * door, and the only one a bundle can never touch, since the list is read
     * exclusively from the user's own configuration file.
     *
     * The same C-SEC-3.b laws as {@see Bootstrap::trustedBundleCommandNames()} answer
     * this call, through the same shared parser. They matter MORE here: the
     * command list ends up in front of an external binary, while a named
     * bundle's entrypoint file is require_once'd into this very process.
     * Hence the same trio of holds: no layering (a key absent from
     * LAYERED_KEYS — only the user's config file answers it, so a
     * checked-out project cannot name a bundle to load), safe mode refusing
     * every opt-in before the file is even read ({@see Bootstrap::safeMode()}), and
     * entries being bundle NAMES as their manifests spell them, matched
     * strictly — never a path, never case-folded. A shipped install has an
     * empty list; nothing loads its code in-process until the operator
     * names the bundle.
     */
    public static function trustedBundleChannelNames(): array
    {
        $path = self::trustedConfigDirPath() . '/config.json';

        if (!LaunchContext::hasTrustedChannelNamesFor($path)) {
            LaunchContext::rememberTrustedChannelNames($path, self::trustedOriginNames(
                self::permissionConfig(),
                self::TRUSTED_BUNDLE_CHANNELS_CONFIG_KEY,
                Lang::t('cli.warn.untrusted_channels'),
            ));
        }

        return LaunchContext::trustedChannelNamesFor($path);
    }

    /**
     * The D1 reversal (owner ruling, code-chat §10 P1): register the hook
     * files of every NAME-trusted bundle through the same gating law the
     * project files get, minus the launch-refusal — a broken third-party
     * file declines itself with a loud notice instead.
     */
    private static function registerTrustedBundleHooks(HookManager $hooks, ?string $root, bool $quarantine): void
    {
        $trustedBundleNames = self::trustedBundleChannelNames();

        // D1 reversal (owner ruling, code-chat §10 P1): a bundle the user
        // trusted BY NAME in `trustedBundleChannels` registers its declared
        // hook files here — the same user-tier allow-list that gates the
        // channel classes BundleChannelLoader loads, one consent covering
        // both surfaces. An untrusted bundle never reaches this loop; its
        // decline is the `bundles.hooks_declined` notice in {@see Bootstrap::bundles()}.
        //
        // The bytes gate is the loader's own (refuseLooseBytes): name-trust
        // does not extend to a file another account can rewrite. Entry-level
        // quarantine is CALLED with the bundle path as its own exempt file:
        // the project ledger recorded by checkPolicyFreshness() keys by
        // launch root and is structurally blind to bundle scan-roots, so
        // filtering bundle entries against it would silently drop every one
        // of them. The name-allow-list is the P1 consent; extending the
        // first-launch ledger to bundle roots is recorded as P4 hardening in
        // the program report, and keeping this call in place means that
        // widening is a recorder change, not a call-site hunt.
        //
        // A broken bundle file REFUSES ITSELF, not the launch: unlike the
        // project files above, these bytes come from third-party install
        // directories, and PermissionConfigException exists to protect the
        // launch's own gating policy from silent degradation — a loud notice
        // naming the skipped file is the matching honesty here.
        foreach (self::bundles($root) as $bundle) {
            if (!\in_array($bundle->name, $trustedBundleNames, true)) {
                continue;
            }

            foreach ($bundle->hooksPaths() as $path) {
                if (!\is_file($path)) {
                    // The bare `/hooks` directory candidate is not a loadable
                    // surface; hooksPaths() lists its conventional
                    // `hooks/hooks.json` alongside it, so skipping the
                    // directory here loses no declared hooks.
                    continue;
                }

                if (!ContainedPath::below($path, $bundle->dir)) {
                    self::warnPermissionConfigInTranscript(Lang::t('bundles.hooks.uncontained', [
                        'bundle' => $bundle->name,
                        'file' => $path,
                    ]));

                    continue;
                }

                $loose = BundleChannelLoader::refuseLooseBytes($path);

                if ($loose !== null) {
                    self::warnPermissionConfigInTranscript(Lang::t('bundles.hooks.loose_bytes', [
                        'bundle' => $bundle->name,
                        'file' => $path,
                        'reason' => $loose,
                    ]));

                    continue;
                }

                try {
                    $entries = self::quarantineNewHookEntries(
                        $path,
                        self::hookFileEntries($path),
                        $quarantine,
                        [$path],
                    );

                    $hooks->loadEntries($entries, $path);

                    foreach ($entries as $entry) {
                        if (
                            ($entry['unattendedAsk'] ?? false) === true
                            && ($entry['disabled'] ?? false) !== true
                        ) {
                            HeadlessPermissionPrompt::requireUnattendedAnswer($entry['name']);
                        }
                    }
                } catch (\InvalidArgumentException | \RuntimeException $e) {
                    self::warnPermissionConfigInTranscript(Lang::t('bundles.hooks.refused', [
                        'bundle' => $bundle->name,
                        'file' => $path,
                        'error' => $e->getMessage(),
                    ]));
                }
            }
        }
    }

    /**
     * $path's hook entries, READ ONCE PER PROCESS.
     *
     * THE CHAIN A LAUNCH STARTS WITH IS THE CHAIN IT RUNS. A hook entry is a
     * shell command, and this method is reached on every hook-manager build —
     * the launch's two, plus one more for each Ctrl+P provider switch
     * ({@see \SugarCraft\Code\Chat::selectPaletteProvider()} =>
     * {@see Bootstrap::backendFor()}). Re-reading the file on each of those meant the
     * SESSION ITSELF could install hooks into itself: `Bash` is deliberately
     * not path-jailed, so one `>> ~/.candy-code/hooks.yaml` followed by a
     * provider switch put attacker-authored shell in the guard chain
     * mid-session, with no relaunch and no prompt.
     * {@see \SugarCraft\Code\Hooks\BuiltIn\ProtectFilesHook} denies the write
     * itself, but its `command` half is best-effort by nature (see that
     * method), so the read side may not depend on the write side having caught
     * it. Freezing at first read is what makes the two independent.
     *
     * The cost is stated rather than implied: a hook file EDITED BY THE USER
     * mid-session no longer takes effect until the next launch. That is the
     * same "one config source for the whole launch" commitment
     * {@see Bootstrap::agentManager()} already documents for the permission rules, and it
     * is the half of the trade that can be undone by pressing Ctrl+C.
     *
     * Keyed by path, so this does not entangle a project file with the user's,
     * and an absent file is memoised as `[]` — a file that appears mid-session
     * is exactly the case being closed.
     *
     * @return array<array{name: string, event: string, matcher: string, command: string, description: string, disabled: bool}>
     *
     * @throws PermissionConfigException when the user's own hook file is
     *         writable by other accounts, or belongs to one
     * @throws \RuntimeException|\InvalidArgumentException see {@see \SugarCraft\Code\Hooks\HookConfig::loadFromFile()}
     */
    private static function hookFileEntries(string $path): array
    {
        if (!LaunchContext::hasHookFileEntriesFor($path)) {
            // Only the USER's files. The project's are gated by
            // `trustedProjectHooks` and live in a checkout whose permissions
            // are the user's business; the user's files are the ones loaded
            // with no gate at all, on a premise that ownership is what makes
            // true — and the user's `.claude/settings.json` joins
            // `hooks.yaml` under that premise, since it now carries the same
            // class of payload (shell on a tool call).
            if ($path === self::trustedConfigDirPath() . '/hooks.yaml' || $path === self::userClaudeSettingsFile()) {
                self::requirePrivatePolicyFile($path);
            }

            LaunchContext::rememberHookFileEntries($path, ClaudeSettingsHooks::isClaudeSettingsPath($path)
                ? ClaudeSettingsHooks::fromFile($path, self::claudeSettingsScope($path))
                : HookConfig::loadFromFile($path));
        }

        return LaunchContext::hookFileEntriesFor($path);
    }

}
