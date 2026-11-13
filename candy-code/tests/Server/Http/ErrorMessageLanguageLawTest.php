<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server\Http;

use PHPUnit\Framework\TestCase;
use React\Http\Message\ServerRequest;
use SugarCraft\Code\Server\Http\RequestLocale;
use SugarCraft\Code\Server\Http\StaticFiles;

/**
 * S-V08 (G-13): the HTTP error-message language law.
 *
 * LAW (also stated on `Responses::error`): a refusal's `kind` is the machine
 * contract and its `message` is a developer-facing English diagnostic for API
 * clients — EXCEPT on the pages a browser navigation lands on (the no-UI
 * notice, static-file refusals, the share page), whose prose is user-visible
 * framing and is keyed through `Lang::t` under the visitor's
 * `Accept-Language` ({@see RequestLocale}).
 *
 * The guard below fails on any bare sentence literal in argument 3 of a
 * `Responses::error(` call that is not in the allowlist, so a new refusal
 * either routes through a key or is consciously listed as an API diagnostic.
 */
final class ErrorMessageLanguageLawTest extends TestCase
{
    /**
     * API-diagnostic census: `<path under src/>|<message>`. Every row is an
     * English developer message on a JSON/API surface. Adding a row is a
     * deliberate G-13 class-2 decision; a browser-facing page never belongs here.
     *
     * @var list<string>
     */
    private const API_DIAGNOSTICS = [
        'Channels/ChannelRegistry.php|no webhook channel answers this route',
        'Channels/ChannelRegistry.php|the channel surface has shut down',
        'Server/Http/ApiController.php|method not allowed',
        'Server/Http/ApiController.php|no such endpoint',
        'Server/Http/ApiController.php|that login code or token is not valid',
        'Server/Http/ApiController.php|tickets are for signed-in browsers; other clients authenticate the upgrade directly',
        'Server/Http/ApiController.php|too many failed sign-ins from this address',
        'Server/Http/AuthMiddleware.php|authentication required',
        'Server/Http/AuthMiddleware.php|this endpoint speaks WebSocket only',
        'Server/Http/AuthMiddleware.php|too many failed sign-ins from this address',
        'Server/Http/CanvasPageController.php|canvas links are minted by POST only',
        'Server/Http/CanvasPageController.php|canvas pages are served by GET only',
        'Server/Http/CanvasPageController.php|the canvas mint names a sessionId and an artifactId',
        'Server/Http/CanvasPageController.php|the canvas mint needs a JSON body',
        'Server/Http/CanvasPageController.php|this canvas page does not exist, or its link has been used or has expired',
        'Server/Http/HookWebhooks.php|method not allowed',
        'Server/Http/HookWebhooks.php|no such endpoint',
        'Server/Http/HookWebhooks.php|the webhook body is larger than the server accepts',
        'Server/Http/HookWebhooks.php|the webhook could not start a session',
        'Server/Http/HookWebhooks.php|the webhook secret is not valid',
        'Server/Http/HookWebhooks.php|the webhook signature is not valid',
        'Server/Http/HookWebhooks.php|this hook is over its delivery rate, per address or per hook overall',
        'Server/Http/HookWebhooks.php|too many failed deliveries for this hook from this address',
        'Server/Http/HostAndOriginGuard.php|cross-origin requests are not accepted',
        'Server/Http/HostAndOriginGuard.php|this server does not answer to that host name',
        'Server/Http/Router.php|the request failed inside the server; the cause is in the server log',
        'Server/Http/Router.php|this endpoint speaks WebSocket only',
        'Server/Server.php|the request body did not arrive in time',
        'Server/Server.php|too many simultaneous requests from this address',
        'Server/Ws/Upgrade.php|not a valid WebSocket upgrade',
        'Server/Ws/Upgrade.php|offer the WebSocket subprotocol ',
    ];

    public function testNoBareSentenceLiteralEscapesTheAllowlist(): void
    {
        $found = [];
        $root = \dirname(__DIR__, 3) . '/src';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!\str_ends_with((string) $file, '.php')) {
                continue;
            }
            $source = (string) \file_get_contents((string) $file);
            if (\preg_match_all("/Responses::error\\(\\s*[^,]+,\\s*'[^']*',\\s*'((?:[^'\\\\\\\\]|\\\\\\\\.)*)'/", $source, $m) < 1) {
                continue;
            }
            $relative = \substr((string) $file, \strlen($root) + 1);
            foreach ($m[1] as $literal) {
                $found[$relative . '|' . \str_replace("\\'", "'", $literal)] = true;
            }
        }

        $rogue = \array_diff(\array_keys($found), self::API_DIAGNOSTICS);
        self::assertSame([], \array_values($rogue), 'a bare English literal reached Responses::error() argument 3: route a browser-facing page through RequestLocale::t(), or list an API diagnostic here with its G-13 reason');

        $stale = \array_diff(self::API_DIAGNOSTICS, \array_keys($found));
        self::assertSame([], \array_values($stale), 'allowlist row no longer matches a call site: delete it');
    }

    public function testTheNoUiNoticePageAnswersInTheVisitorsLanguage(): void
    {
        $request = (new ServerRequest('GET', 'http://x/'))->withHeader('Accept-Language', 'de-DE,de;q=0.9,en;q=0.5');
        $page = (string) StaticFiles::new(null)($request)->getBody();

        self::assertStringContainsString('<html lang="de">', $page);
        self::assertStringContainsString('keine Web-Oberfläche', $page);
        self::assertStringContainsString('<code>--web-root</code>', $page, 'flag vocabulary stays literal');
        self::assertStringContainsString('composer require sugarcraft/candy-code-web', $page);
    }

    public function testTheNoticePageIsEnglishWithoutALanguageHeader(): void
    {
        $page = (string) StaticFiles::new(null)(new ServerRequest('GET', 'http://x/'))->getBody();

        self::assertStringContainsString('<html lang="en">', $page);
        self::assertStringContainsString('The server is running, but no web UI is installed.', $page);
        self::assertStringContainsString('<code>/ws</code> and the HTTP API under <code>/api/</code> work without it.', $page);
    }

    public function testTheStaticMethodRefusalIsTranslatedButKeepsItsKind(): void
    {
        $response = StaticFiles::new(null)((new ServerRequest('POST', 'http://x/'))->withHeader('Accept-Language', 'fr'));
        $body = \json_decode((string) $response->getBody(), true, 8);

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('method_not_allowed', $body['error']['kind']);
        self::assertSame('méthode non autorisée', $body['error']['message']);
    }

    public function testLanguageNegotiationIsContainedAndRanked(): void
    {
        $tag = static fn (string $h): string => RequestLocale::tag((new ServerRequest('GET', 'http://x/'))->withHeader('Accept-Language', $h));

        self::assertSame('de', $tag('de-AT, en;q=0.8'));
        self::assertSame('pt-br', $tag('pt-BR'));
        self::assertSame('de', $tag('xx;q=1.0, de;q=0.5'), 'an unshipped tag does not mask a later shipped one');
        self::assertSame('en', $tag('../../etc/passwd'));
        self::assertSame('en', $tag('de;q=0'), 'q=0 means not acceptable');
        self::assertSame('en', $tag(''));
    }
}
