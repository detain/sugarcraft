<?php

declare(strict_types=1);

namespace SugarCraft\Code\Channels;

use SugarCraft\Code\Permissions\ChannelToolScope;

/**
 * Resolves the channel tool scope a session is admitted under (code-chat D6).
 *
 * This is the OpenClaw `groups.resolveToolPolicy` grade-parity point: the
 * stored policy for a conversation is folded into a {@see ChannelToolScope}
 * at admission — per run, from the session's identity — never forked into a
 * second permission engine. The scope it returns rides the existing rule
 * chain as a restriction dimension (PermissionGate step 0.5), so a configured
 * Deny still outranks everything and a scope ALLOW only ever falls through.
 *
 * Identity grammar (code-chat.md §2.3): a channel-originated session is named
 * `agent:<agentId>:<channel>:<kind>:<id>`, with `:topic:<threadId>` appended
 * for Telegram topics and `kind` one of `group`, `channel`, `slash`, or `dm`
 * (both sender-shaped kinds — the native slash-session shape
 * `agent:<id>:discord:slash:<userId>` and a per-peer direct-message session
 * `agent:<id>:discord:dm:<userId>` — carry the sender as their tail; that is
 * where per-sENDER policy can bind, because the sender IS the session there.
 * An aggregate DM session, which folds many humans into one conversation,
 * is named as a `channel` session instead: sender policy cannot resolve per
 * message there, and an invented grammar segment would lie about it).
 * The name rides the existing `session.create`
 * wire field: this design needed no new protocol method.
 *
 * A name that does not start with `agent:` is not a channel session and gets
 * no scope at all — local TUI runs and human-named serve sessions are
 * byte-identical to before. A name that DOES start with `agent:` but cannot be
 * parsed is treated as a broken policy (fail closed): an identity we cannot
 * resolve is exactly the case a policy must not wave through.
 *
 * An unreadable WHOLE record takes the same door (M-1): the store throws on a
 * corrupt channels.json, and scopeFor folds that throw into a refusingAll
 * scope naming the file — broken configuration restricts, never releases.
 *
 * Storage (ruling D6 scope law — everything channel grows INTO channels.json):
 *
 *     {"channels": {"candy-discord|discord": {
 *         "tools":         {"allow": [...], "alsoAllow": [...], "deny": [...]},
 *         "groups":        {"<groupId>":    {"tools": {...}}},
 *         "toolsBySender": {"<senderId>":   {"tools": {...}}}
 *     }}}
 *
 * Keys are the registry's own `bundle|name` granularity. Precedence, openclaw
 * shape: the resolved LEVEL (sender for slash sessions, group for group
 * sessions, the channel entry otherwise) supplies the allowlist, and an
 * ABSENT or EMPTY allowlist at that level inherits the channel's
 * (absent/empty all the way down means deny-only — no allowlist speaks);
 * the DENY lists UNION across the live levels, so a deny at any live scope
 * binds regardless of what the level above it allows. Entries are bare tool
 * names or name globs (`mcp__git__*`) — an entry spelling arguments (a `(`)
 * is refused as malformed, because the scope decides by name and a silently
 * never-matching deny entry would be a hole dressed as a policy.
 *
 * Trust door (D1/D2 era law): policy binds ONLY for bundles the user trusted
 * by name in `trustedBundleChannels` — the same list that lets a bundle's
 * channels start at all. An untrusted bundle's stored policy is inert, so
 * editing channels.json cannot conjure restrictions (or fall-throughs) for
 * traffic a human never consented to route.
 */
final class ChannelToolPolicyService
{
    /** @var array<int, string> grammar kinds that address a policy level */
    private const KINDS = ['group', 'channel', 'slash', 'dm'];

    /** @var \Closure():array<string, mixed> reads the stored record fresh per admission */
    private \Closure $record;

    /** @var array<int, string> */
    private array $trustedBundles;

    /**
     * @param callable():array<string, mixed> $record reads the whole channels.json record (the store, or a test array)
     * @param array<int, string> $trustedBundles bundle names from trustedBundleChannels
     */
    public function __construct(callable $record, array $trustedBundles)
    {
        $this->record = \Closure::fromCallable($record);
        $this->trustedBundles = \array_values($trustedBundles);
    }

