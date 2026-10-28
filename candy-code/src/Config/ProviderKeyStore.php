<?php

declare(strict_types=1);

namespace SugarCraft\Code\Config;

use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Lang;

/**
 * The persist half of `candy-code login <provider>` — the typed API key's
 * whole write path into the user `config.json`'s `providers` map, lifted out
 * of {@see \SugarCraft\Code\Cli\Subcommands::loginStoreKey()} (C-TUI-5.d,
 * plan_comparison §5) so the first-run wizard's host can land a key through
 * exactly the door the CLI already uses, without a TTY conversation.
 *
 * The semantics are byte-faithful to the login verb, because a key stored by
 * either path must be indistinguishable on disk:
 *
 *  - the file's EXISTING `providers` map is merged, never replaced — one
 *    provider's key persist keeps every other provider's block (model,
 *    thinkingBudget, modelPrices, baseUrl — the C-PRV-1.c fields) intact;
 *  - a block already typed as a DIFFERENT provider is refused
 *    (`cli.sub.login.type_mismatch`), not silently re-typed;
 *  - control characters are refused (`cli.sub.login.control_chars`) —
 *    a pasted key rides the config file verbatim, and a control byte in it
 *    is a forgery vector for the human reader of the file later;
 *  - a non-array JSON body is refused (`cli.sub.login.corrupt_config`),
 *    never repaired;
 *  - the write goes through {@see Bootstrap::writeUserConfig()}, the one
 *    sanctioned `config.json` door (it can bail unwritten under lock
 *    contention or an unresolvable symlink), and the settle is VERIFIED by
 *    re-reading the exact slot before any success is promised — the same
 *    no-fabricated-success law `login` already obeys;
 *  - after a verified write, `login`'s own success echo
 *    (`cli.sub.login.stored`, which names the winning env variable from its
 *    private roster) is the caller's to print.
 *
 * The interactive half — the prompt, the line read, the trim — stays with
 * `login` itself; nothing in this file echoes or reads STDIN.
 *
 * `login` keeps its own persist door until a follow-up step re-points it
 * here (the census rosters credit both while they coexist); this class is
 * NOT dormant-by-accident — C-TUI-5.e wires it as the wizard's finish arm.
 */
final class ProviderKeyStore
{
    /**
     * Persist $key for provider $type.
     *
     * @return null the key was written AND verified by re-read — the caller
     *     may announce success; a string is a finished, translated refusal
     *     message the caller should echo verbatim (nothing was written).
     */
    public static function store(string $type, string $key): ?string
    {
        $path = Bootstrap::userConfigPath();

        if ($key === '') {
            return Lang::t('cli.sub.login.empty_key');
        }

        if (\preg_match('/[\x00-\x1F\x7F]/', $key) === 1) {
            return Lang::t('cli.sub.login.control_chars');
        }

        $providers = [];
        if (\is_file($path)) {
            $raw = \file_get_contents($path);
            if ($raw !== false && $raw !== '') {
                $decoded = \json_decode($raw, true);
                if (!\is_array($decoded)) {
                    return Lang::t('cli.sub.login.corrupt_config', ['path' => $path]);
                }

                if (\is_array($decoded['providers'] ?? null)) {
                    $providers = $decoded['providers'];
                }
            }
        }

        $block = $providers[$type] ?? null;
        if (\is_array($block) && \array_key_exists('type', $block) && $block['type'] !== $type) {
            $actual = \is_string($block['type']) ? $block['type'] : \get_debug_type($block['type']);

            return Lang::t('cli.sub.login.type_mismatch', ['path' => $path, 'provider' => $type, 'actual' => $actual]);
        }

        $providers[$type] = [...(\is_array($block) ? $block : []), 'type' => $type, 'apiKey' => $key];
        Bootstrap::writeUserConfig(['providers' => $providers]);

        // writeUserConfig() can bail without writing (lock contention, an
        // unresolvable symlink); a success nobody verified is exactly the
        // fabricated path the login verb refuses, so re-read the slot.
        $settled = \json_decode((string) @\file_get_contents($path), true);
        if (!\is_array($settled) || ($settled['providers'][$type]['apiKey'] ?? null) !== $key) {
            return Lang::t('cli.sub.login.stored_failed', ['path' => $path]);
        }

        return null;
    }
}
