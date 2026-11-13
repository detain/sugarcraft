<?php

declare(strict_types=1);

namespace SugarCraft\Mail\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Async\CancellationSource;
use SugarCraft\Mail\Attachment;
use SugarCraft\Mail\Email;
use SugarCraft\Mail\MailgunTransport;
use SugarCraft\Mail\Tests\Support\DialogueGate;
use SugarCraft\Mail\Tests\Support\MailgunProbe;

/**
 * CM-11: MailgunTransport. Wire shape is pinned offline through a probe
 * subclass; the response arms run against a forked one-shot fake API over
 * the baseUrl seam, so no test ever touches the network.
 */
final class MailgunTransportTest extends TestCase
{
    private function email(): Email
    {
        return new Email(
            from:    ['Me <me@example.com>'],
            to:      ['a@example.com', 'b@example.com'],
            subject: 'Hi there',
            body:    'plain body',
            cc:      ['c@example.com'],
            bcc:     ['d@example.com'],
        );
    }

    public function testNameAndEndpointForBothRegionsAndTheSeam(): void
    {
        $us = new MailgunTransport('key-1', 'mg.example.com');
        $this->assertSame('mailgun', $us->name());
        $this->assertSame('https://api.mailgun.net/v3/mg.example.com/messages', $us->endpoint());

        $eu = new MailgunTransport('key-1', 'mg.example.com', MailgunTransport::REGION_EU);
        $this->assertSame('https://api.eu.mailgun.net/v3/mg.example.com/messages', $eu->endpoint());
        $this->assertSame(
            'https://api.eu.mailgun.net/v3/mg.example.com/messages',
            (new MailgunTransport('key-1', 'mg.example.com', 'EU'))->endpoint(),
            'region is case-insensitive'
        );

        $seam = new MailgunTransport('key-1', 'mg.example.com', baseUrl: 'http://127.0.0.1:9/');
        $this->assertSame('http://127.0.0.1:9/v3/mg.example.com/messages', $seam->endpoint());
    }

