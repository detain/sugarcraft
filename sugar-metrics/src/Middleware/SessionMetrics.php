<?php

declare(strict_types=1);

namespace SugarCraft\Metrics\Middleware;

use SugarCraft\Metrics\Registry;
use SugarCraft\SshServer\Context;
use SugarCraft\SshServer\Middleware;
use SugarCraft\SshServer\Session;

/**
 * Mirrors charmbracelet/promwish middleware option (the
 * InstrumentHandler pattern): wraps a handler to emit telemetry
 * around each invocation.
 *
 * SugarSshServer middleware that emits session telemetry.
 *
 * Per session:
 *
 *   - `ssh.session.connect`    counter — total connect attempts
 *   - `ssh.session.duration`   histogram — seconds the session was open
 *   - `ssh.session.error`      counter — incremented if `\$next` threw
 *
 * Tags on every emit: `user`, `term`; the error emit also carries
 * `exception` — the thrown class's basename, normalized (pass
 * `$fullExceptionClass: true` to restore full FQCNs on a private sink;
 * a public scrape should not carry your dependency layout —
 * sugar-metrics-049). Add more by passing `extraTags` as a callable that
 * receives the {@see Session} — useful for stamping client subnet, geo,
 * build version, etc. The closure supplies tag VALUES only: each value
 * rides the same clamp as `user`/`term`, and base tags stay
 * authoritative — an entry returning a `user`/`term` key can never
 * rewrite the normalized session identity (sugar-metrics-005).
 *
 * Budget: each tag value is normalized to at most 64 bytes from
 * `[A-Za-z0-9._:@/-]`, so one session's dimension cannot bloat the
 * label space or push a statsd UDP datagram past MTU (fragmented
 * datagrams drop silently at most agents — the flood dies with itself).
 *
 * @phpstan-type ExtraTagsFn callable(Session): array<string,string>
 */
final class SessionMetrics implements Middleware
{
    /**
     * Upper bound on the length of a session-derived tag value.
     * `user`/`term` come from attacker-controlled SSH env (`USER`/`TERM`),
     * so an unbounded value would explode label cardinality and bloat
     * backend/registry memory.
     */
    private const MAX_TAG_LENGTH = 64;

    /** @var (callable(Session): array<string,string>)|null */
    private $extraTags;

    /**
     * House-idiom factory (sugar-metrics-014): `SessionMetrics::new($registry)`.
     *
     * @param (callable(Session): array<string,string>)|null $extraTags
     */
    public static function new(Registry $registry, ?callable $extraTags = null, bool $fullExceptionClass = false): self
    {
        return new self($registry, $extraTags, fullExceptionClass: $fullExceptionClass);
    }

    /**
     * @param (callable(Session): array<string,string>)|null $extraTags
     */
    public function __construct(
        private readonly Registry $registry,
        ?callable $extraTags = null,
        private readonly bool $fullExceptionClass = false,
    ) {
        $this->extraTags = $extraTags;
    }

    public function handle(Context $ctx, Session $session, callable $next)
    {
        $tags = [
            'user' => self::normalizeTag($session->user),
            'term' => self::normalizeTag($session->term),
        ];
        if ($this->extraTags !== null) {
            // sugar-metrics-005: base tags are AUTHORITATIVE — the merge
            // order (extra first, base last) keeps a closure's `user`/`term`
            // entries from rewriting the normalized session identity,
            // mirroring the error arm's doctrine below. The closure's own
            // keys survive; its VALUES ride the same clamp — an attacker-
            // bounded field such as Session::$clientVersion (raw, unlength-
            // bounded) cannot smuggle unclamped bytes or a 400-byte value
            // into the scraped label space.
            $extra = [];
            foreach (($this->extraTags)($session) as $key => $value) {
                $extra[$key] = self::normalizeTag($value);
            }
            $tags = array_merge($extra, $tags);
        }

        try {
            $this->registry->counter('ssh.session.connect', 1.0, $tags);
        } catch (\Throwable) {
            // Registry failures must not prevent session handling.
        }

        $stop = null;
        try {
            $stop = $this->registry->time('ssh.session.duration', $tags);
        } catch (\Throwable) {
            // Registry failures must not prevent session handling.
        }

        try {
            $next($ctx, $session);
        } catch (\Throwable $e) {
            try {
                // Assigned, not unioned: a caller-supplied extraTags entry
                // for 'exception' must never shadow the real thrown class.
                // sugar-metrics-049: default to the class BASENAME — the
                // full FQCN of every failure path is a free dependency
                // fingerprint on a public scrape (exact vendor layout and
                // versioned namespaces read through the label). Operators
                // who want full names back pass $fullExceptionClass: true
                // on a private sink. normalizeTag folds any remaining
                // backslashes to underscores either way.
                $errorTags = $tags;
                if ($this->fullExceptionClass) {
                    $thrown = $e::class;
                } else {
                    $segments = explode('\\', $e::class);
                    $thrown = end($segments);
                }
                $errorTags['exception'] = self::normalizeTag($thrown);
                $this->registry->counter('ssh.session.error', 1.0, $errorTags);
            } catch (\Throwable) {
                // Registry failures must not suppress the original exception.
            }
            throw $e;
        } finally {
            if ($stop !== null) {
                try {
                    $stop();
                } catch (\Throwable) {
                    // Timer stop failures must not suppress the original exception.
                }
            }
        }
    }

    /**
     * Clamp an attacker-controlled tag value to a safe charset and length.
     *
     * `=` and `|` are the separators the cardinality tracker uses to build
     * its per-metric tag key, so a hostile value containing them could
     * collide/corrupt key accounting; newlines and control bytes could
     * inject into line-oriented backends (Prometheus textfile). Everything
     * outside `[A-Za-z0-9._:@/-]` collapses to `_`, then the result is
     * capped so a single session can't blow up cardinality or memory.
     */
    private static function normalizeTag(string $value): string
    {
        $clean = preg_replace('/[^A-Za-z0-9._:@\/-]/', '_', $value) ?? '';
        if (strlen($clean) > self::MAX_TAG_LENGTH) {
            $clean = substr($clean, 0, self::MAX_TAG_LENGTH);
        }
        return $clean;
    }
}
