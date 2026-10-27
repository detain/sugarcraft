import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import { Wire } from '../../../stores/agents/__tests__/harness'
import FilesPanel from '../FilesPanel.vue'
import SidePanels from '../SidePanels.vue'

/**
 * C-WEB-3.b: the Files panel — a search box over `fs.search` (through the
 * session store's `searchFiles`), an empty query listing the front of the
 * project, and rows that open the shared read-only FileViewer on
 * `files.read`. The viewer renders the content as plain text with line
 * numbers (never markup), shows a size notice for `binary: true`, and
 * offers "load more" by byte offset when `truncated`.
 */

let wire: Wire

const SEARCH = (matches: string[]) => ({ root: '/project', matches, truncated: false })

/** A complete (untruncated) files.read answer for $path. */
const READ_FILE = (path: string, content: string) => ({ path, size: content.length, offset: 0, binary: false, content, truncated: false })

async function openPanel(): Promise<ReturnType<typeof mount>> {
  const wrapper = mount(FilesPanel, { props: { sessionId: 's1' } })
  await flushPromises()
  return wrapper
}

/** Answer the mount search and settle. */
async function front(wrapper: ReturnType<typeof mount>, matches: string[]): Promise<void> {
  await wire.answer('fs.search', SEARCH(matches))
  await flushPromises()
  expect(wrapper.findAll('[data-testid^="files-row-"]')).toHaveLength(matches.length)
}

beforeEach(() => {
  setActivePinia(createPinia())
  wire = new Wire()
  try {
    globalThis.localStorage?.clear()
  } catch {
    // no storage
  }
})

