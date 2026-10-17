<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host\Commands;

use SugarCraft\Code\CommandParser;
use SugarCraft\Code\Commands\SendToCommand;
use SugarCraft\Code\Media\ImageIngress;
use SugarCraft\Code\Media\MediaRequest;
use SugarCraft\Code\Message;
use SugarCraft\Code\Tui\MediaForm\ImportPng;

/**
 * `/sendto <file.png> [--to img2img|inpaint] [--mask FILE]` — send a saved
 * PNG back into the generate form (crush-media W4.4).
 *
 * The file's own parameters chunk is imported over a fresh draft — local
 * first, the gated server png-info helper second, loud refusal when neither
 * answers ({@see ImportPng::import()}) — and the form opens on the result
 * through the same {@see CommandEffect::openMediaForm()} door `/generate`
 * flipped to in W3.1. `--to` additionally attaches the artifact itself as
 * the init image; `--mask` (inpaint only) rides the draft's mask field,
 * where the W4.3 mask controls will read it once that section is wired.
 *
 * Local-path law: every read goes through {@see ImageIngress::fromPath()},
 * the single door that refuses URLs and data: URIs without fetching, caps
 * the size, and validates the bytes. Nothing is dialed on the command's
 * word alone: the server fallback inside {@see ImportPng::serverInfoFor()}
 * answers null — without opening a socket — unless this launch configures
 * an SD base over a family the import speaks.
 *
 * The {@see $serverInfo} seam lets tests pin the fallback chain with a
 * double; the registry builds this class with no arguments (`new $class()`),
 * which selects the gated production hop.
 */
final class SendToHostCommand implements HostCommand
{
    /**
     * @param (callable(string):?string)|null $serverInfo png-info channel
     *        seam (base64 PNG => infotext or null when unavailable)
     */
    public function __construct(private readonly ?\Closure $serverInfo = null)
    {
    }

    public function run(CommandContext $context, string $text): CommandResult
    {
        $parsed = (new CommandParser())->parse($text);
        $tokens = $parsed?->args ?? [];

        if ($tokens === []) {
            // Unlike /generate, the bare command has no subject to send —
            // the usage answer is the whole reply; no form opens.
            return CommandResult::failure($text, SendToCommand::usage(), 1);
        }

        $plan = SendToCommand::parse($tokens);

        if ($plan['help']) {
            return CommandResult::reply($text, SendToCommand::help());
        }

        if ($plan['error'] !== null) {
            return CommandResult::failure($text, $plan['error'], 1);
        }

        return $this->send($text, $plan);
    }

    /**
     * @param array{path: ?string, to: ?string, mask: ?string, help: bool, error: ?string} $plan
     */
    private function send(string $text, array $plan): CommandResult
    {
        $path = (string) $plan['path'];

        try {
            $ingress = ImageIngress::fromPath($path);
        } catch (\Throwable $error) {
            return CommandResult::failure($text, 'cannot read ' . $path . ': ' . $error->getMessage(), 1);
        }

        $hop = $this->serverInfo ?? static fn (string $base64): ?string => ImportPng::serverInfoFor($base64);
        $imported = ImportPng::import($ingress->bytes, MediaRequest::new(), $hop);

        if ($imported['error'] !== null) {
            return CommandResult::failure($text, $path . ': ' . $imported['error'], 1);
        }

        $request = $imported['request'];
        \assert($request instanceof MediaRequest);

        if ($plan['to'] !== null) {
            $request = $request->withInitImages([$ingress->base64]);
        }

        if ($plan['mask'] !== null) {
            try {
                $mask = ImageIngress::fromPath($plan['mask']);
            } catch (\Throwable $error) {
                return CommandResult::failure($text, 'cannot read mask ' . $plan['mask'] . ': ' . $error->getMessage(), 1);
            }

            $request = $request->withMask($mask->base64);
        }

        return CommandResult::new(
            Message::user($text),
            Message::assistant($this->row($ingress, $request, $plan, $imported['via'])),
        )->withEffect(CommandEffect::openMediaForm($request));
    }

    /**
     * @param array{path: ?string, to: ?string, mask: ?string, help: bool, error: ?string} $plan
     */
    private function row(ImageIngress $ingress, MediaRequest $request, array $plan, ?string $via): string
    {
        $mode = $plan['to'] === null
            ? 'parameters only'
            : $plan['to'] . ($plan['mask'] !== null ? ' + mask' : '');

        return sprintf(
            'sent %s to the generate form — %d fields imported (%s, %s)',
            $ingress->name(),
            count($request->toArray()),
            $via === ImportPng::VIA_SERVER ? ImportPng::VIA_SERVER_LABEL : ImportPng::VIA_LOCAL_LABEL,
            $mode,
        );
    }
}
