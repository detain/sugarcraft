<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tools\BuiltIn;

use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Support\MediaStore;
use SugarCraft\Code\Tools\Catalog\BuildsFromCatalog;
use SugarCraft\Code\Tools\Catalog\BuiltInTool;
use SugarCraft\Code\Tools\Catalog\ToolBuildContext;
use SugarCraft\Code\Tools\Catalog\ToolPermissionClass;
use SugarCraft\Code\Tools\DelegatesToEngine;
use SugarCraft\Code\Tools\TakesToolCallId;
use SugarCraft\Code\Tools\Tool;
use SugarCraft\Code\Tools\ToolResult;

/**
 * Save a self-contained HTML page as a session artifact (C-TOOL-6.f,
 * GAP-TL-24).
 *
 * The model writes a complete page — markup, styles, scripts inline — and the
 * bytes land in the session's media store under `~/.candy-code/media/`, at
 * `0600`, temp-then-rename, exactly where generated images land: the store's
 * own `putDocument()` is the door, so this class calls no write primitive of
 * its own and RuntimeTest's not-a-tree-write roster can hold it beside
 * `GenerateImage` honestly.
 *
 * WHY NOASK AND NOT READ. `ToolPermissionClass` defines Read as "changes
 * nothing"; Canvas changes something — it writes a file — but it writes only
 * state the harness owns (its own session media directory, the same argument
 * the GenerateImage roster note makes; that tool is Ask only because it dials
 * a server, which this one never does). NoAsk is the honest label. Both
 * classes are allowed in every permission mode, so no mode prompts on it
 * either way.
 *
 * WHY THE BYTES ARE NOT SANITISED. The TUI never renders the page: the result
 * carries no `imagePath` and no `imageBytes`, so `TranscriptProjector`
 * projects no media ref and no painter receives the content. The display half
 * is the web `CanvasCard` (C-TOOL-6.g), which loads the artifact in a
 * sandboxed, no-same-origin iframe under its own strict CSP — the containment
 * lives at the render door, where a sanitizer at the store door would only
 * mangle pages that never reach any terminal.
 *
 * WHY THERE IS NO `MediaKind::Html` HERE. The enum doubles as the protocol
 * `MEDIA_REF.kind` vocabulary (`Definitions.php` builds the union from
 * `cases()`) and as the `mediaKinds` provider vocabulary, so a new case is a
 * schema-shape change — that step belongs to the web/protocol half together
 * with the projector's kind arm, not to the writer.
 *
 * ENGINE DELEGATION. The class implements {@see DelegatesToEngine} for one
 * read: the bound engine's `sessionId()`, which picks the session folder the
 * artifact lands in — exactly {@see GenerateImage}'s use of the interface,
 * same `'main'` fallback when nothing is bound. `EngineBackend::turnTools()`
 * binds every such tool per turn, so the engine needs no edit for this. The
 * accepted side effect is the one every engine-bound tool shares:
 * `EngineExecutor` keeps `DelegatesToEngine` tools out of sub-agent toolsets.
 */
#[BuiltInTool(name: self::NAME, permission: ToolPermissionClass::NoAsk, position: 45, gloss: 'save a self-contained HTML page as a session artifact the web client can display')]
final readonly class CanvasTool implements Tool, BuildsFromCatalog, DelegatesToEngine, TakesToolCallId
{
    public const NAME = 'Canvas';

    /** Largest page the tool accepts; a breach refuses rather than truncating. */
    public const MAX_BYTES = 524288; // 512 KiB

    /** `title` arg budget, in characters (the label is prose, not an id). */
    public const MAX_TITLE_CHARS = 80;

    /** Session folder for MediaStore when the bound engine has no session id. */
    private const FALLBACK_SESSION = 'main';

    /**
     * `$mediaRoot` is a test seam relocating the artifact tree; production
     * builds carry only nulls (the store's own home-rooted default).
     */
    private function __construct(
        private ?EngineBackend $engine = null,
        private ?string $mediaRoot = null,
    ) {
    }

    public static function new(): self
    {
        return new self();
    }

    public static function fromCatalog(ToolBuildContext $context): self
    {
        return new self();
    }

    public function withEngine(EngineBackend $engine, ?\Closure $heartbeat = null, ?\Closure $subAgentEmitter = null): self
    {
        return new self($engine, $this->mediaRoot);
    }

    /** The same tool writing under $root instead of the home tree (a test seam). */
    public function withMediaRoot(?string $root): self
    {
        return new self($this->engine, $root);
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Save a complete, self-contained HTML page as a session artifact. The page must carry everything it'
            . ' renders inline (styles, scripts, data as data: URIs) — it is served later from a sandboxed frame with'
            . ' no network of its own. Use it for what a terminal cannot show: diagrams, styled reports, small'
            . ' interactive pages. The answer names the artifact id and path; nothing else displays the page yet.'
            . ' At most ' . self::MAX_BYTES . ' bytes.';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'html' => [
                    'type' => 'string',
                    'description' => 'The whole page, self-contained: a complete document with inline styles and'
                        . ' scripts, no external references (up to ' . self::MAX_BYTES . ' bytes of UTF-8)',
                ],
                'title' => [
                    'type' => 'string',
                    'description' => 'Short human label for the result line (at most '
                        . self::MAX_TITLE_CHARS . ' characters)',
                ],
            ],
            'required' => ['html'],
        ];
    }

    public function execute(array $args): ToolResult
    {
        $callId = (string) ($args['id'] ?? '');
        try {
            return $this->save($args, $callId);
        } catch (\Throwable $e) {
            return new ToolResult($callId, 'canvas could not be saved: ' . $e->getMessage(), true);
        }
    }

    /**
     * @param array<string, mixed> $args
     */
    private function save(array $args, string $callId): ToolResult
    {
        $html = $args['html'] ?? null;
        if (!\is_string($html) || trim($html) === '') {
            return new ToolResult($callId, 'the html argument must be a non-empty string', true);
        }
        if (!mb_check_encoding($html, 'UTF-8')) {
            return new ToolResult($callId, 'the html argument must be valid UTF-8', true);
        }
        $bytes = \strlen($html);
        if ($bytes > self::MAX_BYTES) {
            return new ToolResult($callId, 'the page is ' . $bytes . ' bytes, over the ' . self::MAX_BYTES
                . '-byte canvas cap; a page this large belongs in files the model can edit, not in an artifact', true);
        }

        $title = \is_string($args['title'] ?? null) && $args['title'] !== '' ? $args['title'] : 'canvas';
        if (mb_strlen($title) > self::MAX_TITLE_CHARS) {
            return new ToolResult($callId, 'the title is ' . mb_strlen($title) . ' characters, over the '
                . self::MAX_TITLE_CHARS . '-character cap', true);
        }

        $session = $this->engine?->sessionId() ?? self::FALLBACK_SESSION;
        $store = MediaStore::forSession($session, $this->mediaRoot);
        $path = $store->putDocument($html, 'html');

        return new ToolResult($callId, 'Canvas saved — ' . $title . ': artifact ' . MediaStore::idOf(basename($path))
            . ', ' . $path . ' (' . $bytes . ' bytes)');
    }
}