    public static function forStore(ChannelsStore $store, array $trustedBundles): self
    {
        return new self(static fn (): array => $store->all(), $trustedBundles);
    }

    /**
     * The scope a session named $sessionName is admitted under, or null when
     * no policy speaks for it (not a channel name, no stored entry, untrusted
     * bundle, or a live-but-empty config that grants no restriction).
     */
    public function scopeFor(?string $sessionName): ?ChannelToolScope
    {
        if (null === $sessionName || '' === $sessionName || !str_starts_with($sessionName, 'agent:')) {
            return null;
        }

        $parsed = $this->parse($sessionName);
        if (null === $parsed) {
            return ChannelToolScope::refusingAll('unparsed agent session name', 'session', $sessionName);
        }

        [$channel, $kind, $id] = $parsed;

        try {
            $entry = $this->entryFor($channel);
        } catch (\Throwable $e) {
            // M-1: the WHOLE record failed to read (a corrupt channels.json).
            // The store answers loudly now; honouring ChannelToolScope's law
            // here means every channel session gets refusingAll until the
            // operator repairs the file — the old silent `[]` fell through to
            // "no policy speaks" and UNRESTRICTED every channel. The
            // diagnostic rides the refusal text so it is operator-visible.
            return ChannelToolScope::refusingAll($channel . ' channel', $kind, 'the stored policy is unreadable: ' . $e->getMessage());
        }

        if (false === $entry) {
            return ChannelToolScope::refusingAll($channel . ' channel', $kind, 'the stored policy for it is not a readable object');
        }

        if (null === $entry) {
            return null;
        }

        $malformed = $this->toolsError($entry);
        if (null !== $malformed) {
            return ChannelToolScope::refusingAll($channel . ' channel', 'channel', $malformed);
        }

        $channelTools = $this->toolsOf($entry);
        $levelTools = $channelTools;
        $label = $channel . ' ' . $kind;

        if ('group' === $kind || 'slash' === $kind || 'dm' === $kind) {
            // Both sender-shaped kinds — native slash sessions and per-peer DM
            // sessions — address the sender section: the session IS one human.
            $section = 'group' === $kind ? 'groups' : 'toolsBySender';
            $levels = $entry[$section] ?? [];
            if (!\is_array($levels)) {
                return ChannelToolScope::refusingAll($channel . ' ' . $kind, $kind, 'the stored ' . $section . ' section is not a readable object');
            }

            if (\array_key_exists($id, $levels)) {
                $level = $levels[$id];
                if (!\is_array($level)) {
                    return ChannelToolScope::refusingAll($channel . ' ' . $kind . ' ' . $id, $kind, 'the stored entry for ' . $id . ' is not a readable object');
                }

                $levelError = $this->toolsError($level);
                if (null !== $levelError) {
                    return ChannelToolScope::refusingAll($channel . ' ' . $kind . ' ' . $id, $kind, $levelError);
                }

                $levelTools = $this->toolsOf($level);
                if (null !== $levelTools) {
                    $label .= ' ' . $id;
                } else {
                    // The conversation is addressed, but declares no tools of
                    // its own — every live line below is the channel's, and
                    // the refusal should say so.
                    $label = $channel . ' channel';
                }
            } else {
                $label = $channel . ' channel';
            }
        }

        $allow = (null !== $levelTools && [] !== ($levelTools['allow'] ?? [])) ? $levelTools['allow'] : (null !== $channelTools ? $channelTools['allow'] : []);
        $alsoAllow = (null !== $levelTools && [] !== ($levelTools['alsoAllow'] ?? [])) ? $levelTools['alsoAllow'] : (null !== $channelTools ? $channelTools['alsoAllow'] : []);
        $deny = \array_merge(
            null !== $channelTools ? $channelTools['deny'] : [],
            (null !== $levelTools && $levelTools !== $channelTools) ? $levelTools['deny'] : [],
        );

        $malformed = $this->firstMalformedEntry([...$allow, ...$alsoAllow, ...$deny]);
        if (null !== $malformed) {
            return ChannelToolScope::refusingAll($label, $kind, $malformed);
        }

        if ([] === $allow && [] === $alsoAllow && [] === $deny) {
            return null;
        }

        return ChannelToolScope::of($label, $kind, $allow, $alsoAllow, \array_values(\array_unique($deny)));
    }

