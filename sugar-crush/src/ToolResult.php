<?php

declare(strict_types=1);

namespace SugarCraft\Crush;

use SugarCraft\Mosaic\Mosaic;

/**
 * Represents the result of a tool/function call.
 *
 * Tool results are added to the conversation history so the AI
 * can see the outcome of its requested action and respond
 * accordingly.
 *
 * The imageBytes/imagePath/imageProtocol fields deliberately store the raw
 * bytes rather than reusing {@see \SugarCraft\Crush\Attachment} (this
 * codebase's other image-attachment value object, consumed by {@see
 * \SugarCraft\Crush\Messages\UserMessage::withImage()}): a tool's output is
 * frequently produced entirely in-memory (e.g. a rendered diagnostic PNG,
 * see {@see \SugarCraft\Crush\Tools\BuiltIn\Doctor}) with no
 * filesystem path to construct an `Attachment` from, whereas `Attachment`
 * models a user-supplied, file-backed image. If a future step needs to
 * bridge the two (e.g. surfacing a tool-produced image back into the user
 * message history), convert at that boundary rather than collapsing these
 * two differently-shaped representations into one.
 */
final class ToolResult
{
    /**
     * Process-lifetime cache of the terminal's image-rendering capability.
     *
     * Mirrors candy-mosaic's own {@see Mosaic::auto()} contract: probe the
     * terminal once (DA1 + XTWINOPS, ~100ms) and reuse the answer for every
     * subsequent image-bearing tool result instead of re-querying the TTY
     * per call.
     *
     * W1.G2/E2 fix (reviewer-reported): this cache is now the single
     * shared probe-once instance exposed via {@see mosaic()} and threaded
     * into {@see \SugarCraft\Crush\Chat}'s constructor as its `mosaic:`
     * parameter by {@see \SugarCraft\Crush\Cli\Bootstrap::chat()} -- the
     * spec's literal `new Chat(..., mosaic: $mosaic)` -- rather than being
     * a standalone capture point Chat never wires through. A future
     * renderer (E3, not part of this step) reads the same instance off
     * `Chat::mosaic()` instead of re-probing or reaching into this class's
     * private state.
     */
    private static ?Mosaic $mosaic = null;

    /**
     * @param string $name The name of the tool that was called
     * @param string $result The output/result from the tool
     * @param string|null $error Error message if the tool failed, null on success
     * @param string|null $id ID matching the corresponding ToolCall
     * @param string|null $imageBytes Raw image bytes captured by the tool (e.g. a screenshot), if any
     * @param string|null $imagePath Filesystem path the image was captured from/saved to, if any
     * @param string|null $imageProtocol The candy-mosaic protocol ({@see Mosaic::protocol()}) detected
     *                                   at capture time -- 'kitty'|'sixel'|'iterm2'|'halfblock'|'quarterblock'|'chafa'
     */
    public function __construct(
        public readonly string $name,
        public readonly string $result,
        public readonly ?string $error = null,
        public readonly ?string $id = null,
        public readonly ?string $imageBytes = null,
        public readonly ?string $imagePath = null,
        public readonly ?string $imageProtocol = null,
    ) {}

    /**
     * Create a successful result.
     */
    public static function ok(string $name, string $result, ?string $id = null): self
    {
        return new self($name, $result, null, $id);
    }

    /**
     * Create an error result.
     */
    public static function error(string $name, string $error, ?string $id = null): self
    {
        return new self($name, '', $error, $id);
    }

    /**
     * Create a successful result that also carries raw image bytes (e.g. a
     * tool that captured a screenshot). Probes the terminal's image
     * capability once via {@see Mosaic::auto()} -- never throws -- so a
     * later renderer (see E3, not part of this step) knows which protocol
     * to paint with without re-probing the TTY per tool call.
     *
     * Mirrors sugar-crush's candy-mosaic wiring plan (crush_feat.md section
     * 9, E1): "Extend ToolResult.php with an optional imageBytes field."
     */
    public static function okWithImage(string $name, string $result, string $imageBytes, ?string $id = null): self
    {
        return new self(
            $name,
            $result,
            null,
            $id,
            imageBytes: $imageBytes,
            imageProtocol: self::probeMosaic()->protocol(),
        );
    }

    /**
     * Fluent attach of image bytes onto an existing result, e.g. a tool
     * that already produced text output also captured a screenshot.
     * Returns a new instance -- $this is left untouched, per this repo's
     * immutable+with*() convention (see AGENTS.md).
     */
    public function withImage(string $imageBytes, ?string $imagePath = null): self
    {
        return new self(
            $this->name,
            $this->result,
            $this->error,
            $this->id,
            imageBytes: $imageBytes,
            imagePath: $imagePath,
            imageProtocol: self::probeMosaic()->protocol(),
        );
    }

    /**
     * True when this result carries image bytes for in-TUI rendering.
     */
    public function hasImage(): bool
    {
        return $this->imageBytes !== null;
    }

    /**
     * Probe-once capture point for candy-mosaic's terminal capability
     * detection (crush_feat.md section 9, E2). Memoizes {@see Mosaic::auto()}
     * for the lifetime of the process so repeated {@see okWithImage()}/
     * {@see withImage()} calls don't each re-probe the TTY.
     */
    private static function probeMosaic(): Mosaic
    {
        return self::$mosaic ??= Mosaic::auto();
    }

    /**
     * Public accessor for the same probe-once {@see Mosaic} instance
     * {@see probeMosaic()} caches -- the shared instance {@see
     * \SugarCraft\Crush\Cli\Bootstrap::chat()} threads into {@see
     * \SugarCraft\Crush\Chat}'s `mosaic:` constructor parameter (W1.G2/E2),
     * so a future renderer (E3) reads the SAME detected protocol this
     * class's own image-bearing results were built from, rather than
     * re-probing the TTY independently.
     */
    public static function mosaic(): Mosaic
    {
        return self::probeMosaic();
    }

    /**
     * Convert to array for serialization (wire format).
     *
     * @return array{role:string,tool_call_id:string,name:string,content:string}
     */
    public function toWire(): array
    {
        return [
            'role' => 'tool',
            'tool_call_id' => $this->id ?? $this->name,
            'name' => $this->name,
            'content' => $this->error ?? $this->result,
        ];
    }

    public function isError(): bool
    {
        return $this->error !== null;
    }
}
