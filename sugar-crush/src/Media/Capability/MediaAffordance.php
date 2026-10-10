<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Media\Capability;

use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Media\Sd\Client;
use SugarCraft\Crush\Media\Video\JobClient;
use SugarCraft\Crush\Providers\ProviderFactory;

/**
 * The serve-mode media flags (plan_crush_media W6.7, plan lines 838-847):
 * what a client may offer WITHOUT dialing anything.
 *
 * The plan's formula verbatim (:839): "derived from W1.8 config ∩ W1.2
 * probe", refreshed by a boot-time config read with no probe on the serve
 * boot path (:844) — so every input here is a setting or a static door,
 * never a socket. Fail-open means the advertisement can never throw and can
 * never hide the tools: an unreadable config answers flags-off, and the
 * runtime refusal with its pointed message stays the tools' own job
 * ({@see \SugarCraft\Crush\Tools\BuiltIn\GenerateImage::capabilityRefusal()}).
 *
 * The per-kind doors mirror the shipped tool gates so the flags and the
 * tools cannot drift apart:
 *
 *  - IMAGE: the family parses (or defaults to sdapi, the declared-family-less
 *    reading both tools use); `EndpointFamily::isImplemented()` is the image
 *    authority (C-1) — GenerateImage.php:345 refuses on exactly this door.
 *  - VIDEO: a family must be BOTH implemented (C-1) AND carry a recorded
 *    video job contract ({@see JobClient::videoContract()}, the W5.3 video
 *    authority — GenerateVideo.php:509). No family satisfies both today,
 *    which is the honest answer: the RPC `generate.submit` renders images
 *    only until the W7 sglang adapter lands, so a video affordance would
 *    advertise a door the serve surface cannot walk. When a lane flips
 *    either door for one family, the flags flip with it — pinned as a
 *    structural never-both test in `tests/Media/MediaAffordanceTest`.
 *  - KINDS: an absent or empty `mediaKinds` roster means "everything the
 *    host can do" — the W1.8 config-authority fail-open both tools carry
 *    (GenerateImage.php:352, GenerateVideo.php:515).
 *  - BASE URL: nothing is offered over no server — {@see Client::resolveBaseUrl()}
 *    is the single base authority (env > `sd.baseUrl` > `mediaBaseUrl`); a
 *    null answer forces both flags false.
 *
 * Privacy law (:839, "never secrets/hostnames in the clear"): the URL never
 * rides the record — only a sha256 prefix of the lowercase host, so clients
 * can group launches by server without the discovery file or the handshake
 * naming it.
 */
final class MediaAffordance
{
    /** Hex chars of the host's sha256 that ride the wire (a bucket, not a name). */
    public const HOST_HASH_CHARS = 16;

    /** @var array{image: bool, video: bool, baseUrlHostOnly: string|null}|null */
    private static ?array $bootMemo = null;

    private function __construct()
    {
    }

    /**
     * The flags for this process: resolved once from the live config on the
     * first ask (the boot-time law, plan :844), then memoised — a settings
     * edit lands at the next serve start, not mid-connection.
     *
     * @return array{image: bool, video: bool, baseUrlHostOnly: string|null}
     */
    public static function current(): array
    {
        return self::$bootMemo ??= self::flags(self::liveSettings(), Client::resolveBaseUrl());
    }

    /**
     * The pure derivation (all doors above, one place). Public as the seam
     * the tests drive table-first without touching env or the filesystem.
     *
     * @param array<array-key, mixed> $settings  the merged user config
     * @return array{image: bool, video: bool, baseUrlHostOnly: string|null}
     */
    public static function flags(array $settings, ?string $base): array
    {
        $family = self::effectiveFamily($settings);
        $permits = self::kindPermitter($settings);
        $host = $base === null ? null : self::hostOf($base);

        return [
            'image' => $host !== null && $family->isImplemented() && $permits('image'),
            'video' => $host !== null && $family->isImplemented()
                && JobClient::videoContract($family) !== null && $permits('video'),
            'baseUrlHostOnly' => $host === null ? null : \substr(\hash('sha256', $host), 0, self::HOST_HASH_CHARS),
        ];
    }

    /** Forget the boot-time answer (tests; the next {@see current()} re-reads). */
    public static function resetForTesting(): void
    {
        self::$bootMemo = null;
    }

    /**
     * The declared family, or the default both tools run under. A declared
     * family that fails to parse is not this door's business — discovery
     * diagnoses it and the tools keep their fall-through (GenerateImage.php
     * and GenerateVideo.php capabilityRefusal read the same way).
     *
     * @param array<array-key, mixed> $settings
     */
    private static function effectiveFamily(array $settings): EndpointFamily
    {
        $raw = $settings['mediaFamily'] ?? null;
        if (\is_string($raw) && \trim($raw) !== '') {
            $parsed = EndpointFamily::tryFrom(\trim($raw));
            if ($parsed !== null) {
                return $parsed;
            }
        }

        return EndpointFamily::SdApi;
    }

    /**
     * @param array<array-key, mixed> $settings
     */
    private static function kindPermitter(array $settings): callable
    {
        if (!\array_key_exists('mediaKinds', $settings)) {
            return self::permitEverything(...);
        }
        $kinds = ProviderFactory::configuredMediaKinds($settings['mediaKinds']);

        return $kinds === []
            ? self::permitEverything(...)
            : static fn (string $kind): bool => \in_array($kind, $kinds, true);
    }

    private static function permitEverything(string $kind): bool
    {
        return true;
    }

    /** The lowercase host of a base URL, bare `host:port` included; null when unparseable. */
    private static function hostOf(string $base): ?string
    {
        $host = \parse_url(\str_contains($base, '://') ? $base : '//' . $base, \PHP_URL_HOST);

        return \is_string($host) && $host !== '' ? \strtolower($host) : null;
    }

    /** @return array<array-key, mixed> */
    private static function liveSettings(): array
    {
        try {
            return Bootstrap::readUserConfig();
        } catch (\Throwable) {
            return [];
        }
    }
}
