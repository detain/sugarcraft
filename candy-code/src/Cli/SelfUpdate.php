<?php

declare(strict_types=1);

namespace SugarCraft\Code\Cli;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\TransferException;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Support\Version;

/**
 * `candy-code self-update` — replace the running phar with the latest
 * published one, verified before the swap, with the pre-swap copy kept for a
 * one-shot rollback (plan_comparison DST-3).
 *
 *     self-update [--check] [--rollback]
 *
 * PHAR-MODE ONLY. The verb acts on the file `bin/candy-code` was launched
 * from: `\Phar::running(false)`. On a Composer install that answer is the
 * empty string, and this command REFUSES LOUDLY — prints the exact
 * `composer update sugarcraft/candy-code` to run instead and exits 1. A
 * silent no-op on the Composer path is the banned failure mode: it would
 * report success while changing nothing.
 *
 * WHAT "LATEST" MEANS HERE. The release trains out of detain/sugarcraft are
 * per-library tags (`candy-code/v0.1.0`, `sugar-core/v3.2.0`, …) sharing one
 * GitHub release stream, so the repo-wide `/releases/latest` answer is
 * usually some other library's tag. The lookup is the one-page list
 * (`?per_page=100`), filtered to non-draft, non-prerelease releases whose
 * tag starts with `candy-code/`, that carry BOTH assets
 * (`candy-code.phar` and `candy-code.phar.sha256`), and highest by
 * {@see Version::compare()} — the newest CANDY-CODE release, not the newest
 * release.
 *
 * HOW THE SWAP HAPPENS. The sidecar is fetched first and must parse to a
 * lowercase 64-hex-digit digest (its optional name field must match the phar
 * asset it signs). The phar is then downloaded to a temp file IN THE PHAR'S
 * OWN DIRECTORY (a rename only exchanges atoms inside one filesystem), its
 * sha256 is computed over the finished file and compared with
 * `hash_equals()`, and only then is the running phar copied to
 * `<phar>.old` and the temp renamed over it. Every refusal before the rename
 * deletes the temp and leaves the running phar byte-identical; a mismatch
 * that fires after the copy still leaves `.old` as the exact prior bytes.
 * `--rollback` renames `<phar>.old` back over the phar — consuming it in the
 * rename, so the restore is one-shot by construction and touches no network.
 *
 * TRUST MODEL, HONESTLY. Both the phar and its sidecar come from the same
 * origin (GitHub release assets, over TLS), and nothing in this repo pins a
 * signing key. The hash therefore bounds transport corruption, asset mixups
 * and tampering with the phar ALONE; a party who controls the release can
 * replace the sidecar in the same breath, and no locally pinned hash they
 * cannot also change would notice. A fixed embedded key was rejected on the
 * same grounds: a key this command cannot rotate is ceremony, not security —
 * rotation is exactly the capability that makes a pinned key load-bearing.
 * The residual is named: trust in the GitHub account that owns the repo.
 *
 * EXITS. 0: up to date (--check or not), a verified swap completed, a
 * rollback restored. 1: composer-mode refusal, an unwritable target, a
 * refused downgrade, a comparison that cannot be made, a fetch or hash
 * failure, --check finding an update available (that is the scripted answer
 * to "should I update?"). 2: usage errors (unknown operand, `--check`
 * together with `--rollback`).
 *
 * TIME BOUNDS follow the E646 rule: a connect ceiling and a per-read
 * ceiling, deliberately no total request timeout — a 100 MB phar download on
 * a slow link is legitimate work that a blanket clock would abort. The read
 * ceiling is what kills a stalled transfer.
 *
 * The $http / $releasesUrl / $pharPath / $currentVersion parameters exist for
 * the suite: `SelfUpdateTest` mounts a fake release server as a Guzzle
 * handler on a real client's middleware stack (canned release JSON and asset
 * bytes over canonical http routes, zero sockets, zero real network), swaps
 * a stub archive standing in for the phar, and names the installed version so
 * the ordering arms are reachable without a real tag checkout.
 * {@see useErrorStreamForTesting} redirects the report lines. Production
 * dispatch supplies none of them.
 */
final class SelfUpdate
{
    /**
     * Every candy-code release tag on the shared repo stream starts with
     * this; the tag's remainder is the version {@see Version} can order.
     */
    public const TAG_PREFIX = 'candy-code/';

    public const PHAR_ASSET = 'candy-code.phar';

    public const SIDECAR_ASSET = 'candy-code.phar.sha256';