describe('FilesPanel (C-WEB-3.b)', () => {
  it('lists the front of the project on mount — an empty query still searches', async () => {
    await wire.connect()
    const wrapper = await openPanel()
    expect(wire.sent('fs.search').at(-1)?.params).toEqual({ limit: 50 })
    await front(wrapper, ['src/a.ts', 'README.md'])
    expect(wrapper.find('[data-testid="files-row-src/a.ts"]').text()).toBe('src/a.ts')
    expect(wrapper.find('[data-testid="files-row-README.md"]').text()).toBe('README.md')
  })

  it('re-searches as the query is typed, sending the partial', async () => {
    await wire.connect()
    const wrapper = await openPanel()
    await front(wrapper, ['src/a.ts', 'README.md'])
    await wrapper.find('[data-testid="files-search"]').setValue('sr')
    await flushPromises()
    expect(wire.sent('fs.search').at(-1)?.params).toEqual({ query: 'sr', limit: 50 })
    await wire.answer('fs.search', SEARCH(['src/a.ts']))
    await flushPromises()
    expect(wrapper.findAll('[data-testid^="files-row-"]')).toHaveLength(1)
  })

  it('drops a stale answer while a newer search is pending', async () => {
    await wire.connect()
    const wrapper = await openPanel()
    await wrapper.find('[data-testid="files-search"]').setValue('sr')
    await flushPromises()
    expect(wire.sent('fs.search')).toHaveLength(2)
    // Answer the newest (mount) search, then let the keystroke's stale
    // response arrive late: the seq guard must keep it off the list.
    await wire.answer('fs.search', SEARCH(['src/a.ts']))
    expect(wrapper.findAll('[data-testid^="files-row-"]')).toHaveLength(1)
    wire.socket().answer(SEARCH(['stale.txt']), wire.sent('fs.search')[0]!.id)
    await flushPromises()
    expect(wrapper.findAll('[data-testid^="files-row-"]')).toHaveLength(1)
    expect(wrapper.find('[data-testid="files-row-stale.txt"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="files-row-src/a.ts"]').exists()).toBe(true)
  })

  it('says so when a search matches nothing, and when the search itself is refused', async () => {
    await wire.connect()
    const wrapper = await openPanel()
    await front(wrapper, [])
    expect(wrapper.find('[data-testid="files-none"]').exists()).toBe(true)

    await wrapper.find('[data-testid="files-search"]').setValue('x')
    await flushPromises()
    await wire.fail('fs.search', -32002, 'no_root', 'this server serves no project root')
    expect(wrapper.find('[data-testid="files-error"]').text()).toContain('serves no project root')
    expect(wrapper.find('[data-testid="files-none"]').exists()).toBe(false)
  })

  it('opens the shared viewer on files.read when a row is clicked', async () => {
    await wire.connect()
    const wrapper = await openPanel()
    await front(wrapper, ['src/a.ts'])
    await wrapper.find('[data-testid="files-row-src/a.ts"]').trigger('click')
    expect(wrapper.find('[data-testid="file-viewer"]').exists()).toBe(true)
    expect(wire.sent('files.read').at(-1)?.params).toEqual({ path: 'src/a.ts', offset: 0, limit: 32_768 })
    await wire.answer('files.read', { path: 'src/a.ts', size: 12, offset: 0, binary: false, content: 'one\ntwo\nx', truncated: false })
    expect(wrapper.find('[data-testid="file-viewer-path"]').text()).toBe('src/a.ts')
    expect(wrapper.find('[data-testid="file-viewer-line-1"]').text()).toBe('1one')
    expect(wrapper.find('[data-testid="file-viewer-line-2"]').text()).toBe('2two')
    expect(wrapper.find('[data-testid="file-viewer-line-3"]').text()).toBe('3x')
    expect(wrapper.find('[data-testid="file-viewer-load-more"]').exists()).toBe(false)

    await wrapper.find('[data-testid="file-viewer-close"]').trigger('click')
    expect(wrapper.find('[data-testid="file-viewer"]').exists()).toBe(false)
  })

  it('renders HTML in the content as text, never as markup', async () => {
    await wire.connect()
    const wrapper = await openPanel()
    await front(wrapper, ['evil.txt'])
    await wrapper.find('[data-testid="files-row-evil.txt"]').trigger('click')
    await wire.answer('files.read', {
      path: 'evil.txt',
      size: 40,
      offset: 0,
      binary: false,
      content: '<b>bold</b> <script>alert(1)</script>',
      truncated: false,
    })
    const lines = wrapper.find('[data-testid="file-viewer-lines"]')
    expect(lines.text()).toContain('<b>bold</b>')
    expect(lines.find('b').exists()).toBe(false)
    expect(lines.find('script').exists()).toBe(false)
  })

  it('answers a binary file with a size notice, not content, and no load-more', async () => {
    await wire.connect()
    const wrapper = await openPanel()
    await front(wrapper, ['bin/blob'])
    await wrapper.find('[data-testid="files-row-bin/blob"]').trigger('click')
    await wire.answer('files.read', { path: 'bin/blob', size: 4_096, offset: 0, binary: true, content: '', truncated: true })
    expect(wrapper.find('[data-testid="file-viewer-binary"]').text()).toContain('binary file (4096 bytes)')
    expect(wrapper.find('[data-testid="file-viewer-load-more"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="file-viewer-lines"]').exists()).toBe(false)
  })

  it('drops an earlier read when the path switches mid-read (shared instance)', async () => {
    await wire.connect()
    const wrapper = await openPanel()
    await front(wrapper, ['src/a.ts', 'src/b.ts'])
    await wrapper.find('[data-testid="files-row-src/a.ts"]').trigger('click')
    // FilesPanel reuses the viewer instance and only changes :path; a.ts's
    // read is still unanswered when b.ts's read goes out.
    await wrapper.find('[data-testid="files-row-src/b.ts"]').trigger('click')
    await flushPromises()
    expect(wire.sent('files.read')).toHaveLength(2)
    wire.socket().answer(READ_FILE('src/a.ts', 'A-CONTENT'), wire.sent('files.read')[0]!.id)
    await flushPromises()
    expect(wrapper.find('[data-testid="file-viewer-path"]').text()).toBe('src/b.ts')
    expect(wrapper.text()).not.toContain('A-CONTENT')
    await wire.answer('files.read', READ_FILE('src/b.ts', 'B-CONTENT'))
    expect(wrapper.find('[data-testid="file-viewer-line-1"]').text()).toBe('1B-CONTENT')
  })

  it('loads more by byte offset and re-splits the whole text so line numbers stay true', async () => {
    await wire.connect()
    const wrapper = await openPanel()
    await front(wrapper, ['long.txt'])
    await wrapper.find('[data-testid="files-row-long.txt"]').trigger('click')
    // truncated ⇒ the server consumed the WHOLE raw limit (FilesMethods.php:97,109);
    // the sanitized content string here is shorter than that — invalid UTF-8
    // bytes collapsed — which is exactly why the advance must be the limit,
    // not the content's length.
    await wire.answer('files.read', { path: 'long.txt', size: 40_000, offset: 0, binary: false, content: 'line1\nli', truncated: true })
    expect(wrapper.find('[data-testid="file-viewer-load-more"]').exists()).toBe(true)
    expect(wrapper.get('.hint').text()).toBe('showing 32768 of 40000 bytes')

    await wrapper.find('[data-testid="file-viewer-load-more"]').trigger('click')
    await flushPromises()
    expect(wire.sent('files.read').at(-1)?.params).toEqual({ path: 'long.txt', offset: 32_768, limit: 32_768 })
    // The server echoes the requested offset (FilesMethods.php:106).
    await wire.answer('files.read', { path: 'long.txt', size: 40_000, offset: 32_768, binary: false, content: 'ne2', truncated: false })
    expect(wrapper.findAll('[data-testid^="file-viewer-line-"]')).toHaveLength(2)
    expect(wrapper.find('[data-testid="file-viewer-line-2"]').text()).toBe('2line2')
  })

  it('names a refused read inside the viewer', async () => {
    await wire.connect()
    const wrapper = await openPanel()
    await front(wrapper, ['gone.txt'])
    await wrapper.find('[data-testid="files-row-gone.txt"]').trigger('click')
    await wire.fail('files.read', -32004, 'file_not_found', 'no readable file at that path under the project root')
    expect(wrapper.find('[data-testid="file-viewer-error"]').text()).toContain('no readable file')
  })

  it('mounts in the drawer under a Files tab', async () => {
    await wire.connect()
    const wrapper = mount(SidePanels, { props: { sessionId: 's1' } })
    await wrapper.find('[data-testid="panels-toggle"]').trigger('click')
    await wrapper.find('[data-testid="panel-tab-files"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-testid="files-panel"]').exists()).toBe(true)
    expect(wire.sent('fs.search').at(-1)?.params).toEqual({ limit: 50 })
    await wire.answer('fs.search', SEARCH(['src/a.ts']))
    await flushPromises()
    expect(wrapper.find('[data-testid="files-row-src/a.ts"]').exists()).toBe(true)
  })

  it('does not mount the Files panel — and never searches — without a session', async () => {
    await wire.connect()
    const wrapper = mount(SidePanels, { props: { sessionId: null } })
    await wrapper.find('[data-testid="panels-toggle"]').trigger('click')
    await wrapper.find('[data-testid="panel-tab-files"]').trigger('click')
    await flushPromises()
    // The `active === 'files' && sessionId` gate (SidePanels.vue): no
    // session, no panel, and so no fs.search on the wire either.
    expect(wrapper.find('[data-testid="files-panel"]').exists()).toBe(false)
    expect(wire.sent('fs.search')).toHaveLength(0)
  })
})
