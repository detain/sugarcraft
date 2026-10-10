import type { MediaRef, MethodName, MethodParams, MethodResult } from '../protocol/generated'

/**
 * The typed RPC call, shaped exactly like `SugarCrushClient.request` (and the
 * connection store's wrapper of the same name), so either can be handed to
 * {@see fetchArtifact} without an adapter.
 */
export type MediaRequest = <M extends MethodName>(method: M, params: MethodParams<M>) => Promise<MethodResult<M>>

/** Anything that can make a typed method call — the connection store satisfies it. */
export interface ArtifactCaller {
  request: MediaRequest
}

/** Bytes asked for per `media.fetch` slice; the server caps one slice at 4 MiB. */
export const FETCH_SLICE_BYTES = 1_048_576

/**
 * Slice pages before a fetch is refused (64 MiB) — the same 64-page ethic as
 * `session.loadFullOutput`'s `tool.output` loop, scaled to the media slice.
 */
export const MAX_FETCH_SLICES = 64

/** A refusal worth showing on the card: a broken answer, or an oversized artifact. */
export class MediaFetchError extends Error {}

/** The owner stopped caring (unmount, replacement); never surface as an error. */
export class MediaFetchCancelled extends Error {}

/**
 * The object-URL seam. MediaCard never calls `URL.createObjectURL` directly:
 * every created URL is tracked here so tests can assert the revoke-on-unmount
 * and revoke-on-replacement laws (the leak law) without a real browser.
 */
export const objectUrls = {
  create(blob: Blob): string {
    return URL.createObjectURL(blob)
  },
  revoke(url: string): void {
    URL.revokeObjectURL(url)
  },
}

/** Decode one base64 slice the way `media.fetch` delivers it (std alphabet, padded). */
export function decodeBase64(chunk: string): Uint8Array {
  const binary = atob(chunk)
  const bytes = new Uint8Array(binary.length)
  for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i)
  return bytes
}

/**
 * Assemble one artifact's whole blob through `media.fetch`'s offset/limit
 * slicing — the browser's mirror of the server's `tool.output` pattern
 * (Appendix C-7: refs ride the journal, bytes are pulled on demand). The mime
 * rides the offset-0 row only; {@see MediaRef.mime} is the fallback.
 *
 * `shouldStop` is consulted before every slice, so an unmounting component
 * cancels the loop instead of racing it (the cancelled rejection is the
 * caller's cue to drop the result).
 */
export async function fetchArtifact(
  caller: ArtifactCaller,
  sessionId: string,
  media: MediaRef,
  shouldStop: () => boolean = () => false,
): Promise<{ blob: Blob; mime: string }> {
  const parts: Uint8Array[] = []
  let delivered = 0
  let offset = 0
  let mime = media.mime
  for (let page = 0; page < MAX_FETCH_SLICES; page++) {
    if (shouldStop()) throw new MediaFetchCancelled('media fetch was cancelled')
    const row = await caller.request('media.fetch', { sessionId, artifactId: media.artifactId, offset, limit: FETCH_SLICE_BYTES })
    if (page === 0 && typeof row.mime === 'string' && row.mime !== '') mime = row.mime
    if (row.offset !== offset) throw new MediaFetchError(`media.fetch answered at offset ${row.offset}, asked at ${offset}`)
    const bytes = decodeBase64(row.bytes)
    if (row.eof) {
      const total = delivered + bytes.length
      if (total !== row.totalBytes) throw new MediaFetchError(`media.fetch delivered ${total} of ${row.totalBytes} bytes`)
      parts.push(bytes)
      return { blob: new Blob(parts as BlobPart[], { type: mime }), mime }
    }
    if (bytes.length === 0) throw new MediaFetchError('media.fetch ended an empty slice without eof')
    parts.push(bytes)
    delivered += bytes.length
    offset += bytes.length
  }
  throw new MediaFetchError(`artifact exceeds the ${MAX_FETCH_SLICES}-slice fetch budget`)
}
