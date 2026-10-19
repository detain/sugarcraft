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
  const files = Array.from(clipboard?.items ?? [])
    .filter((item) => item.kind === 'file' && isPastedImageMime(item.type))
    .map((item) => item.getAsFile())
    .filter((file): file is File => file !== null)
    .slice(0, cap)
  return Promise.all(files.map(readImage))
}
