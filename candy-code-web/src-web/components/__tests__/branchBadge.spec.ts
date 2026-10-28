import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import { createMemoryHistory } from 'vue-router'
import StatusBar from '../StatusBar.vue'
import SessionSidebar from '../SessionSidebar.vue'
import { summary as sessionSummary, Wire } from '../grid/__tests__/harness'
import { createAppRouter } from '../../router'
import { useSessionsStore } from '../../stores/sessions'
import type { SessionSummary } from '../../protocol/generated'
import { createState } from '../../stores/reducer'

/**
 * C-WEB-4.f (GAP-GIT-01): the branch badge. The server now rides the row's
 * `git_branch` on every `SessionSummary` as `branch` (nullable), and both
 * surfaces show it — the status bar a chip, the sidebar row a `⎇ branch`
 * meta segment. Two laws the pins hold:
 *  - the badge is present ONLY when the server named a branch (a session
 *    started outside a repo, or a row with no branch, shows nothing);
 *  - it renders as plain text, and its tooltip calls it what it is — the
 *    branch AT SESSION START, never live checkout state.
 */

const infoResult = {
  version: '1.2.3',
  protocol: 1,
  root: '/repo',
  providers: ['anthropic'],
  agents: [],
  tools: [],
  commands: ['help'],
  permissionModes: ['default'],
  defaultPermissionMode: 'default',
}

async function mountStatusBar(branch: string | null | undefined): Promise<ReturnType<typeof mount>> {
  const wire = await Wire.connect()
  const wrapper = mount(StatusBar, {
    props: {
      state: createState('s1'),
      summary: sessionSummary('s1', branch === undefined ? {} : { branch }) as unknown as SessionSummary,
      contextPct: null,
      connection: 'live',
    },
  })
  await flushPromises()
  wire.answer('server.info', infoResult)
  await flushPromises()
  return wrapper
}

beforeEach(() => {
  setActivePinia(createPinia())
})

describe('StatusBar branch chip (C-WEB-4.f)', { retry: 0 }, () => {
  it('shows the branch as plain text with a session-start tooltip', async () => {
    const wrapper = await mountStatusBar('release/1.0')
    const chip = wrapper.find('[data-testid="branch-chip"]')
    expect(chip.exists()).toBe(true)
    expect(chip.text()).toBe('⎇ release/1.0')
    expect(chip.attributes('title')).toBe('Branch at session start: release/1.0')
    expect(chip.element.children).toHaveLength(0)
  })

  it('stays silent when the server sent an explicit null branch', async () => {
    const wrapper = await mountStatusBar(null)
    expect(wrapper.find('[data-testid="branch-chip"]').exists()).toBe(false)
  })

  it('stays silent when the summary carries no branch at all', async () => {
    const wrapper = await mountStatusBar(undefined)
    expect(wrapper.find('[data-testid="branch-chip"]').exists()).toBe(false)
  })
})

describe('SessionSidebar branch meta (C-WEB-4.f)', { retry: 0 }, () => {
  async function mountSidebar(rows: Array<Record<string, unknown>>) {
    await Wire.connect()
    const sessions = useSessionsStore()
    sessions.byId = Object.fromEntries(
      rows.map((row, index) => [
        String(row.id),
        {
          id: `pad${index}`, name: 'pad', status: 'idle', open: true, updatedAt: '', createdAt: '',
          permissionMode: 'default', root: '/repo', contextTokens: 0, contextWindow: 0,
          ...row,
        },
      ]),
    )
    const router = createAppRouter(createMemoryHistory())
    await router.push({ name: 'session', params: { id: String(rows[0]?.id ?? 'x') } })
    await router.isReady()
    return mount(SessionSidebar, {
      props: { activeId: String(rows[0]?.id ?? null) },
      global: { plugins: [router] },
    })
  }

  it('carries ⎇ branch on the row whose session started there, as plain text', async () => {
    const wrapper = await mountSidebar([
      { id: 's1', name: 'session s1', status: 'idle', open: true, updatedAt: '2026-10-17 00:00:01', branch: 'release/1.0' },
      { id: 's2', name: 'session s2', status: 'idle', open: true, updatedAt: '2026-10-17 00:00:02', branch: null },
    ])
    const rows = wrapper.findAll('li.row')
    expect(rows).toHaveLength(2)
    // Address by session id, not list position: the sidebar sorts newest-first.
    const anchored = (id: string): Element | null =>
      wrapper.find(`[data-session-id="${id}"]`).element.closest('li')
    const metaEl = anchored('s1')?.querySelector('[data-testid="session-branch"]')
    expect(metaEl).not.toBeNull()
    expect(metaEl?.textContent).toBe('⎇ release/1.0')
    expect(metaEl?.getAttribute('title')).toBe('Branch at session start: release/1.0')
    expect(metaEl?.children).toHaveLength(0)
    expect(anchored('s2')?.querySelector('[data-testid="session-branch"]')).toBeNull()
    expect(wrapper.findAll('[data-testid="session-branch"]')).toHaveLength(1)
  })

  it('omits the segment for a summary that never carried the key', async () => {
    const wrapper = await mountSidebar([
      { id: 's1', name: 'session s1', status: 'idle', open: true, updatedAt: '2026-10-17 00:00:01' },
    ])
    expect(wrapper.find('[data-testid="session-branch"]').exists()).toBe(false)
  })
})
