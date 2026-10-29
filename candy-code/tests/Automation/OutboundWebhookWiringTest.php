<?php

/**
 * C-AGT-5.f: the outbound webhook WIRING, measured end to end at the seams
 * that decide WHAT rides out and WHEN. The engine itself — parse, sign,
 * retry, queue — is {@see WebhookDispatcherTest} and friends; this file
 * pins only the launch's assembly:
 *
 * - `Bootstrap::workspace()` reads `webhooksOut` from the USER layer only
 *   (the key is absent from LAYERED_KEYS and PROJECT_TIER_KEYS, so a
 *   project settings file can never open an egress door), refuses launch
 *   when the key is present but unusable, and stays dark when absent;
 * - a TUI turn settling enqueues exactly one signed `turn.completed` POST,
 *   counted over the same window the transcript fold uses;
 * - a TUI approval arming enqueues one signed `permission.asked` POST, and
 *   a superseded ask enqueues nothing;
 * - a served TURN_COMPLETED, heard through the feed's event stream,
 *   enqueues one signed `turn.completed` POST.
 *
 * Every leg dials a fake `React\Http\Browser` (the dispatcher's seam), so
 * no socket is ever opened and no wall-clock boundary is waited on; the
 * 200 answers resolve synchronously, which is what lets the posts be read
 * the moment the emit returns.
 */

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Automation;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Http\Message\Response;
use SugarCraft\Code\App\AssistantMsg;
use SugarCraft\Code\Automation\OutboundWebhookConfig;
use SugarCraft\Code\Automation\WebhookDispatcher;
use SugarCraft\Code\Automation\WebhookEvent;
use SugarCraft\Code\Automation\WebhookSigner;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Cli\PermissionConfigException;
use SugarCraft\Code\Config\LayeredSettings;
use SugarCraft\Code\Hooks\HookContext;
use SugarCraft\Code\Hooks\HookEvent;
use SugarCraft\Code\Hooks\HookInterface;
use SugarCraft\Code\Hooks\HookManager;
use SugarCraft\Code\Hooks\HookRegistry;
use SugarCraft\Code\Hooks\HookResult;
use SugarCraft\Code\Host\SessionHost;
use SugarCraft\Code\Host\SessionHub;
use SugarCraft\Code\Host\WorkspaceContext;
use SugarCraft\Code\Message;
use SugarCraft\Code\Protocol\ServerContext;
use SugarCraft\Code\Server\ServerConfig;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\ToolCall;

final class OutboundWebhookWiringTest extends TestCase
{
    use HomeSandboxTrait;

    private const WIRE_SECRET = 'wiring-secret-value-0123456789';

    private const WIRE_URL = 'https://hooks.agent.test/ci';

    private string $sandbox = '';

    private string $project = '';

    /** @var list<array<string,mixed>> */
    private array $dialPosts = [];

    /** @var array<string,mixed> */
    private array $dialTransforms = [];

    private string|false $suggestions = false;

    protected function setUp(): void
    {
        $this->sandbox = \sys_get_temp_dir() . '/crush-webhook-wiring-' . \bin2hex(\random_bytes(6));
        $this->project = $this->sandbox . '/project';
        \mkdir($this->project, 0o700, true);
        $this->useHomeSandbox($this->sandbox . '/home');
        // One Cmd per settle, so no suggestion backend rides the same turn.
        $this->suggestions = getenv('CANDY_CODE_DISABLE_PROMPT_SUGGESTIONS');
        putenv('CANDY_CODE_DISABLE_PROMPT_SUGGESTIONS=1');
    }

    protected function tearDown(): void
    {
        $this->suggestions === false
            ? putenv('CANDY_CODE_DISABLE_PROMPT_SUGGESTIONS')
            : putenv('CANDY_CODE_DISABLE_PROMPT_SUGGESTIONS=' . $this->suggestions);
        $this->restoreHomeSandbox();
        if (\is_dir($this->sandbox)) {
            \exec('rm -rf ' . \escapeshellarg($this->sandbox));
        }
    }

    // ── the launch read ────────────────────────────────────────────────