    /**
     * @return null|array{0: string, 1: string, 2: string} [channel, kind, id]
     */
    private function parse(string $name): ?array
    {
        $parts = \explode(':', $name);
        $count = \count($parts);
        if ($count < 5 || $count > 7) {
            return null;
        }

        $channel = $parts[2];
        $kind = $parts[3];
        $id = $parts[4];
        if ('' === $channel || '' === $id || !\in_array($kind, self::KINDS, true)) {
            return null;
        }

        if (7 === $count && 'topic' !== $parts[5]) {
            return null;
        }

        if (6 === $count) {
            return null;
        }

        return [$channel, $kind, $id];
    }

    /**
     * The store entry addressed by the grammar's channel segment, matched by
     * the registry's `bundle|name` key suffix. Channel names are unique across
     * bundles (ruling 2c — the registry refuses collisions), so exactly one
     * key may match; more than one means the file disagrees with the registry
     * and resolution refuses rather than picking a side.
     *
     * @return null|array<string, mixed>|false false = present but unreadable
     */
    private function entryFor(string $channel): null|array|false
    {
        $reader = $this->record;
        $channels = $reader()['channels'] ?? null;
        if (null === $channels) {
            return null;
        }

        if (!\is_array($channels)) {
            return false;
        }

        $suffix = '|' . $channel;
        $matched = [];
        foreach (\array_keys($channels) as $key) {
            if (\is_string($key) && str_ends_with($key, $suffix)) {
                $bundle = \substr($key, 0, \strlen($key) - \strlen($suffix));
                if (!\in_array($bundle, $this->trustedBundles, true)) {
                    continue;
                }

                $matched[] = $key;
            }
        }

        if (0 === \count($matched)) {
            return null;
        }

        if (1 < \count($matched)) {
            return false;
        }

        $entry = $channels[$matched[0]];

        return \is_array($entry) ? $entry : false;
    }

    /**
     * Does this level declare a tools block at all? null = absent (inherit).
     * Assumes toolsError() already passed for a present block.
     *
     * @param array<string, mixed> $level
     * @return null|array{allow: array<int, string>, alsoAllow: array<int, string>, deny: array<int, string>}
     */
    private function toolsOf(array $level): ?array
    {
        $tools = $level['tools'] ?? null;
        if (null === $tools) {
            return null;
        }

        $out = [];
        foreach (['allow', 'alsoAllow', 'deny'] as $list) {
            $entries = $tools[$list] ?? [];
            $clean = [];
            foreach ($entries as $entry) {
                $clean[] = \trim((string) $entry);
            }

            $out[$list] = $clean;
        }

        return $out;
    }

    /**
     * Why a present tools block cannot be honoured, or null when it (or its
     * absence) is fine. Malformed policy must never silently unrestrict: the
     * caller turns any error into a refuse-everything scope.
     *
     * @param array<string, mixed> $level
     */
    private function toolsError(array $level): ?string
    {
        $tools = $level['tools'] ?? null;
        if (null === $tools) {
            return null;
        }

        if (!\is_array($tools)) {
            return 'a stored tools block is malformed';
        }

        foreach (['allow', 'alsoAllow', 'deny'] as $list) {
            $entries = $tools[$list] ?? [];
            if (!\is_array($entries)) {
                return 'the stored ' . $list . ' list is malformed';
            }

            foreach ($entries as $entry) {
                if (!\is_string($entry)) {
                    return 'the ' . $list . " list holds a non-string entry";
                }

                if ('' === \trim($entry)) {
                    return 'the ' . $list . ' list holds an empty entry';
                }
            }
        }

        return null;
    }

    /**
     * A policy entry the name-matcher could never speak with: an argument
     * spelling. Names and name globs only (the scope decides by name).
     *
     * @param array<int, string> $entries
     */
    private function firstMalformedEntry(array $entries): ?string
    {
        foreach ($entries as $entry) {
            if (str_contains($entry, '(')) {
                return "the entry '" . $entry . "' spells arguments, and this policy decides by tool name";
            }
        }

        return null;
    }

}
