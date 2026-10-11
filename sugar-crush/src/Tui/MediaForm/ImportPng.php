<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui\MediaForm;

use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Media\Capability\EndpointFamily;
use SugarCraft\Crush\Media\ImageIngress;
use SugarCraft\Crush\Media\Infotext;
use SugarCraft\Crush\Media\MediaRequest;
use SugarCraft\Crush\Media\Png;
use SugarCraft\Crush\Media\Sd\Client;
use SugarCraft\Crush\Media\Sd\Response;
use SugarCraft\Crush\Media\Sd\SdTransport;
use SugarCraft\Crush\Providers\ProviderFactory;

/**
 * The PNG-info import channel (crush-media W4.4).
 *
 * One law, three doors: a saved generation carries its parameters in the
 * PNG's own tEXt/iTXt chunks, so re-loading it is LOCAL-FIRST — {@see
 * Png::readText()} reads the `parameters` chunk and {@see Infotext::applyTo()}
 * folds it over the draft under the onlyUnset law (a value the user already
 * set on the draft outranks the file's, W1.6 step 5). Only when the chunk is
 * absent does the server's `POST /sdapi/v1/png-info` helper get consulted
 * (plan §4.4, the legacy-map path), and only when this launch actually
 * configures an SD base over a family the import speaks — the zero-dial law
 * {@see \SugarCraft\Crush\Tools\BuiltIn\GenerateImage} follows, mirrored
 * here: an unconfigured or unimplemented profile answers null without ever
 * opening a socket. Neither channel → the loud refusal row (§2.14), never a
 * silently empty form.
 *
 * The three doors that consume this class:
 * - the media form's `i` rune ({@see MediaForm::importAttachedPng()}) imports
 *   the PNG attached on the Image tab;
 * - `/sendto <file.png>` ({@see \SugarCraft\Crush\Commands\SendToCommand})
 *   imports a file from disk straight into a fresh form draft;
 * - {@see self::fromInfotext()} is the paste-params channel: an infotext
 *   string copied out of any A1111 tool folds over a draft locally. v1
 *   resolves it here rather than through the API's `infotext` field
 *   (plan §4.2) — the server-side twin is documented divergence, not a gap.
 *
 * Remote PNGs are never fetched for import: like {@see ImageIngress}, every
 * path in this family is local-only by law.
 */
final class ImportPng
{
    /** Which channel produced the infotext the draft was folded from. */
    public const string VIA_LOCAL = 'local';
    public const string VIA_SERVER = 'server';

    /** Row text for "no PNG to import from" — the form door's empty state. */
    public const string NO_ATTACH = 'no PNG attached to import from — attach one on the Image tab first, or run /sendto <file.png>';

    /** Row text for non-PNG bytes; no chunk read, no dial, per the import law. */
    public const string NOT_PNG = 'the attached file is not a PNG — the infotext import reads PNG tEXt/iTXt chunks only';

    /** Row text when neither the local chunk nor the server helper answers. */
    public const string NO_PARAMS = 'this PNG carries no parameters chunk and the server png-info fallback is unavailable on this launch — nothing was imported';

    /** Row-text head for a completed import; the form appends file and via. */
    public const string ATTACHED = 'parameters imported';

    /** Human label for {@see self::VIA_LOCAL}, used in status rows. */
    public const string VIA_LOCAL_LABEL = 'local chunk';

    /** Human label for {@see self::VIA_SERVER}, used in status rows. */
    public const string VIA_SERVER_LABEL = 'server png-info';

    private function __construct()
    {
    }

    /**
     * The local channel alone: the `parameters` chunk of a PNG, or null when
     * the bytes are not a PNG, carry no such chunk, or it is blank.
     */
    public static function extract(?string $bytes): ?string
    {
        if ($bytes === null || !Png::isPng($bytes)) {
            return null;
        }

        $text = Png::readText($bytes, Png::KEYWORD_PARAMETERS);

        return ($text === null || trim($text) === '') ? null : $text;
    }

    /**
     * The paste-params door: fold an infotext string over a draft locally,
     * under the same onlyUnset law the chunk import uses. Never throws —
     * {@see Infotext::parse()} tolerates legacy and unknown keys by design,
     * dropping server-sourced secret-shaped ones per {@see Infotext::isSecretKey()}.
     */
    public static function fromInfotext(string $text, MediaRequest $draft): MediaRequest
    {
        return Infotext::applyTo($draft, Infotext::parse($text), onlyUnset: true);
    }

