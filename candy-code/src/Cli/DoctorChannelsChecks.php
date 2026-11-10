<?php

declare(strict_types=1);

namespace SugarCraft\Code\Cli;

use SugarCraft\Code\Bundles\Bundle;
use SugarCraft\Code\Bundles\BundleDiscovery;
use SugarCraft\Code\Channels\BundleChannelLoader;
use SugarCraft\Code\Channels\ChannelInboundLog;
use SugarCraft\Code\Channels\ChannelsStore;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Support\ContainedPath;

/**
 * The three channel/bundle legs of `doctor`, kept out of DoctorChecks so the
 * roster file stays a thin seat (SourceSizeRatchet) and its own no-read law
 * (exactly one `file_get_contents` for `config file`) stays pinned.
 *
 * Same two laws as the roster, harder: READ-ONLY — these legs call no writer
 * (never ChannelsStore::recordEntrypoint(), never BundleChannelLoader::load(),
 * never ChannelInboundLog::rows()), require no files, and open no handles
 * beyond the one channels.json byte read that exists to diagnose the silent
 * JSON swallow in ChannelsStore::read(). NO NETWORK — trust-state inspection
 * only; a Discord/Teams dial would prove the creds work, not that the state
 * is sound, and doctor answers the second question only.
 *
 * Redaction law: pairing codes, app passwords, and tenant ids live in
 * channels.json and NEVER leave these rows — details carry paths, file modes,
 * and counts only. Bundle and trusted names are configuration the operator
 * typed, so they print; HOME paths are doctor-appropriate evidence.
 */
final class DoctorChannelsChecks
{
    /**
     * `channels store` — the 0600 cred store: presence, readability, mode,
     * and (loudly) corruption. A corrupt store reads as [] at boot; without
     * this row that failure is invisible until "my channels vanished".
     */
    public static function channelsStoreRow(?callable $configDir = null): array
    {
        $dir = self::resolveDir($configDir);
        if ($dir === '') {
            return ['status' => 'OK', 'detail' => Lang::t('cli.doctor.channels_store_unresolved')];
        }

        $path = $dir . '/' . ChannelsStore::FILE_NAME;
        if (!is_file($path)) {
            return ['status' => 'OK', 'detail' => Lang::t('cli.doctor.config_absent', ['path' => $path])];
        }
        if (!is_readable($path)) {
            return ['status' => 'FAIL', 'detail' => Lang::t('cli.doctor.config_unreadable', ['path' => $path])];
        }
        $mode = self::looseModeSuffix($path);
        if ($mode !== null) {
            return ['status' => 'FAIL', 'detail' => Lang::t('cli.doctor.file_loose_mode', ['path' => $path, 'mode' => $mode])];
        }

        // The ONLY content read in this class. ChannelsStore::read() answers a
        // broken file with [] and no sound at all, so the diagnosis is made
        // here from raw bytes instead of inherited silence.
        $raw = (string) @file_get_contents($path);
        if (trim($raw) === '') {
            return ['status' => 'FAIL', 'detail' => Lang::t('cli.doctor.channels_store_empty', ['path' => $path])];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ['status' => 'FAIL', 'detail' => Lang::t('cli.doctor.config_invalid', [
                'path' => $path,
                'error' => json_last_error_msg(),
            ])];
        }

        $ledger = isset($decoded['seenEntrypoints']) && is_array($decoded['seenEntrypoints'])
            ? $decoded['seenEntrypoints']
            : [];
        return ['status' => 'OK', 'detail' => Lang::t('cli.doctor.channels_store_ok', [
            'path' => $path,
            'channels' => (string) (count($decoded) - (isset($decoded['seenEntrypoints']) ? 1 : 0)),
            'ledger' => (string) count($ledger),
        ])];
    }

