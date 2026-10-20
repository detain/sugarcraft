/**
 * Pasted images for the composer (WEB-5b). The browser hands clipboard files
 * as Blobs; `session.send`'s `images` wants strict-base64 payloads with the
 * sniffed raster type declared. This module is the whole conversion — kept a
 * plain function over a ClipboardData-shaped input so a test can feed it a
 * fake without a real clipboard, and the component stays presentation.
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
