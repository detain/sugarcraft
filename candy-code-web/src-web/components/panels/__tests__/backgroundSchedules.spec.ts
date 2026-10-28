import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import { useBackgroundStore } from '../../../stores/agents/background'
import { Wire } from '../../../stores/agents/__tests__/harness'
import BackgroundPanel from '../BackgroundPanel.vue'

/**
 * C-AGT-5.b: the panel's Schedules section is a thin client of the host's
 * `/schedules` command — the roster arrives as command.exec rows (the same
 * listing text the TUI reads, last-fire outcome suffix included), and the
 * per-row buttons send the command's mutating verbs and reload. No
 * web-only schedule grammar: what the command did not print, the panel
 * does not claim.
 */

const LISTING = [
  '[on] (file) nightly — every 1d, next 2026-10-28 03:00:00 @ /srv/nightly: Run the suite… — last: fired',
  '[off] (file) parked — every 6h, next 2026-10-27 18:40:00 @ /srv/nightly: Tidy the artifacts…',
  'Load notes from schedules.json:',
  '  - schedule "typo" was skipped: every must be an integer',
].join('\n')

const LIST_RESULT = { rows: [{ role: 'system', content: LISTING, uiOnly: true }], effects: [] }

let pinia: ReturnType<typeof createPinia>
let wire: Wire

beforeEach(() => {
  pinia = createPinia()
  setActivePinia(pinia)
  wire = new Wire()
})

/** Mount with the roster answered (the mount's command.exec /schedules). */
async function openPanel(scopes: string[] = ['read', 'write', 'approve', 'admin']) {
  await wire.connect(() => useBackgroundStore(), { principal: { kind: 'owner', scopes } })
  await wire.answer('bg.list', { items: [] })
  const wrapper = mount(BackgroundPanel, { props: { sessionId: 's1' }, global: { plugins: [pinia] } })
  await flushPromises()
  await wire.answer('command.exec', LIST_RESULT)
  await flushPromises()
  return wrapper
}

describe('BackgroundPanel schedules section', () => {
  it('reads the roster through command.exec /schedules and renders the listing verbatim', async () => {
    const wrapper = await openPanel()
    const sent = wire.sent('command.exec')
    expect(sent).toHaveLength(1)
    expect(sent[0]?.params).toMatchObject({ sessionId: 's1', name: 'schedules' })
    const lines = wrapper.findAll('[data-testid="bg-schedule-line"]')
    expect(lines).toHaveLength(4)
    expect(lines[0]?.text()).toContain('nightly')
    expect(lines[0]?.text()).toContain('— last: fired')
    // Note lines render for reading but are not rows: no buttons on them.
    expect(lines[2]?.find('[data-testid^="bg-schedule-toggle-"]').exists()).toBe(false)
    expect(lines[3]?.find('button').exists()).toBe(false)
  })

  it('a live row carries disable + delete; the disabled row carries enable', async () => {
    const wrapper = await openPanel()
    const lines = wrapper.findAll('[data-testid="bg-schedule-line"]')
    expect(lines[0]?.find('[data-testid="bg-schedule-toggle-nightly"]').text()).toBe('Disable')
    expect(lines[0]?.find('[data-testid="bg-schedule-delete-nightly"]').exists()).toBe(true)
    expect(lines[1]?.find('[data-testid="bg-schedule-toggle-parked"]').text()).toBe('Enable')
  })

  it('disable sends the verb with the row name, then reloads the roster', async () => {
    const wrapper = await openPanel()
    await wrapper.find('[data-testid="bg-schedule-toggle-nightly"]').trigger('click')
    await flushPromises()
    await wire.answer('command.exec', { rows: [{ role: 'system', content: 'Schedule nightly disabled.', uiOnly: true }], effects: [] })
    await flushPromises()
    const verb = wire.sent('command.exec')[1]
    expect(verb?.params).toMatchObject({ sessionId: 's1', name: 'schedules', args: 'disable nightly' })
    await wire.answer('command.exec', LIST_RESULT)
    await flushPromises()
    const reload = wire.sent('command.exec')[2]
    expect(reload?.params).toMatchObject({ sessionId: 's1', name: 'schedules' })
    expect(wrapper.findAll('[data-testid="bg-schedule-line"]')).toHaveLength(4)
  })

  it('delete sends delete <name> through the same command', async () => {
    const wrapper = await openPanel()
    await wrapper.find('[data-testid="bg-schedule-delete-nightly"]').trigger('click')
    await flushPromises()
    await wire.answer('command.exec', { rows: [{ role: 'system', content: 'Schedule nightly deleted.', uiOnly: true }], effects: [] })
    await flushPromises()
    expect(wire.sent('command.exec')[1]?.params).toMatchObject({ sessionId: 's1', name: 'schedules', args: 'delete nightly' })
  })

  it('without a session nothing is asked and the hint stands; without write scope the rows carry no buttons', async () => {
    await wire.connect(() => useBackgroundStore())
    await wire.answer('bg.list', { items: [] })
    const bare = mount(BackgroundPanel, { props: { sessionId: null }, global: { plugins: [pinia] } })
    await flushPromises()
    expect(wire.sent('command.exec')).toHaveLength(0)
    expect(bare.find('[data-testid="bg-schedules"]').text()).toContain('Open a session')

    const readOnly = await openPanel(['read'])
    expect(readOnly.findAll('[data-testid="bg-schedule-line"]')).toHaveLength(4)
    expect(readOnly.find('[data-testid="bg-schedule-toggle-nightly"]').exists()).toBe(false)
    expect(readOnly.find('[data-testid="bg-schedule-delete-nightly"]').exists()).toBe(false)
  })
})
