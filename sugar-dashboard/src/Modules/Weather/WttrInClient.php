<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Modules\Weather;

use SugarCraft\Dashboard\Module\BoundedCommand;

/**
 * Fetches weather data from wttr.in.
 *
 * Uses the J1 JSON endpoint: https://wttr.in/<location>?format=j1
 *
 * The response channel is bounded (D-109): a Content-Length pre-check
 * refuses over-cap replies before their bytes are taken, and the read
 * loop aborts at the same ceiling the process channel uses
 * ({@see BoundedCommand::MAX_OUTPUT_BYTES}, 1 MiB) — a black-holed or
 * malicious endpoint must not park the fetch with unbounded memory.
 * Failures are typed: transport legs raise
 * {@see WeatherTransportFailure}, document legs raise
 * {@see WeatherParseFailure}, and an unparseable temperature becomes a
 * null reading instead of a confident 0°C.
 *
 * @see https://github.com/chubin/wttr.in#json-api
 */
final class WttrInClient implements HttpClient
{
    private const BASE_URL = 'https://wttr.in/%s?format=j1';

    /**
     * @param string $urlPattern sprintf pattern for the endpoint; the test
     *     seam lets the fetch door run against a scripted stream wrapper.
     *     Production never overrides it.
     */
    public function __construct(private readonly string $urlPattern = self::BASE_URL) {}

    public function fetch(string $location): WeatherSnapshot
    {
        $url = sprintf($this->urlPattern, rawurlencode($location));

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 5,
                'ignore_errors' => true,
                'user_agent' => 'SugarCraft/1.0 weather-module',
            ],
        ]);

        // The old @-silenced file_get_contents collapsed every transport
        // error into one generic message. Capture the real error text into
        // a typed failure instead: honest reason, still screen-safe (no
        // raw PHP warning reaches the TUI).
        $error = null;
        set_error_handler(static function (int $errno, string $message) use (&$error): bool {
            $error = $message;
            return true;
        });
        try {
            $stream = fopen($url, 'rb', false, $context);
        } finally {
            restore_error_handler();
        }

        if ($stream === false) {
            throw new WeatherTransportFailure('wttr.in request failed: ' . ($error ?? 'the stream could not be opened'));
        }

        try {
            $this->refuseDeclaredOverflow($stream);
            $body = $this->readBounded($stream);
        } finally {
            fclose($stream);
        }

        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new WeatherParseFailure('wttr.in replied with invalid JSON: ' . $e->getMessage());
        }

        if (!is_array($data)) {
            throw new WeatherParseFailure('wttr.in replied with a JSON value that is not an object');
        }

        return $this->parse($data, $location);
    }

    /**
     * Fast-fail on a declared Content-Length above the cap, before the
     * body is read at all. Only the HTTP stream exposes wrapper_data; other
     * transports fall through to the read-side bound in readBounded().
     *
     * @param resource $stream
     */
    private function refuseDeclaredOverflow($stream): void
    {
        $meta = stream_get_meta_data($stream);

        foreach ((array) ($meta['wrapper_data'] ?? []) as $header) {
            if (is_string($header) && stripos($header, 'content-length:') === 0) {
                $declared = (int) trim(substr($header, strlen('content-length:')));
                if ($declared > BoundedCommand::MAX_OUTPUT_BYTES) {
                    throw new WeatherTransportFailure(sprintf(
                        'wttr.in declared a %d-byte reply, over the %d-byte cap; refusing the body',
                        $declared,
                        BoundedCommand::MAX_OUTPUT_BYTES,
                    ));
                }
            }
        }
    }

    /**
     * Read the response in bounded chunks: the loop can never hold more
     * than the cap plus one window.
     *
     * @param resource $stream
     */
    private function readBounded($stream): string
    {
        $body = '';

        while (!feof($stream)) {
            $chunk = fread($stream, 8192);
            if ($chunk === false) {
                throw new WeatherTransportFailure('wttr.in aborted the reply mid-stream');
            }
            $body .= $chunk;
            if (strlen($body) > BoundedCommand::MAX_OUTPUT_BYTES) {
                throw new WeatherTransportFailure(sprintf(
                    'wttr.in reply exceeded the %d-byte size cap; it was discarded',
                    BoundedCommand::MAX_OUTPUT_BYTES,
                ));
            }
        }

        return $body;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function parse(array $data, string $requestedLocation): WeatherSnapshot
    {
        $current = $data['current_condition'][0] ?? [];

        $tempC = $this->parseTemp($current['temp_C'] ?? null);
        $condition = $this->extractCondition($current);
        $location = $this->extractLocation($data, $requestedLocation);

        return new WeatherSnapshot(
            tempC: $tempC,
            condition: $condition,
            location: $location,
            fetchedAt: new \DateTimeImmutable(),
        );
    }

    /**
     * An API hiccup must not render a confident 0°C (the docblock always
     * promised null; the code said zero). Unparseable readings collapse to
     * null and the card shows the UNMEASURED pattern — the same honest-
     * sentinel doctrine ProcAvailability carries on the process channel.
     */
    private function parseTemp(mixed $raw): ?float
    {
        if (is_numeric($raw)) {
            return (float) $raw;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $current
     */
    private function extractCondition(array $current): string
    {
        $desc = $current['weatherDesc'][0]['value'] ?? null;
        if (is_string($desc)) {
            return $desc;
        }
        return 'Unknown';
    }

    /**
     * @param array<string, mixed> $data
     */
    private function extractLocation(array $data, string $requested): string
    {
        $area = $data['nearest_area'][0] ?? null;
        if ($area === null) {
            return $requested === 'auto' ? 'Unknown' : $requested;
        }

        $areaName = $area['areaName'][0]['value'] ?? null;
        if (is_string($areaName)) {
            return $areaName;
        }

        $region = $area['region'][0]['value'] ?? null;
        if (is_string($region)) {
            return $region;
        }

        return $requested === 'auto' ? 'Unknown' : $requested;
    }
}
