<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Host\Commands;

use SugarCraft\Crush\CommandParser;
use SugarCraft\Crush\Commands\PlayCommand;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Support\MediaStore;

/**
 * `/play <file|artifact-name>` — open the full-screen video player on a
 * finished artifact or a local clip (crush-media W5.4).
 *
 * Resolution order: a token with a slash must name a real, readable file
 * (the typed path IS the consent, same law as {@see GenerateHostCommand}'s
 * mask flag); a bare name is looked up in the session's media store by
 * artifact id, exact filename, or filename without extension, limited to
 * the container types sugar-reel's decoder family speaks (gif decodes in
 * pure PHP, the rest through the accounted ffmpeg spawn). The screen
 * itself opens through the {@see CommandEffect::openVideoPlayer()} door —
 * the player is shell state, so the command only asks the shell to raise
 * it and no per-command arm exists in the router.
 *
 * Permission reasoning (recorded per house law): slash commands never
 * reach the Ask gate, and this one needs no other consent — it reads
 * local disk, writes nothing, spends no money, and the one child process
 * it can spawn (the decoder ffmpeg) is a read-only media pump that
 * tools/check-child-lifetimes.php accounts and the player's shutdown
 * teardown reaps. Hence `play` joins READ_ONLY_COMMANDS: a read-only
 * session may still watch the videos it already made.
 */
final class PlayHostCommand implements HostCommand
{
    /** Container types the player speaks (sugar-reel DecoderFactory family). */
    public const PLAYABLE_EXTENSIONS = ['gif', 'mp4', 'webm', 'mov', 'mkv', 'avi'];

    /**
     * @param string|null $mediaRoot store-root override (tests); null = the
     *        owned default under the home directory
     */
    public function __construct(private readonly ?string $mediaRoot = null)
    {
    }

    public function run(CommandContext $context, string $text): CommandResult
    {
        $parsed = (new CommandParser())->parse($text);
        $tokens = $parsed?->args ?? [];

        if ($tokens === []) {
            return CommandResult::failure($text, PlayCommand::usage(), 1);
        }

        $plan = PlayCommand::parse($tokens);

        if ($plan['help']) {
            return CommandResult::reply($text, PlayCommand::help());
        }

        if ($plan['error'] !== null) {
            return CommandResult::failure($text, $plan['error'], 1);
        }

        $target = (string) $plan['path'];

        return str_contains($target, '/')
            ? $this->fromPath($text, $target)
            : $this->fromStore($context, $text, $target);
    }

    /** A typed path: real, readable, done. No jail — the words are the consent. */
    private function fromPath(string $text, string $target): CommandResult
    {
        if (!is_file($target) || !is_readable($target)) {
            return CommandResult::failure($text, 'no readable file at ' . $target, 1);
        }

        $real = realpath($target);

        return $real === false
            ? CommandResult::failure($text, 'cannot resolve ' . $target, 1)
            : $this->playing($text, $real);
    }

    /** A bare artifact name: exactly one match in this session's store. */
    private function fromStore(CommandContext $context, string $text, string $target): CommandResult
    {
        $store = $this->storeFor($context);

        if ($store === null) {
            return CommandResult::failure($text, 'no media store is reachable — /play <path> still plays a local file', 1);
        }

        $wanted = strtolower($target);

        foreach ($store->list() as $row) {
            $filename = (string) $row['filename'];

            if (!\in_array(strtolower((string) pathinfo($filename, PATHINFO_EXTENSION)), self::PLAYABLE_EXTENSIONS, true)) {
                continue;
            }

            $stem = strtolower(pathinfo($filename, PATHINFO_FILENAME));

            if ($row['id'] === $target || strtolower($filename) === $wanted || $stem === $wanted) {
                $path = (string) $row['path'];

                return is_readable($path)
                    ? $this->playing($text, $path)
                    : CommandResult::failure($text, 'artifact ' . $target . ' points at an unreadable file', 1);
            }
        }

        return CommandResult::failure($text, sprintf(
            'no playable artifact named %s — /play also takes a local path',
            $target,
        ), 1);
    }

    /** The session's store, or null when its home is unowned/unreadable. */
    private function storeFor(CommandContext $context): ?MediaStore
    {
        try {
            return MediaStore::forSession($context->currentSessionId() ?? 'clipboard', $this->mediaRoot);
        } catch (\Throwable) {
            return null;
        }
    }

    private function playing(string $text, string $absolute): CommandResult
    {
        return CommandResult::new(
            Message::user($text),
            Message::assistant(sprintf(
                'playing %s — space play/pause, arrows seek, m mode, q/Esc close',
                basename($absolute),
            )),
        )->withEffect(CommandEffect::openVideoPlayer($absolute));
    }
}