    public function testTheKeyIsUserTierByConstruction(): void
    {
        // The claim docs/SERVER.md makes: an egress door opens only for the
        // human who owns the credential. Absent from BOTH merge whitelists,
        // exactly like the inbound `webhooks` sibling it mirrors.
        self::assertNotContains('webhooksOut', LayeredSettings::LAYERED_KEYS);
        self::assertNotContains('webhooksOut', LayeredSettings::PROJECT_TIER_KEYS);
        self::assertNotContains('webhooks', LayeredSettings::LAYERED_KEYS);
    }

    public function testAnAbsentWebhooksOutIsDark(): void
    {
        self::assertSame([], Bootstrap::outboundWebhookEndpoints([]));
        self::assertSame([], Bootstrap::outboundWebhookEndpoints(['theme' => 'tokyo']));
    }

    public function testAValidWebhooksOutParsesToTheEndpointMapTheDispatcherWants(): void
    {
        $endpoints = Bootstrap::outboundWebhookEndpoints(['webhooksOut' => [
            'ci' => ['url' => self::WIRE_URL, 'secret' => self::WIRE_SECRET, 'events' => [WebhookEvent::TYPE_TURN_COMPLETED]],
        ]]);

        self::assertSame(['ci'], \array_keys($endpoints));
        self::assertSame(self::WIRE_URL, $endpoints['ci']['url']);
        self::assertSame(self::WIRE_SECRET, $endpoints['ci']['secret']);
        self::assertSame([WebhookEvent::TYPE_TURN_COMPLETED], $endpoints['ci']['events']);
    }

    public function testAPresentButUnusableWebhooksOutRefusesTheLaunch(): void
    {
        // Plain http to a non-loopback host is the parse's own refusal; the
        // wiring's job is that it becomes a launch refusal (the bin-level
        // PermissionConfigException handler exits 2), never a silent dark.
        $this->expectException(PermissionConfigException::class);
        $message = '';
        try {
            Bootstrap::outboundWebhookEndpoints(['webhooksOut' => [
                'ci' => ['url' => 'http://hooks.agent.test/ci', 'secret' => self::WIRE_SECRET, 'events' => [WebhookEvent::TYPE_TURN_COMPLETED]],
            ]]);
        } catch (PermissionConfigException $e) {
            $message = $e->getMessage();

            throw $e;
        } finally {
            self::assertStringContainsString('webhooksOut', $message);
            self::assertStringContainsString('ci', $message, 'the refusal names the offending endpoint');
        }
    }

    // ── the TUI legs ───────────────────────────────────────────────────

    public function testASettledTurnEnqueuesOneSignedTurnCompletedPost(): void
    {
        $chat = $this->wiredChat([WebhookEvent::TYPE_TURN_COMPLETED], 'ses_wiring_1', history: [
            Message::user('the prompt'),
            Message::assistant('looking')->withToolCalls([new ToolCall('Bash', ['command' => 'ls'], 'call_one')]),
        ]);

        $chat->update(new AssistantMsg(Message::assistant('Done.')));

        self::assertCount(1, $this->dialPosts, 'one settle, one delivery — the bell and the webhook share the guard');
        $post = $this->dialPosts[0];
        self::assertSame('POST', $post['method']);
        self::assertSame(self::WIRE_URL, $post['url']);
        $this->assertSignedPost($post);

        $payload = \json_decode((string) $post['body'], true);
        self::assertSame(WebhookEvent::TYPE_TURN_COMPLETED, $payload['event']);
        self::assertSame('ses_wiring_1', $payload['data']['session_id']);
        self::assertSame(1, $payload['data']['tool_calls'], 'the mid-turn tool call counts once and the settle row adds none');
        self::assertIsFloat($payload['data']['duration_seconds']);
        self::assertGreaterThanOrEqual(0.0, $payload['data']['duration_seconds']);
    }

    public function testATurnCarriedOnByAQueuedPromptIsNotCompletedYet(): void
    {
        $chat = $this->wiredChat([WebhookEvent::TYPE_TURN_COMPLETED], 'ses_wiring_2', with: [
            'queuedPrompts' => ['next one'],
        ]);

        [$next, ] = $chat->update(new AssistantMsg(Message::assistant('Done.')));

        self::assertTrue($next->inFlight, 'fixture: the queued prompt took the turn');
        self::assertSame([], $this->dialPosts, 'the same "really over" guard the bell uses gates the webhook');
    }

