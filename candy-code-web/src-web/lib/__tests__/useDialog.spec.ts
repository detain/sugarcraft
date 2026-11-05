import { mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { defineComponent, h, nextTick, ref, type Ref } from 'vue'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useDialog } from '../useDialog'

/**
 * CW-UX-07: the dialog spine. Focus-on-open, Tab trap, Escape, opener
 * restore, and the inline-modal pointerdown dismissal are pinned here against
 * a render-function host (no runtime template compiler in this setup). The
 * wiring of the nine shipped dialogs is pinned by the source-shape scan at
 * the bottom — one spine, consumed everywhere.
 */

interface HostOptions {
  open: Ref<boolean>
  onClose?: () => void
  handleEscape?: boolean
  outsidePointerClose?: boolean
  focusOn?: () => HTMLElement | null | undefined
  withControls?: boolean
}

function hostComponent(options: HostOptions) {
  return defineComponent({
    setup() {
      const panel = ref<HTMLElement | null>(null)
      const dialog = useDialog({
        panel,
        open: () => options.open.value,
        onClose: options.onClose,
        handleEscape: options.handleEscape,
        outsidePointerClose: options.outsidePointerClose,
        focusOn: options.focusOn,
      })
      return () =>
        options.open.value
          ? h(
              'div',
              {
                ref: panel,
                role: 'dialog',
                tabindex: -1,
                'data-testid': 'host-dialog',
                onKeydown: dialog.onKeydown,
              },
              options.withControls === false
                ? []
                : [h('input', { id: 'host-first' }), h('button', { id: 'host-last', type: 'button' }, 'close')],
            )
          : null
    },
  })
}

function pressKey(el: Element, key: string, shift = false): KeyboardEvent {
  const event = new KeyboardEvent('keydown', { key, shiftKey: shift, bubbles: true, cancelable: true })
  el.dispatchEvent(event)
  return event
}

let external: HTMLButtonElement

beforeEach(() => {
  document.body.innerHTML = ''
  external = document.createElement('button')
  external.id = 'external-opener'
  document.body.appendChild(external)
})

describe('useDialog focus lifecycle (CW-UX-07)', () => {
  it('takes focus inside on open and restores the opener on close', async () => {
    const open = ref(false)
    mount(hostComponent({ open, handleEscape: false }), { attachTo: document.body })
    external.focus()
    open.value = true
    await nextTick()
    await nextTick()
    expect(document.activeElement?.id).toBe('host-first')
    open.value = false
    await nextTick()
    expect(document.activeElement).toBe(external)
  })

  it('a dialog mounted open captures its opener and restores on unmount', async () => {
    const open = ref(true)
    external.focus()
    const wrapper = mount(hostComponent({ open, handleEscape: false }), { attachTo: document.body })
    await nextTick()
    await nextTick()
    expect(document.activeElement?.id).toBe('host-first')
    wrapper.unmount()
    expect(document.activeElement).toBe(external)
  })

  it('focusOn wins over the first tabbable', async () => {
    const open = ref(false)
    mount(
      hostComponent({ open, focusOn: () => document.getElementById('host-last') as HTMLElement | null, handleEscape: false }),
      { attachTo: document.body },
    )
    open.value = true
    await nextTick()
    await nextTick()
    expect(document.activeElement?.id).toBe('host-last')
  })

  it('an empty dialog keeps the Tab inside the panel', async () => {
    const open = ref(true)
    const wrapper = mount(hostComponent({ open, withControls: false, handleEscape: false }), { attachTo: document.body })
    await nextTick()
    const panel = wrapper.find<HTMLElement>('[data-testid="host-dialog"]').element
    panel.focus()
    const event = pressKey(panel, 'Tab')
    expect(event.defaultPrevented).toBe(true)
    expect(document.activeElement).toBe(panel)
  })
})

