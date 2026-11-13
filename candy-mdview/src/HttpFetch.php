<?php

declare(strict_types=1);

namespace SugarCraft\MdView;

/**
 * The URL input door (finding 008): glow's second documented invocation is
 * `glow https://example.com/README.md`; the port used to answer
 * "no such file" because nothing in src/ knew what a scheme was.
 *
 * A fetched body is hostile remote bytes, so it is NOT a second read path:
 * the transfer is opened with a connect+read deadline and a redirect cap,
 * the FINAL status must be 2xx, and the body then passes through the same
 * {@see DocumentGate::readStream()} contract every local door obeys —
 * capped read, NUL/control-ratio binary refusal, empty refusal. Watch and
 * status header stay local-file doors (RenderCommand never arms them for a
 * remote source).
 *
 * Mirrors charmbracelet/glow's URL input (the `utils.GetFileContent` style
 * fetch feeding the same render pipeline as the file argument).
 */
final class HttpFetch
{
    /** Seconds the whole transfer may take, connect included. */
    public const TIMEOUT_SECONDS = 10.0;

    /** Followed redirects before the final answer must have landed. */
    public const MAX_REDIRECTS = 5;

    /** Scheme allow-list: the two transports glow names, nothing else. */
    public const ALLOWED_SCHEMES = '#^https?://#i';

    /**
     * No instances — this is a door, not a value.
     *
     * @codeCoverageIgnore
     */
    private function __construct()
    {
    }

    /** Whether a file argument names a remote document (glow's parity door). */
    public static function isRemote(string $arg): bool
    {
        return preg_match(self::ALLOWED_SCHEMES, $arg) === 1;
    }

    /**
     * Fetch $url under the transport caps and hand the bytes to the shared
     * document gate. Refusals leave through the usage channel: every one of
     * them is an InvalidArgumentException naming the URL (credentials from
     * its userinfo part never echoed) and the reason.
     *
     * @throws \InvalidArgumentException on scheme, transport, status or
     *         document-gate refusal
     */
    public static function fetch(string $url): string
    {
        if (self::isRemote($url) === false) {
            throw new \InvalidArgumentException(Lang::t('render.fetch_failed', [
                'url'    => self::redact($url),
                'reason' => 'only http and https URLs are fetched',
            ]));
        }

        $context = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'timeout'       => self::TIMEOUT_SECONDS,
                'max_redirects' => self::MAX_REDIRECTS + 1,
                // Non-2xx answers must reach OUR status gate with the code
                // in hand, not arrive as a bare fopen failure whose reason
                // is a laundered warning.
                'ignore_errors' => true,
                'header'        => "User-Agent: candy-mdview\r\nAccept: */*\r\n",
            ],
            'ssl' => [
                'verify_peer'       => true,
                'verify_peer_name'  => true,
                // Glow's door is TLS-verified; an unverified fetch would
                // render attacker-chosen bytes behind a pretend-HTTPS mask.
                'allow_self_signed' => false,
            ],
        ]);

        $handle = @fopen($url, 'rb', false, $context);
        if ($handle === false) {
            $last = error_get_last()['message'] ?? '';
            throw new \InvalidArgumentException(Lang::t('render.fetch_failed', [
                'url'    => self::redact($url),
                'reason' => self::redact(self::transportReason($last)),
            ]));
        }

        try {
            self::requireOkStatus($url, $handle);

            return DocumentGate::readStream($handle, self::redact($url));
        } catch (\InvalidArgumentException $gate) {
            // The gate's empty refusal speaks of standard input; a URL
            // answer deserves the door it came through. Oversize and
            // binary refusals are already label-generic and pass through.
            if ($gate->getMessage() === Lang::t('render.no_input_empty')) {
                throw new \InvalidArgumentException(Lang::t('render.fetch_failed', [
                    'url'    => self::redact($url),
                    'reason' => 'the body is empty',
                ]));
            }

            throw $gate;
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    /**
     * The status line of the FINAL response — a redirected chain has one
     * header block per hop and the last is the answer. A body served behind
     * 3xx/4xx/5xx is never rendered: an HTML error page dressed as
     * Markdown is how a hostile gateway teaches a viewer to paint garbage.
     *
     * @param resource $handle
     */
    private static function requireOkStatus(string $url, $handle): void
    {
        $code  = 0;
        $final = '';
        foreach ((array) (stream_get_meta_data($handle)['wrapper_data'] ?? []) as $line) {
            if (is_string($line) && preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                $code  = (int) $m[1];
                $final = $line;
            }
        }

        if ($code < 200 || $code >= 300) {
            throw new \InvalidArgumentException(Lang::t('render.fetch_failed', [
                'url'    => self::redact($url),
                'reason' => $code === 0
                    ? 'the server answered without an HTTP status'
                    : 'HTTP ' . $final,
            ]));
        }
    }

    /** Trim a raw stream warning down to the sentence a user needs. */
    private static function transportReason(string $message): string
    {
        $message = trim(preg_replace('/\s+/', ' ', strip_tags($message)) ?? '');

        return preg_replace('/^fopen\(.*?\):\s*/', '', $message) ?: 'connection failed';
    }

    /**
     * Strip userinfo credentials from any URL echo. Refusals print on the
     * user's terminal; a URL pasted from a password manager must not come
     * back wearing its secrets.
     */
    private static function redact(string $url): string
    {
        return (string) preg_replace('#(://)[^/@]*@#', '$1***@', $url);
    }
}
