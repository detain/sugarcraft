<?php

declare(strict_types=1);

namespace SugarCraft\Code\Providers;

use GuzzleHttp\HandlerStack;
use SugarCraft\Code\Providers\Concerns\HttpClientDefaults;
use SugarCraft\Code\Tui\Components\PaneLabel;

/**
 * PRV-1 (§6.5): one bounded `GET {root}/v1/models` against an
 * OpenAI-compatible endpoint — the listing route `openai`, `custom` and
 * `sglang` all speak — shared by every {@see HasModelCatalog} provider and
 * by `models all`.
 *
 * NEVER BLOCKS THE TUI, NEVER THROWS: an unreachable host, a 401, a proxy
 * without the route, a timeout or a non-JSON body all answer null, which
 * callers render as "no live tier" — the same degradation contract as
 * ({@see SglangServerInfo::discover()}, including the Bearer key when one
 * is configured) — only the route differs, `models` beside the base's own
 * `chat/completions`.
 *
 * A TOTAL TIMEOUT IS RIGHT HERE and would be wrong on a completion: this is
 * a one-request metadata read, not an LLM call
 * ({@see Concerns\HttpClientDefaults}), so `ProviderConnectTimeoutTest`
 * lists this file among its metadata-read exemptions by name.
 *
 * The remote server is UNTRUSTED input: {@see decode()} sweeps every id
 * through {@see PaneLabel::of()}, so no raw escape or control byte from
 * the `/v1/models` wire reaches the palette rows or the `models all`
 * table (see the method's CONTAINMENT note for why the choke sits at
 * ingest rather than at paint). The community-table tier of the same
 * lists is swept at its own source, {@see ModelMetadata::candidates()}:
 * a JSON `\u001b`-escaped control byte is well-formed, so only the raw
 * form is refused by the parse.
 *
 * This is a SugarCraft architecture class, not a port: no
 * `Mirrors charmbracelet/...` citation attaches to it.
 */
final class OpenAiModelsEndpoint
{
    use HttpClientDefaults;

    /**
     * The listing route relative to the configured base, exactly as
     * `chat/completions` is for these transports: the base IS the version
     * root (`https://api.openai.com/v1`, `https://host/v1`), so servers
     * mounted at a deeper prefix (`https://proxy/sglang/v1`) resolve too,
     * and a base without `/v1` still asks beside its own completions route.
     */
    private const MODELS_PATH = 'models';

    /**
     * The same discovery budget {@see SglangServerInfo} uses: a dead host is
     * discovered fast, and a slow answer is not worth stalling a palette
     * open or a CLI listing.
     */
    public const DISCOVERY_TIMEOUT_SECONDS = 3.0;

    /**
     * @param (\Closure(): mixed)|null $handler A Guzzle handler, for tests;
     *        the default transport otherwise.
     * @return list<string>|null the ids in the server's own order, null on any failure
     */
    public static function fetch(string $baseUrl, ?string $apiKey = null, ?callable $handler = null): ?array
    {
        try {
            $headers = ['Accept' => 'application/json'];
            if ($apiKey !== null && $apiKey !== '') {
                $headers['Authorization'] = 'Bearer ' . $apiKey;
            }

            // Through the shared seam like every provider client, with both
            // bounds pinned to the discovery budget. Trailing-slash the
            // base_uri so a relative request URI resolves against the FULL
            // path — the same rule each transport's chat call rides.
            $client = self::guzzleClient([
                'base_uri' => rtrim($baseUrl, '/') . '/',
                'handler' => $handler === null ? HandlerStack::create() : HandlerStack::create($handler),
                'headers' => $headers,
                'connect_timeout' => self::DISCOVERY_TIMEOUT_SECONDS,
                'timeout' => self::DISCOVERY_TIMEOUT_SECONDS,
                'http_errors' => true,
            ]);

            return self::decode((string) $client->get(self::MODELS_PATH)->getBody());
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * `{"data":[{"id": "..."}]}` reduced to the non-empty string ids. A body
     * that is not that shape — including a valid JSON answer with no usable
     * rows — is the same "unknown" as a failed transport, never a partial
     * catalog presented as a complete one.
     *
     * CONTAINMENT (plan-reverify-1026 fx/palette-sec): every id is swept
     * through {@see PaneLabel::of()} HERE, at the single point a remote
     * server's bytes enter the process, not at each paint site. Both
     * consumers — the Ctrl+P model palette (via {@see ModelCatalog}) and the
     * `models all` table/JSON — read this list, and the palette paints row
     * bodies raw: an unswept id carrying OSC-8 or SGR would ride the wire
     * verbatim (a hostile configured endpoint could then forge hyperlinks
     * or repaint the frame). Sweeping before {@see ModelCatalog::prefetch()}
     * memoises is what keeps the highlight indices {@see \SugarCraft\Fuzzy\Highlighter}
     * later computes aligned with the label actually painted — sanitising at
     * paint time would desynchronise the matched-character runs. Model ids
     * are conventionally control- and space-free, so honest entries
     * round-trip byte-identical; a relay that does name a model with
     * spacing still lists, it simply folds to one row like any other
     * label, and only a sweep that empties a row drops it, like any other
     * unusable id.
     *
     * @return list<string>|null
     */
    public static function decode(string $body): ?array
    {
        /** @var mixed $decoded */
        $decoded = json_decode($body, true);

        if (!\is_array($decoded) || !isset($decoded['data']) || !\is_array($decoded['data'])) {
            return null;
        }

        $ids = [];
        foreach ($decoded['data'] as $row) {
            if (!\is_array($row) || !isset($row['id']) || !\is_string($row['id']) || $row['id'] === '') {
                continue;
            }

            $label = PaneLabel::of($row['id']);
            if ($label !== '') {
                $ids[] = $label;
            }
        }

        return $ids === [] ? null : $ids;
    }
}
