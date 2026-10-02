<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\BuiltIn;

use SugarCraft\Crush\ToolResult as BootProbe;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolResult;
use SugarCraft\Mosaic\Mosaic;

/**
 * Reports the terminal's detected candy-mosaic image-rendering protocol and
 * attaches a tiny capability-swatch PNG -- a genuinely reachable, real
 * caller of an image-bearing {@see ToolResult}.
 *
 * W1.G2 reachability fix (reviewer-reported): the previous 'doctor' wiring
 * lived only on {@see \SugarCraft\Crush\Chat}'s registerTool()/
 * beginToolCalls()/forkToolCalls() dispatch, which never fires in
 * production -- every real completion goes through {@see
 * \SugarCraft\Crush\Backend\EngineBackend}, which resolves tool calls
 * internally via {@see \SugarCraft\Crush\Runtime} against the {@see Tool}[]
 * array {@see \SugarCraft\Crush\Cli\Bootstrap::tools()} builds. This class
 * IS one of those tools -- registered there, advertised in the real LLM
 * tool-calling schema, and invoked by Runtime::executeToolCalls() exactly
 * like Bash/Read/Edit/Glob/Grep/WebFetch.
 *
 * THE TOOL NEVER TOUCHES THE TERMINAL (audit F-T6). It used to keep its own
 * `self::$mosaic ??= Mosaic::auto()`, which ran the DA1 + XTWINOPS probe the
 * first time the model called `doctor` — and that call happens inside the
 * forked turn child, which inherits the TUI's tty descriptors. The child then
 * wrote escape queries into the frame the parent was drawing and drained up
 * to ~150 ms of stdin, so keystrokes typed meanwhile were lost and the
 * terminal's reply could land in the parent's input parser as garbage. It now
 * reads {@see BootProbe::mosaic()}, the probe-once instance the parent ran at
 * boot ({@see \SugarCraft\Crush\Cli\Bootstrap::chat()} hands it to `Chat`)
 * and the fork inherited; the answer is the same one the renderer uses, so
 * the two can no longer disagree either. A headless launch enters at
 * `Bootstrap::backend()` and never builds a `Chat`, so there that accessor
 * still probes on first use — harmless only because no TUI owns the tty on
 * that path; warming it at boot belongs to `Bootstrap`, not to this tool.
 *
 * Deliberately stops at producing the image-bearing result and threading it
 * back onto the root {@see \SugarCraft\Crush\Message} (see {@see
 * \SugarCraft\Crush\Backend\EngineBackend::complete()}) -- the full
 * ImageOverlay/View::images pixel-rendering pipeline is E3 (crush_feat.md
 * section 9), a separate, later step.
 */
final class Doctor implements Tool
{
    /**
     * `doctor`, lowercase on purpose (E10, tracker #78): sibling built-ins
     * answer in TitleCase and this name deliberately stays out of step,
     * because the model has been trained on prompts containing `doctor` — the
     * rename is the risky half and belongs to whoever owns the tool schema,
     * not to a review round. The status quo is pinned as intentional in
     * {@see \SugarCraft\Crush\Tests\Tools\BuiltInToolTest::testDoctorToolHasCorrectName()}.
     */
    public function name(): string
    {
        return 'doctor';
    }

    public function description(): string
    {
        return 'Report the image-rendering protocol of the current terminal, as detected '
            . 'by the candy-mosaic probe the client ran once at startup; calling it never '
            . 're-queries the terminal. It takes no parameters, and its answer is a line of text '
            . 'naming the pixel-graphics protocol that was found (Kitty, Sixel, or iTerm2) '
            . 'or reporting that only a text-cell fallback is available, alongside a '
            . '16-by-16 PNG capability swatch rendered green on a real pixel protocol and '
            . 'amber on the fallback. Reach for it when a decision depends on which image '
            . 'protocol this terminal speaks, such as before attaching an image to a '
            . 'response. It only reports and samples the capability: it does not render or '
            . 'overlay pixels in the terminal itself.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            // stdClass, not []: this tool takes no parameters, and PHP renders
            // an empty array as the JSON array `[]` where JSON Schema demands
            // the object `{}`. A strict server (SGLang) rejects the whole
            // chat/completions request over it, not just this tool, which left
            // the agent unable to send any message at all.
            // {@see \SugarCraft\Crush\Providers\Concerns\ToolSchema} repeats
            // the correction at the wire boundary for tools written later.
            'properties' => new \stdClass(),
            'required' => [],
        ];
    }

    public function execute(array $args): ToolResult
    {
        $mosaic = BootProbe::mosaic();
        $protocol = $mosaic->protocol();
        $pixelGraphics = !$mosaic->isInline();

        $summary = $pixelGraphics
            ? "Detected pixel-graphics protocol: {$protocol}."
            : "No pixel-graphics protocol detected; using '{$protocol}' text-cell fallback.";

        return new ToolResult(
            toolCallId: $args['id'] ?? '',
            content: $summary,
            imageBytes: self::capabilitySwatchPng($pixelGraphics),
            imageProtocol: $protocol,
        );
    }

    /**
     * A tiny (16x16), freshly-rendered PNG swatch -- green when the boot-time
     * {@see Mosaic::auto()} probe detected a real pixel-graphics protocol
     * (Kitty/Sixel/iTerm2), amber when it fell back to a text-cell renderer
     * (half-block/quarter-block/chafa). Genuinely computed from the live
     * probe result rather than a hardcoded placeholder.
     */
    private static function capabilitySwatchPng(bool $pixelGraphicsDetected): string
    {
        $image = imagecreatetruecolor(16, 16);
        $color = $pixelGraphicsDetected
            ? imagecolorallocate($image, 0x2e, 0xa0, 0x4a)
            : imagecolorallocate($image, 0xd9, 0x8a, 0x1f);
        imagefill($image, 0, 0, $color);

        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return $bytes === false ? '' : $bytes;
    }
}
