<?php

declare(strict_types=1);

namespace SugarCraft\Crush;

use SugarCraft\Crush\Tools\ToolResult as EngineToolResult;
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
 *
 * This is the Chat/Renderer-side half of the two type pairs crush_feat.md
 * §1 D flags as "a maintenance hazard independent of the rendering gap".
 * {@see EngineToolResult} (`Tools\ToolResult`) is the canonical pair -- it
 * is what the {@see Tools\Tool} interface returns, what {@see Runtime}
 * collects and what {@see \SugarCraft\Crush\Events\ToolFinished} carries --
 * while this type is what {@see Message}'s `$toolResults` and {@see
 * Renderer} render. The two are reconciled by the lossless {@see
 * toEngineResult()}/{@see fromEngineResult()} adapters rather than by
 * rewriting every consumer of either pair at once (crush_feat.md §1 E1).
 * For those adapters to be genuinely lossless this type carries the two
 * fields only the engine side had -- `$diff` (W1.F1, the unified diff
 * crush_feat.md §1 E3 wants rendered, which otherwise could not reach
 * {@see Renderer} at all since the renderer only ever sees THIS type) and
 * `$durationMs` -- exactly as the engine side already carries the image
 * fields that started life only here.
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
     * @param string|null $diff Raw unified diff produced by an edit-shaped tool, kept OFF $result
     *                          so a renderer can hand it straight to a diff viewer instead of
     *                          string-scanning free text (mirrors {@see EngineToolResult::diff()})
     * @param int|null $durationMs Wall-clock execution time, if the dispatching pipeline measured it
     */
    public function __construct(
        public readonly string $name,
        public readonly string $result,
        public readonly ?string $error = null,
        public readonly ?string $id = null,
        public readonly ?string $imageBytes = null,
        public readonly ?string $imagePath = null,
        public readonly ?string $imageProtocol = null,
        public readonly ?string $diff = null,
        public readonly ?int $durationMs = null,
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
            diff: $this->diff,
            durationMs: $this->durationMs,
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
     * True when this result carries a unified diff for a renderer to show
     * (crush_feat.md §1 E3) instead of only a "File updated: …" one-liner.
     */
    public function hasDiff(): bool
    {
        return $this->diff !== null;
    }

    /**
     * Adapt this Chat-side result to the canonical engine-side
     * {@see EngineToolResult} the {@see Tools\Tool} interface, {@see Runtime}
     * and {@see \SugarCraft\Crush\Events\ToolFinished} speak (crush_feat.md
     * §1 E1).
     *
     * The engine pair carries one `content` string plus an `isError` flag
     * where this pair splits `$result`/`$error`, so the collapse is the same
     * `$this->error ?? $this->result` {@see toWire()} has always used for the
     * provider wire-shape. `toolCallId` is non-nullable engine-side and falls
     * back to the tool name, matching {@see ToolCall::toEngineCall()} so a
     * call and its result still key identically across the seam.
     */
    public function toEngineResult(): EngineToolResult
    {
        return new EngineToolResult(
            toolCallId: $this->id ?? $this->name,
            content: $this->error ?? $this->result,
            isError: $this->isError(),
            durationMs: $this->durationMs,
            imageBytes: $this->imageBytes,
            imagePath: $this->imagePath,
            imageProtocol: $this->imageProtocol,
            diff: $this->diff,
        );
    }

    /**
     * Adapt a canonical engine-side {@see EngineToolResult} into the
     * Chat/Renderer-side shape. Inverse of {@see toEngineResult()}.
     *
     * $name must be supplied by the caller because the engine pair keys
     * purely by `toolCallId` and carries no tool name -- the dispatching
     * side always has the matching {@see ToolCall}, and inventing a name
     * here (e.g. reusing the id) would put a call id in front of the user
     * wherever {@see Renderer} prints `tool: <name>`.
     */
    public static function fromEngineResult(EngineToolResult $result, string $name): self
    {
        return new self(
            $name,
            $result->isError() ? '' : $result->content(),
            $result->isError() ? $result->content() : null,
            $result->toolCallId(),
            $result->imageBytes(),
            $result->imagePath(),
            $result->imageProtocol(),
            $result->diff(),
            $result->durationMs(),
        );
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
