/**
 * Pasted images for the composer (WEB-5b) and attached documents (C-WEB-4.e).
 * The browser hands clipboard files as Blobs; `session.send`'s `images` wants
 * strict-base64 payloads with the sniffed raster type declared, and its
 * `documents` the same shape over the document kinds the server admits. This
 * module is the whole conversion — kept a plain function over a
 * ClipboardData-shaped input so a test can feed it a fake without a real
 * clipboard, and the component stays presentation.
 */

/** The rasters the server sniffs and admits; mirrors TurnMethods::PASTED_IMAGE_MIMES. */
export const PASTED_IMAGE_MIMES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'] as const

export type PastedImageMime = (typeof PASTED_IMAGE_MIMES)[number]

/** One entry of `session.send`'s `images` array. */
export interface PastedImage {
  name: string
  mime: PastedImageMime
  /** Strict base64 of the file's bytes, no data-URL prefix. */
  data: string
}

/** One clipboard entry as browsers (and jsdom fixtures) expose it. */
export interface ClipboardItem {
  readonly kind: string
  readonly type: string
  getAsFile(): File | null
}

/** The slice of a ClipboardEvent this reader needs; a DataTransfer satisfies it structurally. */
export interface ClipboardFiles {
  items: Iterable<ClipboardItem>
}

/** Four images max, exactly the server's `MAX_IMAGES` — the popup would only refuse a fifth. */
export const MAX_PASTED_IMAGES = 4

function isPastedImageMime(type: string): type is PastedImageMime {
  return (PASTED_IMAGE_MIMES as readonly string[]).includes(type)
}

// ── rejection notices (NIT web58-1) ────────────────────────────────────
// A paste entry the browser reports with no type at all cannot be matched
// against the raster list, so it is skipped. Skipping it SILENTLY was the
// complaint: the composer took nothing and said nothing. The reader emits a
// notice over this channel; the app root renders it, same live-region idea
// as the question announcements (lib/questions).

type PasteNoticeListener = (notice: string) => void
const noticeListeners = new Set<PasteNoticeListener>()

/** Subscribe to paste rejections; returns the unsubscribe. */
export function onPasteNotice(listener: PasteNoticeListener): () => void {
  noticeListeners.add(listener)
  return () => noticeListeners.delete(listener)
}

function announce(notice: string): void {
  for (const listener of noticeListeners) listener(notice)
}

/** The notice text for $count typeless paste entries. */
export function typelessPasteNotice(count: number): string {
  return `${count} pasted ${count === 1 ? 'file was' : 'files were'} skipped: the browser reported no type, and only ${PASTED_IMAGE_MIMES.join(', ')} images can be sent.`
}

/** Base64 of bytes without building one giant string per slice (btoa is latin1). */
export function bytesToBase64(bytes: Uint8Array): string {
  let binary = ''
  const chunks = 0x8000
  for (let offset = 0; offset < bytes.length; offset += chunks) {
    binary += String.fromCharCode(...bytes.subarray(offset, offset + chunks))
  }
  return btoa(binary)
}

async function readImage(file: File): Promise<PastedImage> {
  const mime = file.type as PastedImageMime
  const bytes = new Uint8Array(await file.arrayBuffer())
  return {
    name: file.name !== '' ? file.name : `pasted-image.${mime.slice('image/'.length)}`,
    mime,
    data: bytesToBase64(bytes),
  }
}

// ── paste chips (C-WEB-4.a) ────────────────────────────────────────────
// A long text paste used to flood the textarea, exactly the frame problem
// ISS-051 solved on the TUI: the draft there collapses such a paste to a
// one-line chip and `Chat::expandPasteChips()` puts the payload back at every
// reader that treats the draft as sent text. The composer lands the same
// machine client-side: the thresholds mirror `Chat::PASTE_COLLAPSE_LINES` /
// `Chat::PASTE_COLLAPSE_BYTES`, and the chip shape is this surface's own —
// `[Pasted #n +X lines]`.

/** Collapse a paste taller than this many lines (mirrors `Chat::PASTE_COLLAPSE_LINES`). */
export const PASTE_CHIP_LINES = 10

/** ...or heavier than this many bytes (mirrors `Chat::PASTE_COLLAPSE_BYTES`). */
export const PASTE_CHIP_BYTES = 2048

/** Would the TUI collapse this text on paste? (Either bound alone; both exclusive.) */
export function pasteNeedsCollapse(text: string): boolean {
  return text.split('\n').length > PASTE_CHIP_LINES || new TextEncoder().encode(text).length > PASTE_CHIP_BYTES
}