    /**
     * The release list of the repository that publishes this tool's phar.
     */
    public const RELEASES_URL = 'https://api.github.com/repos/detain/sugarcraft/releases?per_page=100';

    /**
     * The command a Composer-installed candy-code must run INSTEAD of this
     * verb — printed verbatim in the refusal, which is the point of
     * refusing loudly.
     */
    public const COMPOSER_COMMAND = 'composer update sugarcraft/candy-code';

    private const CONNECT_TIMEOUT_SECONDS = 5.0;

    private const READ_TIMEOUT_SECONDS = 60.0;

    /**
     * Test-only stand-in for STDERR so the suite can read the report lines;
     * null means the real stream. Swapped via {@see useErrorStreamForTesting}.
     */
    private static mixed $errorStream = null;

    /**
     * Routes this verb's stderr lines to $stream (pass null to hand the
     * stream back). The suite reads the refusal/mismatch wording this way
     * without spawning a child process.
     */
    public static function useErrorStreamForTesting(mixed $stream): void
    {
        self::$errorStream = $stream;
    }

    private function __construct()
    {
    }

    public static function run(
        ParsedArgs $args,
        ?ClientInterface $http = null,
        ?string $releasesUrl = null,
        ?string $pharPath = null,
        ?string $currentVersion = null,
    ): int {
        $check = isset($args->subcommandFlags['--check']);
        $rollback = isset($args->subcommandFlags['--rollback']);
        if ($check && $rollback) {
            return NonInteractive::failUsage(
                'candy-code: ' . Lang::t('cli.selfupdate.flags_exclusive'),
                $args->outputFormat,
                Lang::t('cli.selfupdate.usage'),
            );
        }
        if ($args->subcommandArgs !== []) {
            return NonInteractive::failUsage(
                'candy-code: ' . Lang::t('cli.selfupdate.unexpected_operand', ['operand' => (string) $args->subcommandArgs[0]]),
                $args->outputFormat,
                Lang::t('cli.selfupdate.usage'),
            );
        }

        // null => ask the runtime what phar (if any) we are inside. A test
        // passes the stub it wants swapped; production never does.
        $phar = $pharPath ?? \Phar::running(false);
        if ($phar === '') {
            self::stderr(self::composerRefusal());

            return NonInteractive::EXIT_FAILURE;
        }

        // Spec (5): name the write problem before anything is downloaded —
        // a refusal after the fetch would waste the bytes it cannot install.
        $dir = \dirname($phar);
        if (!\is_dir($dir) || !\is_writable($dir)) {
            self::stderr(Lang::t('cli.selfupdate.dir_not_writable', ['dir' => $dir]));

            return NonInteractive::EXIT_FAILURE;
        }
        if (!\is_file($phar) || !\is_writable($phar)) {
            self::stderr(Lang::t('cli.selfupdate.phar_not_writable', ['path' => $phar]));

            return NonInteractive::EXIT_FAILURE;
        }

        $old = $phar . '.old';
        if ($rollback) {
            return self::rollback($old, $phar);
        }

        $http ??= self::httpClient();
        try {
            $response = $http->request('GET', $releasesUrl ?? self::RELEASES_URL);
        } catch (TransferException $e) {
            self::stderr(Lang::t('cli.selfupdate.fetch_failed', ['reason' => $e->getMessage()]));

            return NonInteractive::EXIT_FAILURE;
        }
        if ($response->getStatusCode() >= 400) {
            self::stderr(Lang::t('cli.selfupdate.fetch_failed', ['reason' => 'HTTP ' . $response->getStatusCode()]));

            return NonInteractive::EXIT_FAILURE;
        }
        $decoded = \json_decode((string) $response->getBody()->getContents(), true);
        $release = \is_array($decoded) ? self::selectRelease($decoded) : null;
        if ($release === null) {
            self::stderr(Lang::t('cli.selfupdate.no_release', ['phar' => self::PHAR_ASSET, 'sidecar' => self::SIDECAR_ASSET]));

            return NonInteractive::EXIT_FAILURE;
        }

        $current = $currentVersion ?? \trim(Help::versionString());
        $cmp = Version::compare($release['version'], $current);

        if ($check) {
            if ($cmp === null) {
                self::stderr(Lang::t('cli.selfupdate.check_unknown', ['current' => $current, 'latest' => $release['version']]));

                return NonInteractive::EXIT_FAILURE;
            }
            if ($cmp > 0) {
                self::stderr(Lang::t('cli.selfupdate.check_available', ['current' => $current, 'latest' => $release['version']]));

                return NonInteractive::EXIT_FAILURE;
            }
            self::stderr(Lang::t('cli.selfupdate.check_up_to_date', ['current' => $current, 'latest' => $release['version']]));

            return NonInteractive::EXIT_OK;
        }

        if ($cmp === null) {
            self::stderr(Lang::t('cli.selfupdate.uncomparable', ['current' => $current]));

            return NonInteractive::EXIT_FAILURE;
        }
        if ($cmp === 0) {
            self::stderr(Lang::t('cli.selfupdate.up_to_date', ['current' => $current]));

            return NonInteractive::EXIT_OK;
        }
        if ($cmp < 0) {
            // The sanctioned downgrade path is --rollback, which returned
            // above without consulting the network at all.
            self::stderr(Lang::t('cli.selfupdate.downgrade_refused', ['current' => $current, 'latest' => $release['version']]));

            return NonInteractive::EXIT_FAILURE;
        }

        // Sidecar first: a shape it cannot answer with stops the verb before
        // a single phar byte is transferred.
        try {
            $sidecarBody = (string) $http->request('GET', $release['sidecarUrl'])->getBody()->getContents();
        } catch (TransferException $e) {
            self::stderr(Lang::t('cli.selfupdate.download_failed', ['asset' => self::SIDECAR_ASSET, 'reason' => $e->getMessage()]));

            return NonInteractive::EXIT_FAILURE;
        }
        $expected = self::parseSidecar($sidecarBody);
        if ($expected === null) {
            self::stderr(Lang::t('cli.selfupdate.sidecar_malformed', ['asset' => self::SIDECAR_ASSET, 'phar' => self::PHAR_ASSET]));

            return NonInteractive::EXIT_FAILURE;
        }
        $named = self::sidecarName($sidecarBody);
        if ($named !== '' && $named !== self::PHAR_ASSET) {
            self::stderr(Lang::t('cli.selfupdate.sidecar_names', ['found' => $named, 'phar' => self::PHAR_ASSET]));

            return NonInteractive::EXIT_FAILURE;
        }

        $temp = $phar . '.tmp-' . \getmypid() . '-' . \bin2hex(\random_bytes(4));
        try {
            $http->request('GET', $release['pharUrl'], ['sink' => $temp]);
        } catch (TransferException $e) {
            self::forget($temp);
            self::stderr(Lang::t('cli.selfupdate.download_failed', ['asset' => self::PHAR_ASSET, 'reason' => $e->getMessage()]));

            return NonInteractive::EXIT_FAILURE;
        }
        if (!\is_file($temp) || \filesize($temp) === 0) {
            self::forget($temp);
            self::stderr(Lang::t('cli.selfupdate.empty_download', ['asset' => self::PHAR_ASSET]));

            return NonInteractive::EXIT_FAILURE;
        }

        // The one gate the swap cannot cross without: the bytes on disk must
        // BE the signed bytes. hash_equals, not ==, so the comparison does
        // not short-circuit on the first differing digit.
        $actual = \hash_file('sha256', $temp);
        if ($actual === false || !\hash_equals($expected, $actual)) {
            self::forget($temp);
            self::stderr(Lang::t('cli.selfupdate.hash_mismatch', [
                'expected' => $expected,
                'actual' => $actual === false ? Lang::t('cli.selfupdate.hash_unreadable') : $actual,
            ]));

            return NonInteractive::EXIT_FAILURE;
        }

        // Keep the running phar BEFORE exchanging it: without a preserved
        // copy there is nothing --rollback could restore, and a verb that
        // takes away its own undo is not one this verb wants to be.
        if (!\copy($phar, $old)) {
            self::forget($temp);
            self::stderr(Lang::t('cli.selfupdate.preserve_failed', ['old' => $old]));

            return NonInteractive::EXIT_FAILURE;
        }

        if (!\rename($temp, $phar)) {
            self::forget($temp);
            self::stderr(Lang::t('cli.selfupdate.rename_failed', ['old' => $old, 'path' => $phar]));

            return NonInteractive::EXIT_FAILURE;
        }

        self::stderr(Lang::t('cli.selfupdate.installed', [
            'path' => $phar,
            'from' => $current,
            'to' => $release['version'],
            'old' => $old,
        ]));

        return NonInteractive::EXIT_OK;
    }