    public function testADarkLaunchSettlesWithoutAnyDial(): void
    {
        $chat = new Chat(...[
            'history' => [Message::user('the prompt')],
            'inFlight' => true,
            'backend' => new EchoBackend(),
            'workspace' => WorkspaceContext::new(root: $this->project, userConfig: []),
            'currentSessionId' => 'ses_wiring_dark',
        ]);

        $chat->update(new AssistantMsg(Message::assistant('Done.')));

        self::assertSame([], $this->dialPosts);
    }

    public function testAnArmedApprovalEnqueuesOneSignedPermissionAskedPost(): void
    {
        $asks = new class implements HookInterface {
            public function name(): string
            {
                return 'ask-every-tool-wiring';
            }

            public function event(): HookEvent
            {
                return HookEvent::PreToolUse;
            }

            public function matcher(): string
            {
                // '(?:.*)' matches everything exactly like the every-tool
                // fixtures' plain '.*' — parenthesised spelling only, so the
                // literal stays exempt from the GlobDialect corpus harvest.
                return '(?:.*)';
            }

            public function execute(HookContext $context): HookResult
            {
                return HookResult::ask('Run rm -rf build/?');
            }
        };
        $hooks = new HookManager(new HookRegistry());
        $hooks->register($asks);

        $chat = $this->wiredChat([WebhookEvent::TYPE_PERMISSION_ASKED], 'ses_wiring_3')
            ->registerTool('Bash', static fn (array $args): string => 'total 0')
            ->withHooks($hooks);

        [$asking, ] = $chat->update(new AssistantMsg(
            Message::assistant('running')->withToolCalls([new ToolCall('Bash', ['command' => 'rm -rf build/'], 'call_ask')]),
        ));

        self::assertNotNull($asking->pendingPermission(), 'fixture: the hook raised a prompt');
        self::assertCount(1, $this->dialPosts, 'the armed question rides out beside the bell');
        $payload = \json_decode((string) $this->dialPosts[0]['body'], true);
        self::assertSame(WebhookEvent::TYPE_PERMISSION_ASKED, $payload['event']);
        self::assertSame('ses_wiring_3', $payload['data']['session_id']);
        self::assertSame('Bash', $payload['data']['tool']);
        $this->assertSignedPost($this->dialPosts[0]);
    }

    public function testAnEndpointSubscribedToNeitherEventTypeIsNotDialed(): void
    {
        // dispatch() filters by membership; the wiring must not widen the
        // operator's subscription by emitting an unsolicited event type.
        $chat = $this->wiredChat([WebhookEvent::TYPE_SCHEDULE_FIRED], 'ses_wiring_4', history: [
            Message::user('the prompt'),
        ]);

        $chat->update(new AssistantMsg(Message::assistant('Done.')));

        self::assertSame([], $this->dialPosts, 'a settle is not a schedule fire');
    }

    // ── the served leg ─────────────────────────────────────────────────

    public function testAServedTurnCompletionEnqueuesOneSignedPostThroughTheFeed(): void
    {
        $store = new EnhancedSessionStore($this->sandbox . '/session.db');
        $store->createSession('s', 'p', 'm');

        $workspace = WorkspaceContext::new(
            root: $this->project,
            sessionStore: $store,
            backend: new EchoBackend(),
        )->withService(WebhookDispatcher::class, $this->wiringDispatcher([WebhookEvent::TYPE_TURN_COMPLETED]));

        $hub = SessionHub::new($workspace);
        $host = $hub->open('s');
        $context = ServerContext::new(
            $hub,
            ServerConfig::new($this->sandbox)->withRoot($this->project),
            'test',
            Loop::get(),
            static fn (): float => \microtime(true),
        );

        // Attaching the feed is what arms the listener the serve host hears
        // every session event through — the outbound mirror rides beside
        // the client broadcast, never instead of it.
        $context->feedFor($host);
        $host->submit('do the thing');
        $this->drainServed($host);

        self::assertFalse($host->isBusy(), 'fixture: the served turn settled');
        self::assertCount(1, $this->dialPosts, 'one served turn, one delivery');
        $this->assertSignedPost($this->dialPosts[0]);

        $payload = \json_decode((string) $this->dialPosts[0]['body'], true);
        self::assertSame(WebhookEvent::TYPE_TURN_COMPLETED, $payload['event']);
        self::assertSame('s', $payload['data']['session_id']);
        self::assertSame(0, $payload['data']['tool_calls'], 'an echo turn called no tools');
        self::assertIsFloat($payload['data']['duration_seconds']);
        self::assertGreaterThanOrEqual(0.0, $payload['data']['duration_seconds']);
    }

