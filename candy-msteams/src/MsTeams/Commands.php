<?php

declare(strict_types=1);

namespace CandyCode\Bundles\MsTeams\MsTeams;

/**
 * The command vocabulary in Teams spelling.
 *
 * Discord got native slash deployment (ruling D7=7a); Teams has no
 * equivalent deploy leg for a message bot — command UX there is messaging
 * extensions (invoke), which is P3.5 territory alongside Graph consent.
 * So the TEXT fallback is the whole surface in v1: the same `/word` roster
 * the §7.9 approval law already requires, mention-prefixed or bare. An
 * approval path that depends on any card render fails closed; this parser
 * is why it doesn't have to.
 */
final class Commands
{
    /** The shared §7.6 roster — identical vocabulary in both bundles. */
    public const ROSTER = ['new', 'stop', 'approve', 'queue', 'model', 'pair'];

    /**
     * Teams-only words on top of the shared roster: discord answers these
     * natively (or not at all), so they stay out of the §7.6 list.
     */
    public const TEAMS_EXTRA = ['help', 'status', 'cost'];

    /** One line per command the bundle answers, in the order /help lists them. */
    public const HELP = [
        'help' => 'this list',
        'new' => 'start a fresh session for this conversation',
        'stop' => 'cancel the running turn',
        'status' => 'session id, state, queue mode and approvals awaiting an answer',
        'cost' => 'the spend the host reports for this session',
        'queue' => '`/queue <text>` queues a prompt behind the running turn',
        'model' => '`/model [name]` runs the session\'s /model command',
        'approve' => '`/approve <askId> [once|always|deny]` answers a tool approval in chat',
        'pair' => 'pairing for approvers: `/pair`, `/pair approve <code>`',
    ];

    /**
     * Parse the TEXT command line: a bare `/word …`, possibly preceded by
     * `<at>Name</at>` mention markup (Teams delivers the bot mention as
     * inline markup in the activity text for some clients).
     *
     * @return array{name: string, args: list<string>}|null
     */
    public static function parseText(string $content): ?array
    {
        $text = \trim($content);
        // Strip every leading <at>…</at> token (with trailing whitespace).
        $text = (string) \preg_replace('/^(<at>.*?<\/at>\s*)+/is', '', $text);
        if ($text === '' || $text[0] !== '/') {
            return null;
        }
        $words = \preg_split('/\s+/', \substr($text, 1));
        if ($words === false || $words === []) {
            return null;
        }
        $name = \strtolower(\array_shift($words));
        if (!\in_array($name, \array_merge(self::ROSTER, self::TEAMS_EXTRA), true)) {
            return null;
        }

        return ['name' => $name, 'args' => $words];
    }

    /** The /help body (Teams markdown). Lists exactly what HELP names. */
    public static function helpText(): string
    {
        $lines = ['**candy-code commands**'];
        foreach (self::HELP as $name => $description) {
            $lines[] = \str_contains($description, '`') ? "- {$description}" : "- `/{$name}`: {$description}";
        }
        $lines[] = 'Anything else you write is a prompt for the session.';

        return \implode("\n", $lines);
    }
}
