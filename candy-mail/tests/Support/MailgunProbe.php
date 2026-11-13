<?php

declare(strict_types=1);

namespace SugarCraft\Mail\Tests\Support;

use SugarCraft\Mail\Email;
use SugarCraft\Mail\MailgunTransport;

/**
 * Test-only window onto MailgunTransport's protected wire builders.
 * The transport is final, so the probe reaches the protected builders by
 * reflection instead of subclassing.
 */
final class MailgunProbe
{
    private MailgunTransport $t;

    public function __construct(string $key, string $domain)
    {
        $this->t = new MailgunTransport($key, $domain);
    }

    /** @return array{0: string, 1: string} */
    public function multipart(Email $email): array
    {
        return (new \ReflectionMethod($this->t, 'buildMultipart'))->invoke($this->t, $email);
    }

    /** @return array<int, mixed> */
    public function options(string $boundary, string $body): array
    {
        return (new \ReflectionMethod($this->t, 'curlOptions'))->invoke($this->t, $boundary, $body);
    }
}