describe('useDialog focus trap (CW-UX-07)', () => {
  it('Tab on the last control wraps to the first', async () => {
    const open = ref(true)
    const wrapper = mount(hostComponent({ open, handleEscape: false }), { attachTo: document.body })
    await nextTick()
    await nextTick()
    const panel = wrapper.find('[data-testid="host-dialog"]').element
    const last = wrapper.find<HTMLElement>('#host-last').element
    last.focus()
    const event = pressKey(panel, 'Tab')
    expect(event.defaultPrevented).toBe(true)
    expect(document.activeElement).toBe(wrapper.find('#host-first').element)
  })

  it('Shift+Tab on the first control wraps to the last', async () => {
    const open = ref(true)
    const wrapper = mount(hostComponent({ open, handleEscape: false }), { attachTo: document.body })
    await nextTick()
    await nextTick()
    const panel = wrapper.find('[data-testid="host-dialog"]').element
    const first = wrapper.find<HTMLElement>('#host-first').element
    first.focus()
    const event = pressKey(panel, 'Tab', true)
    expect(event.defaultPrevented).toBe(true)
    expect(document.activeElement).toBe(wrapper.find('#host-last').element)
  })

  it('Tab from outside the dialog is pulled back in', async () => {
    const open = ref(true)
    const wrapper = mount(hostComponent({ open, handleEscape: false }), { attachTo: document.body })
    await nextTick()
    await nextTick()
    external.focus()
    const panel = wrapper.find('[data-testid="host-dialog"]').element
    pressKey(panel, 'Tab')
    expect(document.activeElement).toBe(wrapper.find('#host-first').element)
  })
})

describe('useDialog Escape and dismissal (CW-UX-07)', () => {
  it('Escape closes by default', async () => {
    const onClose = vi.fn()
    const open = ref(true)
    const wrapper = mount(hostComponent({ open, onClose }), { attachTo: document.body })
    await nextTick()
    const event = pressKey(wrapper.find('[data-testid="host-dialog"]').element, 'Escape')
    expect(event.defaultPrevented).toBe(true)
    expect(onClose).toHaveBeenCalledTimes(1)
  })

  it('Escape is untouched when handleEscape is false', async () => {
    const onClose = vi.fn()
    const open = ref(true)
    const wrapper = mount(hostComponent({ open, onClose, handleEscape: false }), { attachTo: document.body })
    await nextTick()
    const event = pressKey(wrapper.find('[data-testid="host-dialog"]').element, 'Escape')
    expect(event.defaultPrevented).toBe(false)
    expect(onClose).not.toHaveBeenCalled()
  })

  it('handleEscape without onClose refuses to mount', () => {
    const open = ref(true)
    expect(() => mount(hostComponent({ open, handleEscape: true }), { attachTo: document.body })).toThrow(/onClose/)
  })

  it('a pointerdown outside closes only when the option is on', async () => {
    const armed = vi.fn()
    const passive = vi.fn()
    mount(hostComponent({ open: ref(true), onClose: armed, outsidePointerClose: true }), { attachTo: document.body })
    mount(hostComponent({ open: ref(true), onClose: passive }), { attachTo: document.body })
    await nextTick()
    document.body.dispatchEvent(new Event('pointerdown', { bubbles: true }))
    expect(armed).toHaveBeenCalledTimes(1)
    expect(passive).not.toHaveBeenCalled()
  })

  it('a pointerdown inside an armed dialog never fires the dismissal', async () => {
    const onClose = vi.fn()
    const wrapper = mount(hostComponent({ open: ref(true), onClose, outsidePointerClose: true }), { attachTo: document.body })
    await nextTick()
    wrapper.find('[data-testid="host-dialog"]').element.dispatchEvent(new Event('pointerdown', { bubbles: true }))
    expect(onClose).not.toHaveBeenCalled()
  })
})

describe('every shipped dialog consumes the spine (CW-UX-07)', () => {
  const DIALOGS = [
    'src-web/components/SessionSidebar.vue',
    'src-web/components/panels/ContextPanel.vue',
    'src-web/components/approvals/ApprovalsDrawer.vue',
    'src-web/components/AboutModal.vue',
    'src-web/components/HelpOverlay.vue',
    'src-web/components/HistorySearch.vue',
    'src-web/components/DirectoryPicker.vue',
    'src-web/components/MediaCard.vue',
    'src-web/components/panels/CommandPalette.vue',
    'src-web/components/tokens/TokenPanel.vue',
  ]

  /** The open tag carrying role="dialog", attributes spread or not. */
  function dialogTag(source: string): string {
    const at = source.indexOf('role="dialog"')
    expect(at, 'the role="dialog" root').toBeGreaterThan(-1)
    const start = source.lastIndexOf('<', at)
    const end = source.indexOf('>', at)
    return source.slice(start, end)
  }

  for (const file of DIALOGS) {
    it(`${file}: modal, labelled spine handler, wired import`, () => {
      const source = readFileSync(resolve(process.cwd(), file), 'utf8')
      const tag = dialogTag(source)
      expect(tag, `${file} dialog root`).toContain('aria-modal="true"')
      expect(tag, `${file} dialog root answers the spine keydown`).toContain('@keydown="dialog.onKeydown"')
      expect(source, `${file} imports the spine`).toMatch(/from '[^']*lib\/useDialog'/)
    })
  }
})
