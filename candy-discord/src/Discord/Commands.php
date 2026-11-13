<?php

declare(strict_types=1);

namespace CandyCode\Bundles\Discord\Discord;

use SugarCraft\Code\Lang;

/**
 * The command vocabulary (§7.6): NATIVE slash commands deployed with a
 * global PUT on boot (owner ruling D7=7a) AND the text fallback that works
 * from the first message — mention-prefixed or `/name` lines in a channel
 * the bot can read.
 *
 * The fallback is not decoration: slash registration is eventual (the
 * client caches the command list), and DMs show no command palette until
 * the app is installed there — per the docs, a DM slash command "will not
 * autocomplete or show parameter choices". `/approve` in particular must
 * work as plain text: an approval path that depends on a component render
 * is an approval path that fails closed when components fail.
 */
final class Commands
{
    /**
     * The global command roster for the boot PUT —
     * /applications/<id>/commands (https://discord.com/developers/docs/interactions/application-commands#bulk-overwrite-global-application-commands).
     *
     * @return list<array<string,mixed>>
     */
    public static function definitions(): array
    {
        return [
            [
                'name' => 'new',
                'description' => Lang::t('channels.discord.slash.new.desc'),
                'type' => 1,
                'options' => [[
                    'name' => 'name',
                    'description' => Lang::t('channels.discord.slash.new.name'),
                    'type' => 3,
                    'required' => false,
                ]],
            ],
            [
                'name' => 'stop',
                'description' => Lang::t('channels.discord.slash.stop.desc'),
                'type' => 1,
            ],
            [
                'name' => 'approve',
                'description' => Lang::t('channels.discord.slash.approve.desc'),
                'type' => 1,
                'options' => [
                    ['name' => 'ask', 'description' => Lang::t('channels.discord.slash.approve.ask'), 'type' => 3, 'required' => true],
                    ['name' => 'verdict', 'description' => Lang::t('channels.discord.slash.approve.verdict'), 'type' => 3, 'required' => false, 'choices' => [
                        ['name' => 'once', 'value' => 'once'],
                        ['name' => 'always', 'value' => 'always'],
                        ['name' => 'deny', 'value' => 'deny'],
                    ]],
                ],
            ],
            [
                'name' => 'queue',
                'description' => Lang::t('channels.discord.slash.queue.desc'),
                'type' => 1,
                'options' => [[
                    'name' => 'text',
                    'description' => Lang::t('channels.discord.slash.queue.text'),
                    'type' => 3,
                    'required' => true,
                ]],
            ],
            [
                // §7.10: the /model leg of the §2.3 surface rides command.exec.
                'name' => 'model',
                'description' => Lang::t('channels.discord.slash.model.desc'),
                'type' => 1,
                'options' => [[
                    'name' => 'name',
                    'description' => Lang::t('channels.discord.slash.model.name'),
                    'type' => 3,
                    'required' => false,
                ]],
            ],
            [
                // §7.3: pairing approval is a command on the approver roster.
                'name' => 'pair',
                'description' => Lang::t('channels.discord.slash.pair.desc'),
                'type' => 1,
                'options' => [
                    ['name' => 'action', 'description' => Lang::t('channels.discord.slash.pair.action'), 'type' => 3, 'required' => true, 'choices' => [
                        // `approve` is the deprecated spelling: still accepted (text and
                        // slash), no longer offered as a choice.
                        ['name' => 'grant', 'value' => 'grant'],
                        ['name' => 'list', 'value' => 'list'],
                    ]],
                    ['name' => 'code', 'description' => Lang::t('channels.discord.slash.pair.code'), 'type' => 3, 'required' => false],
                ],
            ],
        ];
    }

    /**
     * Parse the TEXT fallback (a bare `/word …` line, or the same after a
     * leading mention that Discord injects for guild message commands).
     *
     * @return array{name: string, args: list<string>}|null
     */
    public static function parseText(string $content): ?array
    {
        $text = \trim($content);
        // Strip every leading mention: guild commands arrive as
        // "@App slash /new hi" with one or two mentions ahead of the word.
        $text = (string) \preg_replace('/^(<@!?\d+>\s*)+/', '', $text);
        if ($text === '' || $text[0] !== '/') {
            return null;
        }
        $words = \preg_split('/\s+/', \substr($text, 1));
        if ($words === false || $words === []) {
            return null;
        }
        $name = \strtolower(\array_shift($words));
        if (!\in_array($name, ['new', 'stop', 'approve', 'queue', 'model', 'pair'], true)) {
            return null;
        }

        return ['name' => $name, 'args' => $words];
    }

    /**
     * Flatten a slash INTERACTION_CREATE's resolved options to the same
     * shape parseText returns, so one command core serves both spellings.
     *
     * @param array<string,mixed> $interaction
     *
     * @return array{name: string, args: list<string>}|null
     */
    public static function parseInteraction(array $interaction): ?array
    {
        if ((int) ($interaction['type'] ?? 0) !== Buttons::INTERACTION_TYPE_SLASH) {
            return null;
        }
        $data = \is_array($interaction['data'] ?? null) ? $interaction['data'] : [];
        $name = (string) ($data['name'] ?? '');
        if ($name === '') {
            return null;
        }
        $args = [];
        foreach ((array) ($data['options'] ?? []) as $option) {
            if (!\is_array($option)) {
                continue;
            }
            if (isset($option['value'])) {
                $args[] = (string) $option['value'];
            }
        }

        return ['name' => \strtolower($name), 'args' => $args];
    }
}
