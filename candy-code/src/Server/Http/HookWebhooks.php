<?php

declare(strict_types=1);

namespace SugarCraft\Code\Server\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SugarCraft\Code\Context\PromptFence;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Server\Auth\RateLimiter;
use SugarCraft\Code\Server\ServerConfig;

/**
 * AUT-6: inbound webhooks — `POST /api/hooks/{name}` turns one configured
 * secret and one JSON-less body into a background agent turn.
 *
 * THE DOCTRINE, because this is the only door through which the open internet
 * can start an agent:
 *
 * - **Default-off by construction.** The route exists only when the operator's
 *   user config names hooks ({@see \SugarCraft\Code\Server\ServerConfig::$webhooks},
 *   validated at launch); with none configured an unmounted handler never runs
 *   and the path 404s byte-identically to any unknown endpoint — probing
 *   learns nothing.
 * - **The secret is the credential.** Compared with `hash_equals` against
 *   header {@see SECRET_HEADER}; there is no cookie, no ticket, no bearer.
 *   Wrong or missing secrets strike the shared {@see RateLimiter} keyed per
 *   hook AND address, so a guesser is locked out for the standard window
 *   without locking the operator out of `/api/login`.
 * - **The body is untrusted model input.** It rides the same PromptFence
 *   escape every other externally-sourced text does, spliced into the
 *   operator's configured prompt at `{{payload}}` (or appended below a plain
 *   label when the prompt names no placeholder — deliberately NOT a forged
 *   XML boundary, which escaping would not neutralise).
 * - **Rate is per address, and per hook overall** (AUT-6-P3: counted on the
 *   address window ALONE, a fleet of sources combined into N times the
 *   configured rate on one hook), pruned on access; no timers exist here
 *   (E646: ceilings bound requests, not due-checks).
 * - **`bypass-permissions` cannot be configured at all** — that
 *   floor is enforced at launch ({@see \SugarCraft\Code\Server\ServerConfig::webhooksValue()},
 *   asking {@see \SugarCraft\Code\Server\ServerConfig::hookRefusesMode()});
 *   a webhook has no human to answer a permission ask, and bypass answers it
 *   YES. `dont-ask` IS allowed, because it answers the same ask by REFUSING.
 *
 * The spawned turn goes through the SAME door `bg.spawn` uses (the server
 * context's background events), so session caps, draining and the event feed
 * are one truth. The response answers `200` with the background summary as
 * soon as the turn is ADMITTED — delivery is fire-and-observe, and waiting on
 * the model here would hold an HTTP connection open for minutes.
 */
final class HookWebhooks
{
    /** The only credential the route reads. Lower-case as PSR-7 sees it. */
    public const SECRET_HEADER = 'x-candy-code-hook';

    /** The placeholder a configured prompt may name for the escaped body. */
    public const PAYLOAD_PLACEHOLDER = '{{payload}}';

    /** Trailing block appended when the configured prompt names no placeholder. */
    public const UNTRUSTED_LABEL = "\n\nWebhook payload (untrusted):\n";

    /** Sliding window the per-hook rate counts inside. */
    public const WINDOW_SECONDS = 60;

    /** Distinct `hook|address` and `hook` (overall) keys tracked; the stalest are dropped beyond this. */
    private const CAPACITY = 4096;

    /** @var array<string, list<float>> accepted-delivery times per window key */
    private array $accepted = [];

    /**
     * @param array<string,array{secret:string,prompt:string,permissionMode:PermissionMode|null,rate:int}> $hooks validated hook map ({@see \SugarCraft\Code\Server\ServerConfig::webhooksValue()})
     * @param \Closure(string,array{secret:string,prompt:string,permissionMode:PermissionMode|null,rate:int},string):array<string,mixed> $run admits the turn through the server context's bg.spawn door; throws when it cannot
     * @param \Closure(): float $clock
     */
    public function __construct(
        private readonly array $hooks,
        private readonly \Closure $run,
        private readonly RateLimiter $limiter,
        private readonly \Closure $clock,
    ) {
    }

    /** @param (\Closure(): float)|null $clock */
    public static function new(array $hooks, \Closure $run, ?\Closure $clock = null): self
    {
        return new self(
            $hooks,
            $run,
            RateLimiter::new($clock),
            $clock ?? static fn (): float => \microtime(true),
        );
    }

