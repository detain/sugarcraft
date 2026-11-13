<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Runtime;

use SugarCraft\ScriptUi\Style\StyleBuilder;
use SugarCraft\Style\Style;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * The lipgloss style surface gum embeds in EVERY interactive command.
 *
 * scriptui-26 (candy-scriptui-025): the builder plumbing existed (StyleCommand
 * alone consumed it) while a themed `gum choose --border rounded …` died as
 * an unknown option on candy. These flags now register on the seven
 * interactive commands and ride the models as real frame chrome.
 *
 * Scope law: `--width`/`--height` keep their existing MEANING (content
 * dimensions — the frame grows around them), and `--reverse` stays the
 * row-order flag where it already lives (choose/filter): the SGR reverse
 * leg is deliberately absent rather than semantically hijacked.
 */
final class StyleOptions
{
    /** @var list<string> */
    public const ATTRS = ['bold', 'faint', 'italic', 'underline', 'strikethrough'];

    /**
     * Register the shared style options on a command. Callers that already
     * own one of these names for other semantics must opt out via $skip.
     *
     * @param list<string> $skip option names this command declares itself
     */
    public static function register(Command $command, array $skip = []): void
    {
        foreach (['foreground', 'background', 'border-foreground', 'border-background', 'border', 'padding', 'margin', 'align'] as $name) {
            if (in_array($name, $skip, true)) {
                continue;
            }
            $command->addOption($name, null, InputOption::VALUE_REQUIRED, self::describe($name));
        }
        foreach (self::ATTRS as $name) {
            if (in_array($name, $skip, true)) {
                continue;
            }
            $command->addOption($name, null, InputOption::VALUE_NONE, self::describe($name));
        }
    }

    /**
     * Collect the registered flags into the StyleBuilder vocabulary.
     *
     * @return array<string, mixed>
     */
    public static function flags(InputInterface $input): array
    {
        $flags = [];
        foreach (['foreground', 'background', 'border-foreground', 'border-background', 'border', 'padding', 'margin', 'align', ...self::ATTRS] as $name) {
            $flags[$name] = $input->getOption($name);
        }

        return $flags;
    }

    /** @param array<string, mixed> $flags */
    public static function active(array $flags): bool
    {
        foreach ($flags as $value) {
            if ($value !== null && $value !== '' && $value !== false && $value !== 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * The frame style for a run, or null when no style flag was given —
     * the untouched legacy path (byte-identical frames, zero overhead).
     *
     * @param array<string, mixed> $flags
     * @throws \InvalidArgumentException passthrough of StyleBuilder's refusals
     */
    public static function frame(array $flags): ?Style
    {
        if (!self::active($flags)) {
            return null;
        }

        // Colour/decoration precedence is the gate's, not ours — the
        // application already installed the resolved profile for this run
        // (Application::doRunCommand), so a themed frame under --no-ansi
        // drops to plain structure: border glyphs survive, escapes do not.
        return StyleBuilder::fromFlags($flags);
    }

    private static function describe(string $name): string
    {
        return match ($name) {
            'foreground'        => 'Text colour (hex, ANSI index 0-15, or colour name).',
            'background'        => 'Background colour (same vocabulary as --foreground).',
            'border-foreground' => 'Border colour.',
            'border-background' => 'Background colour of the border itself.',
            'border'            => 'Frame border style: none|normal|rounded|thick|double|block|ascii|hidden.',
            'padding'           => 'Inner spacing: all | vertical,horizontal | top,right,bottom,left.',
            'margin'            => 'Outer spacing: all | vertical,horizontal | top,right,bottom,left.',
            'align'             => 'Horizontal alignment of content inside the frame: left|center|right.',
            'bold'              => 'Bold text.',
            'faint'             => 'Faint text.',
            'italic'            => 'Italic text.',
            'underline'         => 'Underline text.',
            'strikethrough'     => 'Cross out text.',
            default             => $name,
        };
    }
}
