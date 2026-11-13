<?php

declare(strict_types=1);

namespace CandyCode\Bundles\MsTeams\MsTeams;

use SugarCraft\Code\Lang;

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
        'help' => 'channels.msteams.help.cmd.help',
        'new' => 'channels.msteams.help.cmd.new',
        'stop' => 'channels.msteams.help.cmd.stop',
        'status' => 'channels.msteams.help.cmd.status',
        'cost' => 'channels.msteams.help.cmd.cost',
        'queue' => 'channels.msteams.help.cmd.queue',
        'model' => 'channels.msteams.help.cmd.model',
        'approve' => 'channels.msteams.help.cmd.approve',
        'pair' => 'channels.msteams.help.cmd.pair',
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
        $lines = [Lang::t('channels.msteams.help.heading')];
        foreach (self::HELP as $name => $key) {
            $description = Lang::t($key);
            $lines[] = \str_contains($description, '`') ? "- {$description}" : "- `/{$name}`: {$description}";
        }
        $lines[] = Lang::t('channels.msteams.help.footer');

        return \implode("\n", $lines);
    }
}