    /**
     * The newest candy-code release in a decoded GitHub release list that
     * carries both assets, or null when none does.
     *
     * Pure over the decoded JSON so the selection rules — prefix, draft and
     * prerelease skipping, the two-asset requirement, numeric ordering — are
     * pinned directly, independently of any transport.
     *
     * @param array<array-key, mixed> $releases
     *
     * @return array{version: string, pharUrl: string, sidecarUrl: string}|null
     */
    public static function selectRelease(array $releases): ?array
    {
        $best = null;
        foreach ($releases as $release) {
            if (!\is_array($release) || ($release['draft'] ?? false) === true || ($release['prerelease'] ?? false) === true) {
                continue;
            }
            $tag = (string) ($release['tag_name'] ?? '');
            if (!\str_starts_with($tag, self::TAG_PREFIX)) {
                continue;
            }
            $version = \substr($tag, \strlen(self::TAG_PREFIX));
            if (!Version::isComparable($version)) {
                continue;
            }
            $pharUrl = null;
            $sidecarUrl = null;
            $assets = $release['assets'] ?? [];
            if (!\is_array($assets)) {
                continue;
            }
            foreach ($assets as $asset) {
                if (!\is_array($asset)) {
                    continue;
                }
                $url = (string) ($asset['browser_download_url'] ?? '');
                if ($url === '') {
                    continue;
                }
                if ((string) ($asset['name'] ?? '') === self::PHAR_ASSET) {
                    $pharUrl = $url;
                } elseif ((string) ($asset['name'] ?? '') === self::SIDECAR_ASSET) {
                    $sidecarUrl = $url;
                }
            }
            if ($pharUrl === null || $sidecarUrl === null) {
                continue;
            }
            if ($best !== null && (Version::compare($version, $best['version']) ?? -1) <= 0) {
                continue;
            }
            $best = ['version' => $version, 'pharUrl' => $pharUrl, 'sidecarUrl' => $sidecarUrl];
        }

        return $best;
    }