    // ── fixtures ───────────────────────────────────────────────────────

    /**
     * A Chat whose workspace carries a real dispatcher over the fake
     * browser, subscribed to exactly `$events`.
     *
     * @param list<string>                       $events
     * @param list<Message>                      $history
     * @param array<string,mixed>                $with
     */
    private function wiredChat(array $events, string $sessionId, ?array $history = null, array $with = []): Chat
    {
        return new Chat(...array_merge([
            'history' => $history ?? [Message::user('the prompt')],
            'inFlight' => true,
            'backend' => new EchoBackend(),
            'workspace' => WorkspaceContext::new(root: $this->project, userConfig: [])
                ->withService(WebhookDispatcher::class, $this->wiringDispatcher($events)),
            'currentSessionId' => $sessionId,
        ], $with));
    }

    /**
     * The dispatcher over a fake browser that records every dial into
     * {@see $dialPosts}; script-exhausted answers are 200, which resolves
     * synchronously — the wiring test never waits on a timer. The browser
     * shape is byte-faithful to {@see WebhookDispatcherTest}'s fake.
     *
     * @param list<string> $events
     */
    private function wiringDispatcher(array $events): WebhookDispatcher
    {
        $posts = &$this->dialPosts;
        $transforms = &$this->dialTransforms;

        $browser = new class($posts, $transforms) extends \React\Http\Browser {
            /** @param list<array<string,mixed>> $posts @param array<string,mixed> $transforms */
            public function __construct(private array &$posts, private array &$transforms)
            {
                parent::__construct();
            }

            public function request($method, $url, array $headers = [], $body = '')
            {
                $this->posts[] = [
                    'method' => (string) $method,
                    'url' => (string) $url,
                    'headers' => $headers,
                    'body' => (string) $body,
                    'at' => \microtime(true),
                ];

                return \React\Promise\resolve(new Response(200));
            }

            public function withFollowRedirects($followRedirects)
            {
                $this->transforms['follow'] = $followRedirects;

                return $this;
            }

            public function withRejectErrorResponse($bool)
            {
                $this->transforms['rejectError'] = $bool;

                return $this;
            }

            public function withTimeout($timeout)
            {
                $this->transforms['timeout'] = $timeout;

                return $this;
            }
        };

        return new WebhookDispatcher(
            $browser,
            OutboundWebhookConfig::parse(['ci' => ['url' => self::WIRE_URL, 'secret' => self::WIRE_SECRET, 'events' => $events]]),
            null,
            64,
            Loop::get(),
        );
    }

    /**
     * The headers a delivery must carry to be a delivery the receiver can
     * trust: the pair, HMAC-verified over the raw body with the endpoint's
     * secret — re-derived here rather than eyeballed.
     *
     * @param array<string,mixed> $post
     */
    private function assertSignedPost(array $post): void
    {
        $headers = (array) $post['headers'];
        $timestamp = (int) ($headers[WebhookSigner::TIMESTAMP_HEADER] ?? 0);
        self::assertGreaterThan(0, $timestamp, 'the timestamp header is present and numeric');
        self::assertLessThanOrEqual(time(), $timestamp, 'never stamped in the future');
        self::assertSame(
            WebhookSigner::signature((string) $post['body'], self::WIRE_SECRET, $timestamp),
            $headers[WebhookSigner::SIGNATURE_HEADER] ?? '',
            'the signature verifies over exactly this body at exactly this second',
        );
        self::assertSame('application/json', $headers['Content-Type'] ?? null);
    }

    /**
     * Run the shared loop until the host's turn settles, bounded by a
     * deadline timer — same shape as the serve-pump tests' drain.
     */
    private function drainServed(SessionHost $host): void
    {
        if (!$host->isBusy()) {
            return;
        }

        $loop = Loop::get();
        $poll = $loop->addPeriodicTimer(0.01, static function () use ($host, $loop): void {
            if (!$host->isBusy()) {
                $loop->stop();
            }
        });
        $guard = $loop->addTimer(15.0, static fn () => $loop->stop());
        $loop->run();
        $loop->cancelTimer($poll);
        $loop->cancelTimer($guard);
    }
}