    public function testConstructorRefusesHostileDomainRegionAndKey(): void
    {
        foreach (['evil.com/../x', 'a b.com', "a.com\r\nX: y", '-lead.com', 'trail.com-', 'a.com?x=1'] as $bad) {
            try {
                new MailgunTransport('key-1', $bad);
                $this->fail("domain '$bad' must be refused");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('domain', \strtolower($e->getMessage()));
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        new MailgunTransport('key-1', 'mg.example.com', 'asia');
    }

    public function testConstructorRefusesKeyShapeThatCannotRideAHeader(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new MailgunTransport("key\r\nX-Forged: 1", 'mg.example.com');
    }

    public function testKeyFallsBackToTheEnvironment(): void
    {
        \putenv('MAILGUN_API_KEY=key-from-env');
        try {
            $this->assertSame('****', (new MailgunTransport('', 'mg.example.com'))->__debugInfo()['apiKey']);
        } finally {
            \putenv('MAILGUN_API_KEY');
        }
        $this->assertSame('', (new MailgunTransport('', 'mg.example.com'))->__debugInfo()['apiKey']);
    }

    public function testDebugInfoMasksTheKeyAndSerialisationIsRefused(): void
    {
        $t = new MailgunTransport('key-secret-123', 'mg.example.com');
        $this->assertStringNotContainsString('key-secret-123', \print_r($t, true));
        $this->assertSame('mg.example.com', $t->__debugInfo()['domain']);

        try {
            \serialize($t);
            $this->fail('serialize must refuse');
        } catch (\RuntimeException) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(\RuntimeException::class);
        MailgunTransport::__set_state([]);
    }

    public function testSendRefusalsHappenBeforeAnyHttpWork(): void
    {
        $dead = 'http://127.0.0.1:1';
        foreach ([
            [new MailgunTransport('', 'mg.example.com', baseUrl: $dead), $this->email(), \InvalidArgumentException::class],
            [new MailgunTransport('key-1', '', baseUrl: $dead), $this->email(), \InvalidArgumentException::class],
            [new MailgunTransport('key-1', 'mg.example.com', baseUrl: $dead), new Email(from: [], to: ['a@example.com']), \InvalidArgumentException::class],
            [new MailgunTransport('key-1', 'mg.example.com', baseUrl: $dead), new Email(from: ['a@example.com'], to: []), \InvalidArgumentException::class],
        ] as [$t, $email, $class]) {
            try {
                $t->send($email);
                $this->fail('expected a refusal');
            } catch (\Throwable $e) {
                $this->assertInstanceOf($class, $e);
            }
        }

        $src = new CancellationSource();
        $src->cancel();
        $this->expectException(\RuntimeException::class);
        (new MailgunTransport('key-1', 'mg.example.com', baseUrl: $dead))->send($this->email(), $src->token());
    }

    public function testMultipartCarriesEveryFieldAndAttachment(): void
    {
        $email = $this->email()
            ->withHtmlBody('<b>hi</b>')
            ->withReplyTo('reply@example.com')
            ->withSignature('-- sig')
            ->withHeader('X-Campaign', 'spring')
            ->withAttachment('note.txt', \dirname(__DIR__) . '/composer.json');
        [$boundary, $body] = (new MailgunProbe('key-1', 'mg.example.com'))->multipart($email);

        $this->assertStringEndsWith('--' . $boundary . "--\r\n", $body);
        $this->assertSame(2, \substr_count($body, 'name="to"'));
        foreach ([
            'name="from"' . "\r\n\r\nMe <me@example.com>",
            'name="cc"' . "\r\n\r\nc@example.com",
            'name="bcc"' . "\r\n\r\nd@example.com",
            'name="subject"' . "\r\n\r\nHi there",
            'name="html"' . "\r\n\r\n<b>hi</b>",
            'name="h:Reply-To"' . "\r\n\r\nreply@example.com",
            'name="h:X-Campaign"' . "\r\n\r\nspring",
            'name="attachment"; filename="note.txt"',
        ] as $needle) {
            $this->assertStringContainsString($needle, $body);
        }
        $this->assertStringContainsString('plain body', $body);
        $this->assertStringContainsString('-- sig', $body);
        $this->assertStringContainsString('sugarcraft/candy-mail', $body, 'attachment bytes ride the part');
    }

    public function testInlineAttachmentRidesTheInlineFieldNamedByItsCid(): void
    {
        $email = $this->email()->withInlineAttachment(\dirname(__DIR__) . '/composer.json', 'logo@example.com');
        [, $body] = (new MailgunProbe('key-1', 'mg.example.com'))->multipart($email);

        $this->assertStringContainsString('name="inline"; filename="logo@example.com"', $body);
        $this->assertStringNotContainsString('name="attachment"', $body);
    }

    public function testFilenameQuoteCannotBreakOutOfTheDispositionHeader(): void
    {
        $att   = Attachment::fromContent('x', 'evil"name.txt');
        $email = new Email(from: ['a@example.com'], to: ['b@example.com'], body: 'b', attachments: [$att]);
        [, $body] = (new MailgunProbe('key-1', 'mg.example.com'))->multipart($email);

        $this->assertStringContainsString('filename="evil%22name.txt"', $body);
        $this->assertStringNotContainsString('filename="evil"name', $body);
    }

    public function testNoSubjectUsesTheProviderDefaultLikeResend(): void
    {
        $email = new Email(from: ['a@example.com'], to: ['b@example.com'], body: 'x');
        [, $body] = (new MailgunProbe('key-1', 'mg.example.com'))->multipart($email);
        $this->assertStringContainsString("name=\"subject\"\r\n\r\n(no subject)", $body);
    }

    public function testCurlOptionsHardenTheCredentialedRequest(): void
    {
        $opts = (new MailgunProbe('key-1', 'mg.example.com'))->options('bnd', 'BODY');

        $this->assertTrue($opts[\CURLOPT_SSL_VERIFYPEER]);
        $this->assertSame(2, $opts[\CURLOPT_SSL_VERIFYHOST]);
        $this->assertSame(\CURL_SSLVERSION_TLSv1_2, $opts[\CURLOPT_SSLVERSION]);
        $this->assertSame('', $opts[\CURLOPT_PROXY]);
        $this->assertSame('BODY', $opts[\CURLOPT_POSTFIELDS]);
        $this->assertContains('Authorization: Basic ' . \base64_encode('api:key-1'), $opts[\CURLOPT_HTTPHEADER]);
        $this->assertContains('Content-Type: multipart/form-data; boundary=bnd', $opts[\CURLOPT_HTTPHEADER]);
    }

    public function testSuccessRidesTheWireWithBasicAuth(): void
    {
        DialogueGate::requireCurlAndFork();
        [$port, $pid, $log] = $this->forkFakeApi(200, 'OK', '{"id":"<1@mg>","message":"Queued."}');
        try {
            (new MailgunTransport('key-wire', 'mg.example.com', baseUrl: "http://127.0.0.1:$port"))->send($this->email());
            $request = $this->pollText($log, 'plain body');
            $this->assertStringContainsString('POST /v3/mg.example.com/messages', $request);
            $this->assertStringContainsString('Authorization: Basic ' . \base64_encode('api:key-wire'), $request);
            $this->assertStringContainsString('multipart/form-data; boundary=', $request);
        } finally {
            $this->reap($pid);
            @\unlink($log);
        }
    }

    public function testApiErrorIsReportedWithStatusAndTruncatedBody(): void
    {
        DialogueGate::requireCurlAndFork();
        [$port, $pid, $log] = $this->forkFakeApi(401, 'Unauthorized', \str_repeat('Z', 900));
        try {
            (new MailgunTransport('key-bad', 'mg.example.com', baseUrl: "http://127.0.0.1:$port"))->send($this->email());
            $this->fail('a 401 must throw');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('401', $e->getMessage());
            $this->assertSame(500, \substr_count($e->getMessage(), 'Z'));
            $this->assertStringNotContainsString('key-bad', $e->getMessage());
        } finally {
            $this->reap($pid);
            @\unlink($log);
        }
    }

    public function testNetworkErrorIsReported(): void
    {
        DialogueGate::requireCurlAndFork();
        $this->expectException(\RuntimeException::class);
        (new MailgunTransport('key-1', 'mg.example.com', baseUrl: 'http://127.0.0.1:1'))->send($this->email());
    }

    /** @return array{0:int,1:int,2:string} [port, pid, log] */
    private function forkFakeApi(int $status, string $reason, string $body): array
    {
        $server = @\stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertIsResource($server, "ephemeral listen failed: $errstr");
        $port = (int) \substr((string) \strrchr((string) \stream_socket_get_name($server, false), ':'), 1);
        $log  = (string) \tempnam(\sys_get_temp_dir(), 'candy-mail-mg-');

        $pid = \pcntl_fork();
        $this->assertGreaterThanOrEqual(0, $pid, 'fork failed');
        if ($pid === 0) {
            $conn = @\stream_socket_accept($server, 10.0);
            $request = '';
            if (\is_resource($conn)) {
                \stream_set_timeout($conn, 5);
                while (($line = \fgets($conn)) !== false) {
                    $request .= $line;
                    if ($line === "\r\n" || $line === "\n") {
                        break;
                    }
                }
                if (\preg_match('/^Content-Length:\s*(\d+)/mi', $request, $m) === 1) {
                    $left = (int) $m[1];
                    while ($left > 0 && \strlen((string) $chunk = \fread($conn, \min(8192, $left))) > 0) {
                        $request .= $chunk;
                        $left    -= \strlen($chunk);
                    }
                }
                \fwrite($conn, "HTTP/1.1 {$status} {$reason}\r\nContent-Length: " . \strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);
                \fclose($conn);
            }
            \file_put_contents($log, $request);
            if (\function_exists('posix_kill')) {
                \posix_kill(\getmypid(), \SIGKILL);
            }
            exit(0);
        }
        \fclose($server);

        return [$port, $pid, $log];
    }

    private function pollText(string $path, string $needle, float $budget = 5.0): string
    {
        $deadline = \microtime(true) + $budget;
        do {
            $content = (string) @\file_get_contents($path);
            if (\str_contains($content, $needle)) {
                return $content;
            }
            \usleep(20000);
        } while (\microtime(true) < $deadline);

        return $content;
    }

    private function reap(int $pid): void
    {
        $deadline = \microtime(true) + 2.0;
        while ($pid > 0 && \microtime(true) < $deadline) {
            if (\pcntl_waitpid($pid, $status, \WNOHANG) !== 0) {
                return;
            }
            \usleep(10000);
        }
    }
}
