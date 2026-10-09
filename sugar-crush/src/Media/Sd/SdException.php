<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Media\Sd;

use RuntimeException;

/**
 * The one failure type the media SD layer raises, shaped after the sdapi error
 * envelope (plan W1.3, citing the mystage §1.1 contract verified against the
 * A1111 tree): every sdapi error is the JSON
 * `{error, detail, body, errors}` carrying the exception's `status_code`,
 * else 500.
 *
 * Transport-level failures (connection refused, DNS, TLS) never surface raw —
 * they arrive here with status 0 and the driver message as `detail`, so a
 * caller catches exactly one class for "the SD server did not answer the way
 * we asked" whether the server answered badly or not at all.
 *
 * The raw response body rides as a FIELD, truncated, and is deliberately kept
 * OUT of the message: sdapi echoes request bodies into `body` on validation
 * errors, and a generation request can carry an override_settings blob with
 * whatever the operator parked there. Logging the message stays safe.
 */
final class SdException extends RuntimeException
{
    /** Cap on the retained echo of the server body / request (see class docblock). */
    public const MAX_RETAINED_BODY_CHARS = 2000;

    private function __construct(
        private readonly int $status,
        private readonly string $error,
        private readonly string $detail,
        private readonly string $body,
        private readonly array $errors,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * Map a non-2xx sdapi response: parse the {error, detail, body, errors}
     * envelope when present; keep the raw text when the server answered
     * without JSON (a proxy error page is still a diagnosis).
     *
     * @param array<string, mixed>|null $decoded  decoded JSON body, null if not JSON
     */
    public static function fromResponse(
        string $context,
        int $status,
        ?array $decoded,
        string $rawBody,
    ): self {
        if ($decoded === null || !self::looksLikeSdapiError($decoded)) {
            return new self(
                status: $status,
                error: '',
                detail: self::truncate($rawBody),
                body: self::truncate($rawBody),
                errors: [],
                message: $context . ': HTTP ' . $status . ' ' . self::truncate($rawBody, 200),
            );
        }

        $error = self::stringify($decoded['error'] ?? '');
        $detail = self::stringify($decoded['detail'] ?? '');
        $body = self::stringify($decoded['body'] ?? '');

        /** @var list<array<string, mixed>> $errors */
        $errors = isset($decoded['errors']) && is_array($decoded['errors'])
            ? array_values(array_filter($decoded['errors'], 'is_array'))
            : [];

        return new self(
            status: $status,
            error: $error,
            detail: $detail,
            body: self::truncate($body === '' ? self::stringify($decoded) : $body),
            errors: $errors,
            message: $context . ': HTTP ' . $status . ' ' . ($error !== '' ? $error : 'error')
                . ($detail !== '' ? ' — ' . self::truncate($detail, 200) : ''),
        );
    }

    /**
     * A transport that never produced an HTTP answer (connect refused, DNS,
     * TLS, redirect refused upstream): status 0, driver message as detail.
     */
    public static function transportFailure(string $context, \Throwable $previous): self
    {
        return new self(
            status: 0,
            error: '',
            detail: $previous->getMessage(),
            body: '',
            errors: [],
            message: $context . ': transport failure (' . $previous::class . ') ' . $previous->getMessage(),
        );
    }

    public static function protocol(string $context, string $why): self
    {
        return new self(
            status: 0,
            error: '',
            detail: $why,
            body: '',
            errors: [],
            message: $context . ': ' . $why,
        );
    }

    /** HTTP status the server reported, 0 for transport-level failures. */
    public function status(): int
    {
        return $this->status;
    }

    public function error(): string
    {
        return $this->error;
    }

    public function detail(): string
    {
        return $this->detail;
    }

    /**
     * The server-echoed request body (truncated), or the raw non-JSON response.
     * Never in the message — see class docblock.
     */
    public function rawBody(): string
    {
        return $this->body;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * True for anything shaped like the FastAPI error envelope: a string
     * 'error'/'detail' key or the pydantic 'errors' list.
     *
     * @param array<array-key, mixed> $decoded
     */
    private static function looksLikeSdapiError(array $decoded): bool
    {
        return array_key_exists('error', $decoded)
            || array_key_exists('detail', $decoded)
            || array_key_exists('errors', $decoded);
    }

    private static function stringify(mixed $value): string
    {
        if (\is_string($value)) {
            return $value;
        }

        if (\is_int($value) || \is_float($value)) {
            return (string) $value;
        }

        if ($value === null) {
            return '';
        }

        $encoded = json_encode($value);

        return $encoded === false ? print_r($value, true) : $encoded;
    }

    private static function truncate(string $text, int $max = self::MAX_RETAINED_BODY_CHARS): string
    {
        if (\strlen($text) <= $max) {
            return $text;
        }

        return substr($text, 0, $max) . '…[truncated]';
    }
}
