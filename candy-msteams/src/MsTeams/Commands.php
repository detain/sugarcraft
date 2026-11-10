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
        if (!\in_array($name, self::ROSTER, true)) {
            return null;
        }

        return ['name' => $name, 'args' => $words];
    }
}
