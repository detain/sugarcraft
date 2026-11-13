<?php

declare(strict_types=1);

namespace SugarCraft\Releases;

/**
 * Pure selection over a decoded GitHub release list: which entry is "the latest"
 * for one {@see ReleaseSource}, on either channel, independent of any transport.
 *
 * STABLE: non-draft, non-prerelease releases whose tag starts with the source's
 * tag prefix, that carry BOTH required assets (phar + sha256 sidecar), highest by
 * {@see Version::compare()}. DEV: the single rolling `<prefix>dev` release
 * (prerelease ALLOWED, draft skipped), judged by COMMIT via {@see devDecision()},
 * never by ordering. Signature assets are REPORTED (`''` when absent) but never
 * REQUIRED here: whether one is required is a fact about the pinned-key state,
 * not about the release.
 *
 * Extracted from candy-code's `Cli/SelfUpdate`.
 */
final class ReleaseSelector
{
    private function __construct(private readonly ReleaseSource $source)
    {
    }

    public static function for(ReleaseSource $source): self
    {
        return new self($source);
    }

    /**
     * @param array<array-key, mixed> $releases
     *
     * @return array{version: string, pharUrl: string, sidecarUrl: string, sigUrl: string, sigSidecarUrl: string}|null
     */
    public function selectRelease(array $releases): ?array
    {
        $prefix = $this->source->tagPrefix;
        $best = null;
        foreach ($releases as $release) {
            if (!\is_array($release) || ($release['draft'] ?? false) === true || ($release['prerelease'] ?? false) === true) {
                continue;
            }
            $tag = (string) ($release['tag_name'] ?? '');
            if (!\str_starts_with($tag, $prefix)) {
                continue;
            }
            $version = \substr($tag, \strlen($prefix));
            if (!Version::isComparable($version)) {
                continue;
            }
            $urls = $this->assetUrls($release['assets'] ?? []);
            if ($urls === null) {
                continue;
            }
            if ($best !== null && (Version::compare($version, $best['version']) ?? -1) <= 0) {
                continue;
            }
            $best = ['version' => $version, ...$urls];
        }

        return $best;
    }

    /**
     * The target commit rides along lower-cased ('' when the entry names none)
     * for {@see devDecision()} to judge: selection stays decidable on assets
     * alone, so a malformed target is the loud cannot-compare refusal, never a
     * silent no-release. Tag names are unique per repository, so at most one
     * entry can match; an asset-less dev entry is skipped.
     *
     * @param array<array-key, mixed> $releases
     *
     * @return array{version: string, pharUrl: string, sidecarUrl: string, sigUrl: string, sigSidecarUrl: string, commitish: string}|null
     */
    public function selectDevRelease(array $releases): ?array
    {
        foreach ($releases as $release) {
            if (!\is_array($release) || ($release['draft'] ?? false) === true) {
                continue;
            }
            if ((string) ($release['tag_name'] ?? '') !== $this->source->devTag()) {
                continue;
            }
            $urls = $this->assetUrls($release['assets'] ?? []);
            if ($urls === null) {
                continue;
            }
            $commitish = $release['target_commitish'] ?? '';

            return [
                'version' => 'dev',
                'commitish' => \is_string($commitish) ? \strtolower(\trim($commitish)) : '',
                ...$urls,
            ];
        }

        return null;
    }

    /**
     * Is the running dev build already the commit the dev release names?
     * [decision, label]: 0 up to date, 1 a newer commit is published, null
     * INDECIDABLE — the current version carries no commit to match, or the
     * release names a target that is missing or not a hex object name (a branch
     * label). Indecidable is the fail-closed shape: --check reports
     * cannot-compare, a swap refuses. A mismatch is never "behind": the rolling
     * channel answers now or not-yet.
     *
     * @param array{commitish: string} $release
     *
     * @return array{0: ?int, 1: string}
     */
    public function devDecision(string $current, array $release): array
    {
        $target = $release['commitish'];
        $label = 'dev@' . ($target === '' ? '?' : $target);
        $embedded = Version::devShaOf($current);
        if ($embedded === null || $target === '' || \preg_match(Version::HEX_SHA_PATTERN, $target) !== 1) {
            return [null, $label];
        }

        return [\str_starts_with($target, $embedded) ? 0 : 1, $label];
    }

    /**
     * The lowercase 64-hex-digit digest a sidecar signs with, or null when it is
     * not that shape. Fails closed on uppercase hex, a short digest, and anything
     * the sha256sum spelling does not produce.
     */
    public static function parseSidecar(string $body): ?string
    {
        if (\preg_match('/^([0-9a-f]{64})(?:\s+\S+)?\s*$/', \trim($body), $m) !== 1) {
            return null;
        }

        return $m[1];
    }

    /** The file name a sidecar declares after its digest ('' when it names nothing). */
    public static function sidecarName(string $body): string
    {
        if (\preg_match('/^[0-9a-f]{64}\s+(\S+)/', \trim($body), $m) !== 1) {
            return '';
        }

        return $m[1];
    }

    /**
     * The asset quartet of one release entry: the two required download routes
     * plus the two signature routes ('' when absent). Null when either required
     * asset is missing or the entry is not shaped like an asset list. The ONE walk
     * both channels read.
     *
     * @return ?array{pharUrl: string, sidecarUrl: string, sigUrl: string, sigSidecarUrl: string}
     */
    private function assetUrls(mixed $assets): ?array
    {
        if (!\is_array($assets)) {
            return null;
        }
        $pharUrl = null;
        $sidecarUrl = null;
        $sigUrl = '';
        $sigSidecarUrl = '';
        foreach ($assets as $asset) {
            if (!\is_array($asset)) {
                continue;
            }
            $url = (string) ($asset['browser_download_url'] ?? '');
            if ($url === '') {
                continue;
            }
            $name = (string) ($asset['name'] ?? '');
            if ($name === $this->source->pharAsset) {
                $pharUrl = $url;
            } elseif ($name === $this->source->sidecarAsset()) {
                $sidecarUrl = $url;
            } elseif ($name === $this->source->signatureAsset()) {
                $sigUrl = $url;
            } elseif ($name === $this->source->sidecarSignatureAsset()) {
                $sigSidecarUrl = $url;
            }
        }
        if ($pharUrl === null || $sidecarUrl === null) {
            return null;
        }

        return ['pharUrl' => $pharUrl, 'sidecarUrl' => $sidecarUrl, 'sigUrl' => $sigUrl, 'sigSidecarUrl' => $sigSidecarUrl];
    }
}