    /**
     * `channel bundles` — read-only preview of next launch's quarantine:
     * stale trusted names, entry points that escape their bundle or sit on
     * loose bytes, and first-sight entry points the loader would hold back.
     * Mirrors BundleChannelLoader's decision order without its writes or its
     * require_once — the loader executes quarantined bytes only AFTER they
     * were seen clean twice; doctor never executes anything at all.
     *
     * @param list<Bundle>|null $bundles      fixture seam; null = live discovery
     * @param list<string>|null $trustedNames fixture seam; null = frozen config read
     */
    public static function channelBundlesRow(
        ?string $root = null,
        ?array $bundles = null,
        ?array $trustedNames = null,
        ?ChannelsStore $store = null,
    ): array {
        $trusted = $trustedNames ?? Bootstrap::trustedBundleChannelNames();
        if ($trusted === []) {
            return ['status' => 'OK', 'detail' => Lang::t('cli.doctor.bundles_dark')];
        }

        if ($bundles === null) {
            $paths = Bootstrap::readUserConfig()[BundleDiscovery::SETTING_KEY] ?? [];
            $bundles = (new BundleDiscovery($root ?? '', is_array($paths) ? $paths : []))->discover();
        }
        $store ??= ChannelsStore::create();

        $byName = [];
        foreach ($bundles as $bundle) {
            $byName[$bundle->name] = $bundle;
        }
        $stale = array_values(array_diff($trusted, array_keys($byName)));

        $entryPoints = 0;
        $escaping = 0;
        $loose = [];
        $unarmed = 0;
        foreach ($trusted as $name) {
            $bundle = $byName[$name] ?? null;
            if ($bundle === null) {
                continue;
            }
            foreach ($bundle->entrypoints()['channel'] ?? [] as $entry) {
                ++$entryPoints;
                if (!ContainedPath::below($entry['path'], $bundle->dir)) {
                    ++$escaping;
                    continue;
                }
                $reason = BundleChannelLoader::refuseLooseBytes($entry['path']);
                if ($reason !== null) {
                    $loose[] = $reason;
                    continue;
                }
                if (!$store->seenEntrypoint($entry['path'])) {
                    ++$unarmed;
                }
            }
        }

        $problems = [];
        if ($stale !== []) {
            $problems[] = Lang::t('cli.doctor.bundles_stale', [
                'count' => (string) count($stale),
                'names' => implode(', ', $stale),
            ]);
        }
        if ($escaping > 0) {
            $problems[] = Lang::t('cli.doctor.bundles_escape', ['count' => (string) $escaping]);
        }
        if ($loose !== []) {
            $problems[] = Lang::t('cli.doctor.bundles_loose', [
                'count' => (string) count($loose),
                'reasons' => implode(', ', array_unique($loose)),
            ]);
        }
        if ($unarmed > 0) {
            $problems[] = Lang::t('cli.doctor.bundles_first_sight', ['count' => (string) $unarmed]);
        }
        if ($problems !== []) {
            return ['status' => 'WARN', 'detail' => implode('; ', $problems)];
        }
        return ['status' => 'OK', 'detail' => Lang::t('cli.doctor.bundles_ok', ['count' => (string) $entryPoints])];
    }

    /**
     * `inbound log` — the durable inbound ledger's posture: presence, mode
     * (the 0600 law), and how many `.corral-*` quarantine siblings it has
     * left behind. Names only, never rows(): the corralled bytes failed to
     * parse once and must not be re-parsed to answer a status question.
     */
    public static function inboundLogRow(?callable $configDir = null): array
    {
        $dir = self::resolveDir($configDir);
        if ($dir === '') {
            return ['status' => 'OK', 'detail' => Lang::t('cli.doctor.channels_store_unresolved')];
        }

        $path = $dir . '/' . ChannelInboundLog::FILE_NAME;
        $corrals = 0;
        foreach (is_dir($dir) ? (array) @scandir($dir) : [] as $entry) {
            if (is_string($entry) && str_starts_with($entry, ChannelInboundLog::FILE_NAME . '.corral-')) {
                ++$corrals;
            }
        }

        if (!is_file($path)) {
            if ($corrals > 0) {
                return ['status' => 'WARN', 'detail' => Lang::t('cli.doctor.inbound_corralled', [
                    'count' => (string) $corrals,
                    'path' => $path,
                ])];
            }
            return ['status' => 'OK', 'detail' => Lang::t('cli.doctor.config_absent', ['path' => $path])];
        }
        if (!is_readable($path)) {
            return ['status' => 'FAIL', 'detail' => Lang::t('cli.doctor.config_unreadable', ['path' => $path])];
        }
        $mode = self::looseModeSuffix($path);
        if ($mode !== null) {
            return ['status' => 'FAIL', 'detail' => Lang::t('cli.doctor.file_loose_mode', ['path' => $path, 'mode' => $mode])];
        }
        if ($corrals > 0) {
            return ['status' => 'WARN', 'detail' => Lang::t('cli.doctor.inbound_corralled', [
                'count' => (string) $corrals,
                'path' => $path,
            ])];
        }
        return ['status' => 'OK', 'detail' => Lang::t('cli.doctor.inbound_ok', [
            'path' => $path,
            'bytes' => self::bytes((int) filesize($path)),
        ])];
    }

    /** The trusted config dir, same seam shape as ChannelsStore::create(). */
    private static function resolveDir(?callable $configDir): string
    {
        return trim((string) ($configDir === null ? Bootstrap::trustedConfigDirPath() : $configDir()));
    }

    /** Octal tail ('0644') when group/world bits are set, null when strict. */
    private static function looseModeSuffix(string $path): ?string
    {
        $perms = @fileperms($path);
        if ($perms === false || ($perms & 0077) === 0) {
            return null;
        }
        return substr(sprintf('0%o', $perms & 0777), -4);
    }

    /** The `config file` row's byte shape, kept local so DoctorChecks stays sealed. */
    private static function bytes(int $count): string
    {
        if ($count >= 1048576) {
            return round($count / 1048576, 1) . ' MiB';
        }
        if ($count >= 1024) {
            return round($count / 1024, 1) . ' KiB';
        }
        return $count . ' B';
    }
}