    /**
     * Local-first import of a PNG's parameters over $draft.
     *
     * @param ?callable(string):?string $serverInfo base64 PNG => infotext
     *        string (or null when unavailable); production passes {@see
     *        self::serverInfoFor()}, tests pass doubles. Never invoked for
     *        non-PNG bytes — the chunk law and the dial law are one guard.
     * @return array{request: ?MediaRequest, error: ?string, via: ?string}
     *         exactly one of request/error is set; via names the channel.
     */
    public static function import(string $bytes, MediaRequest $draft, ?callable $serverInfo = null): array
    {
        if (!Png::isPng($bytes)) {
            return self::failure(self::NOT_PNG);
        }

        $text = self::extract($bytes);
        $via = self::VIA_LOCAL;

        if ($text === null && $serverInfo !== null) {
            $text = $serverInfo(base64_encode($bytes));
            $via = self::VIA_SERVER;
        }

        if ($text === null || trim($text) === '') {
            return self::failure(self::NO_PARAMS);
        }

        $parsed = Infotext::parse($text);
        if (!self::carriesAnything($parsed)) {
            return self::failure(self::NO_PARAMS);
        }

        return [
            'request' => Infotext::applyTo($draft, $parsed, onlyUnset: true),
            'error' => null,
            'via' => $via,
        ];
    }

    /**
     * The production server channel, zero-dial by construction: null (and no
     * socket opened) unless the profile configures an SD base AND speaks a
     * family the import runs over. Mirrors GenerateImage's capability gate —
     * a declared family the import cannot run over (png-info is sdapi-only
     * dialect, so the W7-implemented sglang still zero-dials here) or an
     * image-less mediaKinds roster refuses before any request; a
     * parsed-unknown mediaFamily stays the discovery table's business, not
     * this gate's.
     *
     * @param ?array<string, mixed> $settings authoritative when injected
     *        (tests); null reads the user config the same way the tool does.
     * @param ?SdTransport $transport dial seam (tests inject a counting
     *        double so the refusal arms are pinned non-vacuously).
     */
    public static function serverInfoFor(string $base64, ?array $settings = null, ?SdTransport $transport = null): ?string
    {
        $live = $settings ?? self::liveSettings();

        $familyRaw = $live['mediaFamily'] ?? null;
        if (\is_string($familyRaw) && trim($familyRaw) !== '') {
            $family = EndpointFamily::tryFrom(trim($familyRaw));
            // The import dials the SD-API-specific png-info route, so the
            // door here is stricter than EndpointFamily::isImplemented()
            // ever since the W7 adapter made sglang an implemented (but not
            // sdapi-speaking) family: anything other than sdapi zero-dials.
            if ($family !== null && $family !== EndpointFamily::SdApi) {
                return null;
            }
        }

        if (\array_key_exists('mediaKinds', $live)) {
            $kinds = ProviderFactory::configuredMediaKinds($live['mediaKinds']);
            if ($kinds !== [] && !\in_array('image', $kinds, true)) {
                return null;
            }
        }

        $base = self::configuredBase($live, $settings !== null);
        if ($base === null) {
            return null;
        }

        $apiKey = \is_string($live['sd.apiKey'] ?? null) && $live['sd.apiKey'] !== ''
            ? $live['sd.apiKey']
            : null;

        try {
            $result = Client::withBase($base, $transport, $apiKey)->pngInfo($base64);
            if (!$result->is2xx()) {
                return null;
            }

            $info = Response::pngInfo($result)->info['info'] ?? null;
        } catch (\Throwable) {
            // Any transport or protocol failure means "the fallback is
            // unavailable on this launch" — the caller's refusal row says so.
            return null;
        }

        return (\is_string($info) && trim($info) !== '') ? $info : null;
    }

    /**
     * Base resolution for the fallback. With live config ($injected false)
     * the env-over-settings precedence is {@see Client::resolveBaseUrl()}'s
     * alone; an injected settings map is authoritative for the whole hop so
     * tests cannot be steered by the developer's environment.
     *
     * @param array<array-key, mixed> $settings
     */
    private static function configuredBase(array $settings, bool $injected): ?string
    {
        if (!$injected) {
            return Client::resolveBaseUrl();
        }

        foreach ([Client::BASE_URL_CONFIG_KEY, Client::BASE_URL_MEDIA_CONFIG_KEY] as $key) {
            $value = $settings[$key] ?? null;
            if (\is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /** @return array{request: null, error: string, via: null} */
    private static function failure(string $error): array
    {
        return ['request' => null, 'error' => $error, 'via' => null];
    }

    /**
     * A parse of blank text yields only empty values; treat that as "no
     * parameters anywhere" so the refusal row stays loud instead of opening
     * a form silently unchanged.
     *
     * @param array<array-key, mixed> $parsed
     */
    private static function carriesAnything(array $parsed): bool
    {
        foreach ($parsed as $value) {
            if (\is_string($value) && trim($value) !== '') {
                return true;
            }
        }

        return false;
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