    /**
     * The lowercase 64-hex-digit digest a sidecar body signs with, or null
     * when it is not that shape. Fails closed on uppercase hex, on a short
     * digest, and on anything the sha256sum spelling does not produce.
     */
    public static function parseSidecar(string $body): ?string
    {
        if (\preg_match('/^([0-9a-f]{64})(?:\s+\S+)?\s*$/', \trim($body), $m) !== 1) {
            return null;
        }

        return $m[1];
    }

    /**
     * The file name a sidecar declares after its digest ('' when it names
     * nothing). The digest alone would verify bytes of UNKNOWN provenance;
     * the name is what ties it to the phar asset specifically.
     */
    public static function sidecarName(string $body): string
    {
        if (\preg_match('/^[0-9a-f]{64}\s+(\S+)/', \trim($body), $m) !== 1) {
            return '';
        }

        return $m[1];
    }

    /**
     * The loud composer-mode refusal, verbatim: what to run instead, stated
     * as the exact command. Public and pure so the refusal text is pinned
     * without needing a non-phar "phar" to point the swap machinery at.
     */
    public static function composerRefusal(): string
    {
        return Lang::t('cli.selfupdate.composer_mode', ['command' => self::COMPOSER_COMMAND]);
    }

    private static function rollback(string $old, string $phar): int
    {
        if (!\is_file($old)) {
            self::stderr(Lang::t('cli.selfupdate.rollback_missing', ['old' => $old]));

            return NonInteractive::EXIT_FAILURE;
        }
        if (!\rename($old, $phar)) {
            self::stderr(Lang::t('cli.selfupdate.rollback_failed', ['old' => $old, 'path' => $phar]));

            return NonInteractive::EXIT_FAILURE;
        }
        self::stderr(Lang::t('cli.selfupdate.rollback_restored', ['old' => $old, 'path' => $phar]));

        return NonInteractive::EXIT_OK;
    }

    private static function httpClient(): ClientInterface
    {
        return new Client([
            // E646: ceilings on the two blocking operations, not a clock on
            // the whole request — see the class docblock.
            'connect_timeout' => self::CONNECT_TIMEOUT_SECONDS,
            'read_timeout' => self::READ_TIMEOUT_SECONDS,
            // The suite's client asserts its own answers (status >= 400 is
            // checked by hand) instead of running on Guzzle's exception
            // magic, so the failure text is ours in both paths.
            'http_errors' => false,
            'headers' => [
                'Accept' => 'application/vnd.github+json',
                // The GitHub API rejects a request with no user agent.
                'User-Agent' => 'candy-code-self-update',
            ],
        ]);
    }

    private static function forget(string $path): void
    {
        if (\is_file($path)) {
            @\unlink($path);
        }
    }

    private static function stderr(string $text): void
    {
        if ($text !== '') {
            \fwrite(self::$errorStream ?? \STDERR, $text . "\n");
        }
    }
}