    /**
     * One delivery. $name is the path segment after `/api/hooks/`; $address is
     * the client address the ApiController resolved through `trustedProxies`.
     */
    public function handle(ServerRequestInterface $request, string $name, string $address): ResponseInterface
    {
        $hook = $this->hooks[$name] ?? null;
        if ($hook === null) {
            // Byte-identical to the router's unknown-path 404: an unconfigured
            // hook and a never-existed one are the same answer to a prober.
            return Responses::error(404, 'not_found', 'no such endpoint');
        }

        if ($request->getMethod() !== 'POST') {
            return self::methodNotAllowed();
        }

        $key = $name . '|' . $address;
        $retry = $this->limiter->retryAfter($key);
        if ($retry > 0) {
            return Responses::error(429, 'rate_limited', 'too many failed deliveries for this hook from this address', ['Retry-After' => (string) $retry]);
        }

        // Constant-time by law: this comparison's timing is the only signal a
        // network attacker gets about the secret. hash_equals is the named
        // primitive; HookWebhooksTest source-scans that it is USED here.
        if (!\hash_equals($hook['secret'], $request->getHeaderLine(self::SECRET_HEADER))) {
            $this->limiter->recordFailure($key);

            return Responses::error(401, 'unauthorized', 'the webhook secret is not valid');
        }

        // Two windows are charged per delivery: this address's, and the hook's
        // OVERALL one keyed by the bare name. The name grammar (webhooksValue:
        // no `|` in a hook name) keeps the bare-name key uncollidable with the
        // `hook|address` keys in the same map.
        if (!$this->admit($key, $name, $hook['rate'])) {
            return Responses::error(429, 'rate_limited', 'this hook is over its delivery rate, per address or per hook overall', ['Retry-After' => (string) self::WINDOW_SECONDS]);
        }

        // The body arrives whole but NOT truthfully: RequestBodyBufferMiddleware
        // (MAX_API_BODY_BYTES in front of the router) does not REFUSE an
        // over-limit body — it swaps in an empty one and calls on, so a 70 KiB
        // push would otherwise splice a fence-escaped nothing into the prompt
        // and bill the turn anyway. An over-cap declaration, or a declared
        // body that arrived as '' (dropped by that swap, or shorted by a lying
        // Content-Length), is answered 413 here; $run never sees it. A body
        // declared absent (Content-Length: 0, no chunked framing) may stay
        // empty — that is an ordinary no-payload delivery, not a lost one.
        $declared = (int) $request->getHeaderLine('content-length');
        $body = (string) $request->getBody();
        if ($declared > ServerConfig::MAX_API_BODY_BYTES
            || ('' === $body && ($declared > 0 || 'chunked' === \strtolower($request->getHeaderLine('transfer-encoding'))))) {
            return Responses::error(413, 'payload_too_large', 'the webhook body is larger than the server accepts');
        }
        try {
            // The splice runs INSIDE the try (AUT-6-P3): PromptFence::escape()
            // throws on a PCRE failure (backtrack/recursion limit on a
            // pathological body), and that is a delivery the handler refused
            // to build, not a server bug to expose — it answers with the same
            // generic 503 as a failing $run, and the cause never reaches the
            // client.
            $prompt = \str_contains($hook['prompt'], self::PAYLOAD_PLACEHOLDER)
                ? \str_replace(self::PAYLOAD_PLACEHOLDER, PromptFence::escape($body), $hook['prompt'])
                : $hook['prompt'] . self::UNTRUSTED_LABEL . PromptFence::escape($body);
            $summary = ($this->run)($name, $hook, $prompt);
        } catch (\Throwable) {
            // A $run failure's cause reaches the server log inside $run
            // (ISS-057: whoever hides it from the client owes it to the sink —
            // Serve.php's closure logs its own). An escape failure's cause is
            // PCRE's own message, which names nothing private, and this class
            // holds no sink. Either way the client learns only that delivery
            // was refused.
            return Responses::error(503, 'hook_unavailable', 'the webhook could not start a session');
        }

        return Responses::json(200, ['ok' => true] + $summary);
    }

    /**
     * Count this accepted delivery against BOTH windows — the per-address one
     * ($key) and the per-hook-overall one ($name) — at the same configured
     * rate; false when either is one over, and then neither is charged: a
     * refused delivery consumes no budget anywhere. Prune-on-access like
     * {@see RateLimiter} — time moves only when someone asks.
     */
    private function admit(string $key, string $name, int $perMinute): bool
    {
        $now = ($this->clock)();
        $windows = [];
        foreach ([$key, $name] as $window) {
            $recent = \array_values(\array_filter(
                $this->accepted[$window] ?? [],
                static fn (float $at): bool => $at > $now - self::WINDOW_SECONDS,
            ));

            if (\count($recent) >= $perMinute) {
                unset($this->accepted[$window]);
                $this->accepted[$window] = $recent;

                return false;
            }
            $windows[$window] = $recent;
        }

        foreach ($windows as $window => $recent) {
            $recent[] = $now;
            unset($this->accepted[$window]);
            $this->accepted[$window] = $recent;
        }
        if (\count($this->accepted) > self::CAPACITY) {
            \array_shift($this->accepted);
        }

        return true;
    }

    private static function methodNotAllowed(): ResponseInterface
    {
        return Responses::error(405, 'method_not_allowed', 'method not allowed', ['Allow' => 'POST']);
    }
}
