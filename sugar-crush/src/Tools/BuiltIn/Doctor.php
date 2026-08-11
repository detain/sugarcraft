<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\BuiltIn;

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
 * Deliberately stops at producing the image-bearing result and threading it
 * back onto the root {@see \SugarCraft\Crush\Message} (see {@see
 * \SugarCraft\Crush\Backend\EngineBackend::complete()}) -- the full
 * ImageOverlay/View::images pixel-rendering pipeline is E3 (crush_feat.md
 * section 9), a separate, later step.
 */
final class Doctor implements Tool
{
    /**
     * Process-lifetime cache of the terminal's image-rendering capability --
     * probe once (DA1 + XTWINOPS, ~100ms) via {@see Mosaic::auto()} and
     * reuse the answer for every subsequent call, matching {@see
     * \SugarCraft\Crush\ToolResult::probeMosaic()}'s own memoization
     * contract for the parallel (production-unreachable) type.
     */
    private static ?Mosaic $mosaic = null;

    public function name(): string
    {
        return 'doctor';
    }

    public function description(): string
    {
        return "Report the terminal's detected image-rendering capability (candy-mosaic protocol) and attach a tiny capability-swatch PNG.";
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [],
            'required' => [],
        ];
    }

    public function execute(array $args): ToolResult
    {
        $mosaic = self::$mosaic ??= Mosaic::auto();
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
     * A tiny (16x16), freshly-rendered PNG swatch -- green when {@see
     * Mosaic::auto()} detected a real pixel-graphics protocol (Kitty/Sixel/
     * iTerm2), amber when it fell back to a text-cell renderer
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
