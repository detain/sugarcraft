import { describe, expect, it } from 'vitest'
import { bytesToBase64, MAX_PASTED_IMAGES, readPastedImages, type ClipboardItem } from '../paste'

function item(type: string, bytes: number[], name = ''): ClipboardItem {
  return {
    kind: 'file',
    type,
    getAsFile: () => (type === '' ? null : new File([Uint8Array.from(bytes)], name, { type })),
  }
}

const PNG = [0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]
const GIF = [0x47, 0x49, 0x46, 0x38, 0x39, 0x61]

describe('readPastedImages', () => {
  it('converts raster files in clipboard order to strict base64', async () => {
    const images = await readPastedImages({
      items: [item('image/png', PNG, 'shot.png'), item('image/gif', GIF, 'anim.gif')],
    })
    expect(images).toHaveLength(2)
    expect(images[0]).toEqual({ name: 'shot.png', mime: 'image/png', data: bytesToBase64(Uint8Array.from(PNG)) })
    expect(images[1]?.mime).toBe('image/gif')
    expect(images[1]?.data).not.toContain('\n')
  })

  it('drops everything the server would refuse: strings, text, and unreadable entries', async () => {
    const images = await readPastedImages({
      items: [{ kind: 'string', type: 'text/plain', getAsFile: () => null }, item('image/svg+xml', PNG), item('image/png', PNG), { kind: 'file', type: 'image/png', getAsFile: () => null }],
    })
    expect(images).toHaveLength(1)
  })

  it('names an anonymous paste by its sniffed subtype, as the server fallback does', async () => {
    const images = await readPastedImages({ items: [item('image/png', PNG)] })
    expect(images[0]?.name).toBe('pasted-image.png')
  })

  it('caps at MAX_PASTED_IMAGES — a fifth paste would only be refused', async () => {
    const six = Array.from({ length: 6 }, () => item('image/png', PNG))
    const images = await readPastedImages({ items: six })
    expect(images).toHaveLength(MAX_PASTED_IMAGES)
  })

  it('answers an empty clipboard with no images', async () => {
    expect(await readPastedImages(null)).toEqual([])
    expect(await readPastedImages({ items: [] })).toEqual([])
  })
})
