import { describe, expect, it, vi } from 'vitest'
import type { MediaRef, MethodName, MethodParams, MethodResult } from '../../protocol/generated'
import {
  decodeBase64,
  fetchArtifact,
  FETCH_SLICE_BYTES,
  MAX_FETCH_SLICES,
  MediaFetchCancelled,
  MediaFetchError,
  objectUrls,
} from '../media'

function ref(overrides: Partial<MediaRef> = {}): MediaRef {
  return { artifactId: 'a1', kind: 'image', path: '/media/s1/a1.png', bytes: 0, mime: 'image/png', ...overrides }
}

function enc(text: string): string {
  return btoa(text)
}

interface Row {
  artifactId: string
  offset: number
  bytes: string
  totalBytes: number
  eof: boolean
  mime?: string
}

interface FakeCaller {
  request: <M extends MethodName>(method: M, params: MethodParams<M>) => Promise<MethodResult<M>>
  calls: Record<string, unknown>[]
}

/** Emulates the server: whole artifact in memory, slice + echoed offset + eof. */
function fakeServer(content: string, opts: { mime?: string | null; totalBytes?: number; sliceBytes?: number } = {}): FakeCaller {
  const total = opts.totalBytes ?? content.length
  const calls: Record<string, unknown>[] = []
  const request = vi.fn(async <M extends MethodName>(_method: M, params: MethodParams<M>): Promise<MethodResult<M>> => {
    const p = params as unknown as Record<string, unknown>
    calls.push(p)
    const offset = Number(p.offset)
    const window = Math.min(Number(p.limit), opts.sliceBytes ?? Number.POSITIVE_INFINITY)
    const chunk = content.slice(offset, offset + window)
    const row: Row = { artifactId: 'a1', offset, bytes: enc(chunk), totalBytes: total, eof: offset + chunk.length >= total }
    // mime rides the offset-0 row only (the plan's literal).
    if (opts.mime !== null && calls.length === 1) row.mime = opts.mime ?? 'image/png'
    return row as MethodResult<M>
  })
  return { request, calls }
}

/** One canned row per call, last row repeating forever. */
function scriptedCaller(rows: Row[]): FakeCaller {
  let i = 0
  const calls: Record<string, unknown>[] = []
  const request = vi.fn(async <M extends MethodName>(_method: M, params: MethodParams<M>): Promise<MethodResult<M>> => {
    calls.push(params as unknown as Record<string, unknown>)
    return (rows[Math.min(i++, rows.length - 1)] ?? rows[0]) as MethodResult<M>
  })
  return { request, calls }
}

describe('decodeBase64', () => {
  it('decodes the padded std alphabet to bytes', () => {
    expect(Array.from(decodeBase64('aGVsbG8='))).toEqual(Array.from(new TextEncoder().encode('hello')))
    expect(decodeBase64('')).toHaveLength(0)
  })
})

describe('fetchArtifact', () => {
  it('assembles one eof slice, asking with the ref, session and slice budget', async () => {
    const caller = fakeServer('PNG!')
    const result = await fetchArtifact(caller, 's1', ref())
    expect(await result.blob.text()).toBe('PNG!')
    expect(result.mime).toBe('image/png')
    expect(caller.calls).toEqual([{ sessionId: 's1', artifactId: 'a1', offset: 0, limit: FETCH_SLICE_BYTES }])
  })

  it('walks the offset loop across slices and stops at eof', async () => {
    const caller = fakeServer('abcdef', { sliceBytes: 2 })
    const result = await fetchArtifact(caller, 's1', ref())
    expect(await result.blob.text()).toBe('abcdef')
    expect(result.blob.size).toBe(6)
    expect(caller.calls.map((c) => c.offset)).toEqual([0, 2, 4])
    expect(caller.calls).toHaveLength(3)
  })

  it('keeps byte offsets over multibyte payloads', async () => {
    const payload = String.fromCharCode(233, 240) + 'x'.repeat(10) // 12 latin-1 bytes
    const caller = fakeServer(payload, { mime: 'image/png' })
    const result = await fetchArtifact({ request: caller.request }, 's1', ref())
    const bytes = new Uint8Array(await result.blob.arrayBuffer())
    expect(bytes).toHaveLength(12)
    expect(bytes[0]).toBe(233)
  })

  it('prefers the offset-0 mime and falls back to the ref mime when absent', async () => {
    const first = fakeServer('x', { mime: 'video/mp4' })
    expect((await fetchArtifact(first, 's1', ref({ kind: 'video', mime: 'application/octet-stream' }))).mime).toBe('video/mp4')
    const silent = fakeServer('x', { mime: null })
    expect((await fetchArtifact(silent, 's1', ref({ mime: 'image/webp' }))).mime).toBe('image/webp')
  })

  it('refuses a non-contiguous answer instead of pasting a hole into the blob', async () => {
    const caller = scriptedCaller([{ artifactId: 'a1', offset: 5, bytes: enc('ab'), totalBytes: 7, eof: true }])
    await expect(fetchArtifact(caller, 's1', ref())).rejects.toThrow(MediaFetchError)
  })

  it('refuses a short artifact whose eof lies about the byte total', async () => {
    const caller = scriptedCaller([{ artifactId: 'a1', offset: 0, bytes: enc('abc'), totalBytes: 9, eof: true }])
    await expect(fetchArtifact(caller, 's1', ref())).rejects.toThrow(/delivered 3 of 9/)
  })

  it('refuses an empty middle slice instead of spinning forever', async () => {
    const caller = scriptedCaller([{ artifactId: 'a1', offset: 0, bytes: '', totalBytes: 9, eof: false }])
    await expect(fetchArtifact(caller, 's1', ref())).rejects.toThrow(/without eof/)
  })

  it('refuses an artifact over the slice budget', async () => {
    const request = vi.fn(async <M extends MethodName>(_m: M, params: MethodParams<M>): Promise<MethodResult<M>> => {
      const offset = Number((params as unknown as Record<string, unknown>).offset)
      // An endless server: echoes any offset, never eof.
      return { artifactId: 'a1', offset, bytes: enc('x'), totalBytes: Number.MAX_SAFE_INTEGER, eof: false } as MethodResult<M>
    })
    await expect(fetchArtifact({ request }, 's1', ref())).rejects.toThrow(/slice fetch budget/)
    expect(request).toHaveBeenCalledTimes(MAX_FETCH_SLICES)
  })

  it('stops before the next slice once the owner cancelled', async () => {
    const caller = fakeServer('abcdef', { sliceBytes: 3 })
    let stop = false
    const pending = fetchArtifact(caller, 's1', ref(), () => stop)
    stop = true
    await expect(pending).rejects.toThrow(MediaFetchCancelled)
    expect(caller.request).toHaveBeenCalledTimes(1)
  })
})

describe('objectUrls', () => {
  it('creates a blob url and revokes it without residue (happy-dom floor)', () => {
    const url = objectUrls.create(new Blob(['x'], { type: 'image/png' }))
    expect(url).toMatch(/^blob:/)
    expect(() => objectUrls.revoke(url)).not.toThrow()
  })

  it('is the seam: create and revoke are observable spies', () => {
    const create = vi.spyOn(objectUrls, 'create').mockReturnValue('blob:fake')
    const revoke = vi.spyOn(objectUrls, 'revoke').mockImplementation(() => {})
    const url = objectUrls.create(new Blob(['x']))
    objectUrls.revoke(url)
    expect(create).toHaveBeenCalledTimes(1)
    expect(revoke).toHaveBeenCalledWith('blob:fake')
    vi.restoreAllMocks()
  })
})
