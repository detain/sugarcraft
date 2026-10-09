<?php

declare(strict_types=1);

namespace SugarCraft\Crush;

use SugarCraft\Crush\Permissions\DenialKind;
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
     * Process-lifetime cache of the terminal's image-rendering capability,
     * keyed by the configured render mode (W2.2).
     *
     * Mirrors candy-mosaic's own {@see Mosaic::auto()} contract: probe the
     * terminal once (DA1 + XTWINOPS, ~100ms) and reuse the answer for every
     * subsequent image-bearing tool result instead of re-querying the TTY
     * per call. W2.2 makes that memo MODE-AWARE: one entry per render-mode
     * word, so switching {@see setRenderMode()} re-derives instead of
     * handing back the stale probe of whichever mode was asked for first.
     * Within a process the detection answer itself is stable, which is why
     * the mode string alone is the key.
     *
     * W1.G2/E2 fix (reviewer-reported): this cache is the single
     * shared probe-once instance exposed via {@see mosaic()} and threaded
     * into {@see \SugarCraft\Crush\Chat}'s constructor as its `mosaic:`
     * parameter by {@see \SugarCraft\Crush\Cli\Bootstrap::chat()} -- the
     * spec's literal `new Chat(..., mosaic: $mosaic)` -- rather than being
     * a standalone capture point Chat never wires through. A future
     * renderer (E3, not part of this step) reads the same instance off
     * `Chat::mosaic()` instead of re-probing or reaching into this class's
     * private state.
     *
     * @var array<string, Mosaic>
     */
    private static array $mosaics = [];

    /**
     * The launch's resolved `ui.imageRenderMode` word ('auto' until
     * {@see \SugarCraft\Crush\Cli\Bootstrap::applyMediaRenderMode()} runs).
     *
     * THE LADDER, for the record (crush_media §8.2, candy-mosaic
     * `Detect.php:17` precedence): the `auto` image ladder is
     * kitty > iterm2 > sixel > chafa > halfblock. sugar-reel's VIDEO
     * ladder is deliberately different (sixel-first, crush_media §8.3) --
     * do not "harmonize" them. Grid layouts force cell renderers
     * regardless of mode (crush_media W2.4/W5.6).
     */
    private static string $renderMode = 'auto';

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
     * @param string|null $description The {@see Message::describeToolCall()} one-liner for the CALL
     *                                 this result answers -- e.g. `bash(command: "ls -la")`, or the
     *                                 model-authored summary when the turn supplied one. Display-only
     *                                 and deliberately NOT part of {@see toEngineResult()}/
     *                                 {@see fromEngineResult()}: the engine pair carries what the
     *                                 MODEL is shown, and this string exists solely so
     *                                 {@see \SugarCraft\Crush\Renderer::renderToolResults()} can say
     *                                 WHAT ran on a finished (and therefore collapsed) row. It is
     *                                 attached by {@see \SugarCraft\Crush\Chat} at the point the
     *                                 "running" placeholder is replaced, because that placeholder is
     *                                 the only carrier of the call's arguments that survives to the
     *                                 finish of an engine-dispatched call (crush_feat.md §3 E2/§1 E5).
     * @param array<string, mixed> $arguments The model's raw arguments for the CALL this result
     *                                        answers. Display-only for the same reasons as
     *                                        $description, and attached at the same point: it
     *                                        is what an EXPANDED row paints above the output
     *                                        (`$ ls -la` for a shell call), because the
     *                                        one-liner is bounded and, whenever the model sent
     *                                        a `description`, never names the command at all.
     * @param DenialKind|null $denial Set when the call was STOPPED before it ran, by whichever
     *                                party refused it (audit F-P8). This, never the text of
     *                                $error, is what {@see Chat::isDeniedResult()} reads to draw
     *                                the struck-through row: $error is the tool's own output,
     *                                and a tool can print anything — `Permission denied:`
     *                                included. Mirrors {@see EngineToolResult::denial()} and
     *                                crosses both adapters.
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
        public readonly ?string $description = null,
        public readonly array $arguments = [],
        public readonly ?DenialKind $denial = null,
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
     * Create the result of a call that was STOPPED before it ran: an error
     * whose text is $kind's rendered reason and which carries $kind
     * structurally, so a reader never has to classify the text (audit F-P8).
     */
    public static function denied(string $name, DenialKind $kind, string $detail, ?string $id = null): self
    {
        return new self($name, '', $kind->reason($detail), $id, denial: $kind);
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
            description: $this->description,
            arguments: $this->arguments,
            denial: $this->denial,
        );
    }

    /**
     * Fluent attach of the human-readable one-liner describing the CALL this
     * result answers -- see the constructor's `$description` docblock for why
     * a display-only field is carried here rather than derived at render time.
     *
     * Returns a new instance per this repo's immutable+with*() convention
     * (see AGENTS.md); an empty/blank $description is treated as "unknown"
     * and clears the field, so a placeholder that never had one cannot make
     * the renderer draw a dangling separator.
     */
    public function withDescription(?string $description): self
    {
        $trimmed = $description === null ? null : trim($description);

        return new self(
            $this->name,
            $this->result,
            $this->error,
            $this->id,
            $this->imageBytes,
            $this->imagePath,
            $this->imageProtocol,
            $this->diff,
            $this->durationMs,
            $trimmed === '' ? null : $trimmed,
            $this->arguments,
            $this->denial,
        );
    }

    /**
     * Fluent attach of the raw arguments of the CALL this result answers --
     * see the constructor's `$arguments` docblock. Returns a new instance.
     *
     * @param array<string, mixed> $arguments
     */
    public function withArguments(array $arguments): self
    {
        return new self(
            $this->name,
            $this->result,
            $this->error,
            $this->id,
            $this->imageBytes,
            $this->imagePath,
            $this->imageProtocol,
            $this->diff,
            $this->durationMs,
            $this->description,
            $arguments,
            $this->denial,
        );
    }

    /**
     * True when this result knows the call it answers, i.e. it can tell the
     * user WHAT ran and not merely which tool ran.
     */
    public function hasDescription(): bool
    {
        return $this->description !== null;
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
     * This result's diff as the before/after text of each changed region —
     * {@see diffTextsOf()} over {@see $diff}.
     *
     * @return list<array{path: string, oldText: ?string, newText: string}>
     */
    public function diffTexts(): array
    {
        return self::diffTextsOf((string) $this->diff);
    }

    /**
     * A unified diff as the before/after text of each hunk: what an editor
     * that renders a change from its two sides (the Agent Client Protocol's
     * `{type: "diff", path, oldText, newText}`, roadmap 5.9-2) needs, where
     * {@see $diff} is the patch a terminal renders. Context and removed lines
     * make `oldText`, context and added lines `newText`; a hunk that starts
     * at `-0,0` created the file, so its `oldText` is null. The path is the
     * `+++ b/` header's, without the `a/`/`b/` prefix.
     *
     * DRIVEN BY THE HUNK HEADERS' COUNTS, not by line prefixes: a removed line
     * that reads `-- a/x` is `--- a/x` in the patch, and only the count says
     * it is still inside the hunk. A diff that is empty or does not parse
     * yields nothing.
     *
     * @return list<array{path: string, oldText: ?string, newText: string}>
     */
    public static function diffTextsOf(string $diff): array
    {
        $hunks = [];
        $path = null;
        $lines = explode("\n", $diff);
        $count = \count($lines);
        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];
            if (str_starts_with($line, '--- ')) {
                $path ??= self::diffPath(substr($line, 4));
                continue;
            }
            if (str_starts_with($line, '+++ ')) {
                $path = self::diffPath(substr($line, 4));
                continue;
            }
            if ($path === null || preg_match('/^@@ -(\d+)(?:,(\d+))? \+\d+(?:,(\d+))? @@/', $line, $m) !== 1) {
                continue;
            }

            $oldLeft = ($m[2] ?? '') === '' ? 1 : (int) $m[2];
            $newLeft = ($m[3] ?? '') === '' ? 1 : (int) $m[3];
            $created = $m[1] === '0' && $oldLeft === 0;
            $old = [];
            $new = [];
            while (($oldLeft > 0 || $newLeft > 0) && $i + 1 < $count) {
                $body = $lines[++$i];
                $mark = $body === '' ? ' ' : $body[0];
                $text = substr($body, 1);
                if ($mark === '\\') {
                    continue;
                }
                if ($mark === '-' && $oldLeft > 0) {
                    $old[] = $text;
                    $oldLeft--;
                } elseif ($mark === '+' && $newLeft > 0) {
                    $new[] = $text;
                    $newLeft--;
                } elseif ($mark === ' ') {
                    $old[] = $text;
                    $new[] = $text;
                    $oldLeft--;
                    $newLeft--;
                } else {
                    break;
                }
            }

            $hunks[] = [
                'path' => $path,
                'oldText' => $created ? null : implode("\n", $old),
                'newText' => implode("\n", $new),
            ];
        }

        return $hunks;
    }

    /** A `---`/`+++` header's path, without its `a/`/`b/` prefix or a trailing timestamp. */
    private static function diffPath(string $header): string
    {
        $path = explode("\t", $header, 2)[0];

        return preg_match('#^[ab]/#', $path) === 1 ? substr($path, 2) : $path;
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
            denial: $this->denial,
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
            denial: $result->denial(),
        );
    }

    /**
     * Probe-once capture point for candy-mosaic's terminal capability
     * detection (crush_feat.md section 9, E2), mode-aware since W2.2
     * (crush_media §8.7 gap-1): memoized per render-mode word, so a mode
     * switch derives a fresh Mosaic instead of returning another mode's
     * cached probe. `'auto'` keeps the original detect-based behaviour --
     * probe the terminal, never throw.
     *
     * An unknown word fails SOFT to `auto` (the settings Enum validator is
     * the upstream gate; this hop stays silent, no error_log, so the
     * StderrEmitterCensus gains no site).
     */
    private static function probeMosaic(): Mosaic
    {
        $mode = self::$renderMode;

        // fromModeString() answers 'auto' with Mosaic::auto() itself and
        // null only for words mosaic has never heard of -- one hop covers
        // the forced modes and the fail-soft fallback.
        return self::$mosaics[$mode] ??= Mosaic::fromModeString($mode) ?? Mosaic::auto();
    }

    /**
     * Set the render mode {@see probeMosaic()} derives from (W2.2). Called
     * once per launch by {@see \SugarCraft\Crush\Cli\Bootstrap::applyMediaRenderMode()}
     * with the resolved `ui.imageRenderMode` value; empty or whitespace-only
     * input normalizes to `'auto'`, asking the terminal. The word is stored
     * normalized (trimmed, lower-cased) because mosaic's own vocabulary
     * comparator is, and the memo key must not fork on spelling noise.
     */
    public static function setRenderMode(string $mode): void
    {
        $normalized = strtolower(trim($mode));

        self::$renderMode = $normalized === '' ? 'auto' : $normalized;
    }

    /**
     * The currently configured render-mode word (see {@see setRenderMode()}).
     */
    public static function renderMode(): string
    {
        return self::$renderMode;
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
