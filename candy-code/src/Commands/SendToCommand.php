<?php

declare(strict_types=1);

namespace SugarCraft\Code\Commands;

/**
 * argv-style parser behind the `/sendto` console command (crush-media W4.4).
 *
 * `/sendto` sends a saved PNG back INTO the generate form: the file's own
 * infotext chunk is imported (local-first, {@see
 * \SugarCraft\Code\Tui\MediaForm\ImportPng}) and the result opens as a form
 * draft; `--to` additionally attaches the artifact as an img2img init image
 * (or an inpaint one, with `--mask` riding the draft's mask field — the
 * plan's build step 2 mode wiring). The reverse trip — artifact out to a
 * channel — is not this command; the name reads as the form does, "send
 * this file to the form".
 *
 * Like {@see GenerateCommand} this class is pure: no transport, no settings,
 * no Chat state. A path that is missing, unreadable, or not a PNG is a
 * usage-level refusal sentence the host command renders as a row — nothing
 * throws upward, and remote spellings (http(s)://, data:) are refused here
 * at the door because the import law never fetches (mirrors ImageIngress's
 * local-only rule).
 */
final class SendToCommand
{
    /** The modes `--to` accepts (plan §2.18's button set, import direction). */
    public const MODES = ['img2img', 'inpaint'];

    /** Short one-line synopsis used for the bare-command usage answer. */
    public const USAGE = '/sendto <file.png> [--to img2img|inpaint] [--mask FILE]';

    private function __construct()
    {
    }

    /**
     * Parse quote-split argv tokens (everything after `/sendto`).
     *
     * @param list<string> $tokens
     * @return array{path: ?string, to: ?string, mask: ?string, help: bool, error: ?string}
     *   `path` is the single positional PNG; `to`/`mask` are the optional
     *   mode wires; `help` short-circuits to the flag block; `error` carries
     *   the usage-level refusal sentence and empties everything else.
     */
    public static function parse(array $tokens): array
    {
        $path = null;
        $to = null;
        $mask = null;
        $help = false;

        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token === '--help' || $token === '-h') {
                $help = true;
                continue;
            }

            if ($token === '--to' || $token === '--mask') {
                $value = $tokens[$i + 1] ?? null;
                if ($value === null || str_starts_with($value, '--')) {
                    return self::refusal(sprintf('The flag %s needs a value.', $token));
                }
                $i++;

                if ($token === '--to') {
                    if (!\in_array($value, self::MODES, true)) {
                        return self::refusal(sprintf(
                            '--to expects one of %s, got "%s". %s',
                            implode(' or ', self::MODES),
                            $value,
                            self::USAGE,
                        ));
                    }
                    $to = $value;
                    continue;
                }

                $mask = $value;
                continue;
            }

            if (str_starts_with($token, '--')) {
                return self::refusal(sprintf('Unknown flag "%s". %s', $token, self::USAGE));
            }

            if ($path !== null) {
                return self::refusal(sprintf('Expected one PNG path, got "%s" and "%s". %s', $path, $token, self::USAGE));
            }
            $path = $token;
        }

        if ($help) {
            return ['path' => null, 'to' => null, 'mask' => null, 'help' => true, 'error' => null];
        }

        if ($path === null) {
            return self::refusal('A saved PNG path is required, for example: /sendto output.png --to img2img');
        }

        // Scheme-shaped paths are remote by spelling; the import law is
        // local-only (mirrors ImageIngress's URL refusal), refused at parse.
        $lower = strtolower($path);
        if (str_contains($lower, '://') || str_starts_with($lower, 'data:')) {
            return self::refusal('remote images are never fetched for import — save the PNG locally first. ' . self::USAGE);
        }

        if (preg_match('/\.png$/i', $path) !== 1) {
            return self::refusal(sprintf('"%s" is not a .png — the infotext import reads PNG files only.', $path));
        }

        if ($mask !== null && $to !== 'inpaint') {
            return self::refusal('the --mask flag needs --to inpaint — a mask only travels with an inpaint send.');
        }

        return ['path' => $path, 'to' => $to, 'mask' => $mask, 'help' => false, 'error' => null];
    }

    /** The bare-command answer: /sendto is meaningless without a subject. */
    public static function usage(): string
    {
        return self::USAGE . "\nAdd --help for the full flag table.";
    }

    /** Long, line-per-option help block rendered from the tables above. */
    public static function help(): string
    {
        return implode("\n", [
            self::USAGE,
            '',
            'Imports the PNG\'s own parameters chunk (server png-info only when',
            'the chunk is absent and an SD base is configured) into a fresh',
            'generate-form draft. Values already on the draft are never',
            'overwritten (the onlyUnset law).',
            '',
            'Options:',
            '  --to img2img   attach the file as the init image',
            '  --to inpaint   attach it as init image; --mask then applies',
            '  --mask FILE    inpaint mask PNG (requires --to inpaint)',
        ]);
    }

    /** @return array{path: null, to: null, mask: null, help: bool, error: string} */
    private static function refusal(string $error): array
    {
        return ['path' => null, 'to' => null, 'mask' => null, 'help' => false, 'error' => $error];
    }
}