/** The one-line placeholder filing away a paste of $lines lines, numbered $n. */
export function pasteChip(n: number, lines: number): string {
  return `[Pasted #${n} +${lines} lines]`
}

/**
 * Replace every chip in $text with the payload it filed, in chip order.
 * Unknown or hand-re-typed chip text does not match the map and survives
 * literally — the same law as `Chat::expandPasteChips()`.
 */
export function expandPasteChips(text: string, chips: ReadonlyMap<string, string>): string {
  let expanded = text
  for (const [chip, payload] of chips) {
    expanded = expanded.split(chip).join(payload)
  }
  return expanded
}

/**
 * The image files of a paste, in clipboard order, capped at MAX_PASTED_IMAGES.
 * Anything that is not one of the sniffed rasters — text, files, an svg — is
 * not a pasted image here; the server would refuse it, the popup never offers it.
 */
export async function readPastedImages(clipboard: ClipboardFiles | null | undefined, cap = MAX_PASTED_IMAGES): Promise<PastedImage[]> {
  const entries = Array.from(clipboard?.items ?? [])
  const typeless = entries.filter((entry) => entry.kind === 'file' && entry.type === '').length
  if (typeless > 0) announce(typelessPasteNotice(typeless))
  const files = entries
    .filter((item) => item.kind === 'file' && isPastedImageMime(item.type))
    .map((item) => item.getAsFile())
    .filter((file): file is File => file !== null)
    .slice(0, cap)
  return Promise.all(files.map(readImage))
}

// ── attached documents (C-WEB-4.e) ─────────────────────────────────────
// A PDF rides `session.send`'s `documents`: the same {name?, mime, data}
// envelope as an image. The server sniffs the bytes and the sniff is the
// authority — this reader only ever offers files the browser reports as one
// of the admitted kinds, so the popup never carries a refusal it could have
// avoided. Counts and shapes mirror TurnMethods::MAX_DOCUMENTS and
// TurnMethods::PASTED_DOCUMENT_MIMES.

/** The document kinds the server admits; mirrors TurnMethods::PASTED_DOCUMENT_MIMES. */
export const PASTED_DOCUMENT_MIMES = ['application/pdf'] as const

export type PastedDocumentMime = (typeof PASTED_DOCUMENT_MIMES)[number]

/** One entry of `session.send`'s `documents` array. */
export interface PastedDocument {
  name: string
  mime: PastedDocumentMime
  /** Strict base64 of the file's bytes, no data-URL prefix. */
  data: string
}

/** Two documents max, exactly the server's `MAX_DOCUMENTS`. */
export const MAX_PASTED_DOCUMENTS = 2

function isPastedDocumentMime(type: string): type is PastedDocumentMime {
  return (PASTED_DOCUMENT_MIMES as readonly string[]).includes(type)
}

async function readDocument(file: File): Promise<PastedDocument> {
  const mime = file.type as PastedDocumentMime
  const bytes = new Uint8Array(await file.arrayBuffer())
  return {
    name: file.name !== '' ? file.name : `pasted-document.${mime.slice('application/'.length)}`,
    mime,
    data: bytesToBase64(bytes),
  }
}

/**
 * The document files of a paste or drop, in clipboard order, capped at
 * MAX_PASTED_DOCUMENTS. Typeless entries are the image reader's notice to
 * make (readPastedImages runs over the same clipboard); this reader picks up
 * only what it recognises and announces nothing of its own.
 */
export async function readDroppedDocuments(clipboard: ClipboardFiles | null | undefined, cap = MAX_PASTED_DOCUMENTS): Promise<PastedDocument[]> {
  const files = Array.from(clipboard?.items ?? [])
    .filter((item) => item.kind === 'file' && isPastedDocumentMime(item.type))
    .map((item) => item.getAsFile())
    .filter((file): file is File => file !== null)
    .slice(0, cap)
  return Promise.all(files.map(readDocument))
}

/**
 * The file-picker halves: a `<input type=file>` hands plain Files, not
 * clipboard items, so these convert the admitted ones in list order under the
 * same per-kind cap. The composer adds them to the standing strip, which owns
 * the merge-time budget.
 */
export async function imagesFromFiles(files: Iterable<File>, cap = MAX_PASTED_IMAGES): Promise<PastedImage[]> {
  const picked = Array.from(files)
    .filter((file) => isPastedImageMime(file.type))
    .slice(0, cap)
  return Promise.all(picked.map(readImage))
}

export async function documentsFromFiles(files: Iterable<File>, cap = MAX_PASTED_DOCUMENTS): Promise<PastedDocument[]> {
  const picked = Array.from(files)
    .filter((file) => isPastedDocumentMime(file.type))
    .slice(0, cap)
  return Promise.all(picked.map(readDocument))
}
