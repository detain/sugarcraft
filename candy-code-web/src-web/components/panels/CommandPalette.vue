<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { t } from '../../lib/i18n'
import { useDialog } from '../../lib/useDialog'
import { useRouter } from 'vue-router'
import { oneLine } from '../../lib/format'
import { bindingFor, captureActive, matchesBinding } from '../../lib/keymap'
import { PANEL_LABELS, PANELS, usePanelsStore } from '../../stores/agents/panels'
import { useLayoutStore } from '../../stores/layout'
import { describe, useSessionStore } from '../../stores/session'
import { useNewSessionStore } from '../../stores/newSession'
import { useSessionsStore } from '../../stores/sessions'
import { useSettingsStore } from '../../stores/settings'
import { clearPromptHistory } from '../../stores/promptHistory'

/**
 * The command palette (rebindable, default Ctrl/⌘K — see `lib/keymap`;
 * roadmap O-6c, Appendix O §7.4, the TUI's
 * `PaletteAction` categories): jump to a session, run a slash command in the
 * session on screen — one that takes no argument runs at once, one that does
 * is put in the composer — open a panel, start a session, change the theme.
 * Typing narrows the list (every word must appear); arrows move, Enter picks,
 * Escape closes.
 */
const props = defineProps<{ activeId: string | null }>()

interface Entry {
  id: string
  /** Chrome group label — rendered, filtered, and translated with the entry. */
  group: string
  label: string
  hint?: string
  run: () => unknown
}

const panels = usePanelsStore()
const sessions = useSessionsStore()
const layout = useLayoutStore()
const settings = useSettingsStore()
const newSessionFlow = useNewSessionStore()
const router = useRouter()
const query = ref('')
const selected = ref(0)
const error = ref<string | null>(null)
const input = ref<HTMLInputElement | null>(null)
const paletteEl = ref<HTMLElement | null>(null)
// CW-UX-07: the palette keeps its own Escape (the input's handler) and its
// open-focus on the filter field; the spine adds the Tab trap that stops the
// keyboard walking into the page behind a modal dialog, and the restore on
// close.
const dialog = useDialog({
  panel: paletteEl,
  open: () => panels.paletteOpen,
  handleEscape: false,
  focusOn: () => input.value ?? undefined,
})

const entries = computed<Entry[]>(() => {
  const list: Entry[] = []
  const actionsGroup = t('web.panels.palette_group_actions')
  list.push({ id: 'new-session', group: actionsGroup, label: newSessionFlow.canBrowse ? t('web.panels.palette_new_browse') : t('web.panels.palette_new'), hint: newSessionFlow.canBrowse ? t('web.panels.palette_new_hint') : undefined, run: newSession })
  list.push({ id: 'theme', group: actionsGroup, label: t('web.panels.palette_theme', { theme: settings.theme }), run: () => settings.cycleTheme() })
  list.push({ id: 'clear-history', group: actionsGroup, label: t('web.panels.palette_clear'), hint: t('web.panels.palette_clear_hint'), run: clearHistory })
  list.push({ id: 'panels', group: actionsGroup, label: panels.open ? t('web.panels.palette_panels_close') : t('web.panels.palette_panels_open'), run: () => panels.toggle() })
  for (const panel of PANELS) {
    list.push({ id: `panel-${panel}`, group: t('web.panels.palette_group_panels'), label: t('web.panels.palette_panel_entry', { label: PANEL_LABELS[panel] }), run: () => panels.show(panel) })
  }
  if (props.activeId !== null) {
    const session = useSessionStore(props.activeId)
    for (const command of session.commands) {
      if (command.runsIn !== 'server' && command.source !== 'file') continue
      const name = command.name.replace(/^\//, '')
      list.push({
        id: `cmd-${name}`,
        group: t('web.panels.palette_group_commands'),
        label: `/${name}`,
        hint: [command.argumentHint, command.description].filter((part) => part).join(' — '),
        run: () => runCommand(name, command.argumentHint ?? null),
      })
    }
  }
  for (const summary of sessions.items) {
    if (summary.id === props.activeId) continue
    list.push({
      id: `session-${summary.id}`,
      group: t('web.panels.palette_group_sessions'),
      label: summary.name || (summary.preview ? oneLine(summary.preview, 60) : summary.id),
      hint: summary.status === 'closed' ? '' : summary.status.replace('_', ' '),
      run: () => router.push({ name: 'session', params: { id: summary.id } }),
    })
  }
  return list
})

const shown = computed(() => {
  const words = query.value.toLowerCase().split(/\s+/).filter((word) => word !== '')
  const matches = words.length === 0
    ? entries.value
    : entries.value.filter((entry) => {
        const haystack = `${entry.group} ${entry.label} ${entry.hint ?? ''}`.toLowerCase()
        return words.every((word) => haystack.includes(word))
      })
  return matches.slice(0, 50)
})

watch(query, () => {
  selected.value = 0
})

watch(() => panels.paletteOpen, async (open) => {
  if (!open) return
  query.value = ''
  error.value = null
  selected.value = 0
  await nextTick()
  input.value?.focus()
})

async function newSession(): Promise<void> {
  // The picker is a dialog of its own: the palette gets out of its way.
  panels.togglePalette(false)
  const summary = await newSessionFlow.start()
  if (summary) await router.push({ name: 'session', params: { id: summary.id } })
}

/**
 * WEB-6-P2: the README advertised a prompt-history clear "any time without
 * signing out" while the only caller was `logout()`. This is the UI: the
 * confirm is deliberate — the ring is the only local record of what was typed
 * in this browser, and the clear reaches every open tab.
 */
function clearHistory(): void {
  if (window.confirm(t('web.panels.palette_clear_confirm'))) clearPromptHistory()
}

async function runCommand(name: string, argumentHint: string | null): Promise<void> {
  if (props.activeId === null) return
  if (argumentHint) {
    // It needs an argument: hand it to the composer to finish.
    layout.setDraft(props.activeId, `/${name} `)
    return
  }
  await useSessionStore(props.activeId).send(`/${name}`)
}

async function pick(entry: Entry | undefined): Promise<void> {
  if (!entry) return
  error.value = null
  try {
    await entry.run()
    panels.togglePalette(false)
  } catch (failure) {
    error.value = describe(failure)
  }
}

function onInputKey(event: KeyboardEvent): void {
  const n = shown.value.length
  if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
    event.preventDefault()
    if (n > 0) selected.value = (selected.value + (event.key === 'ArrowDown' ? 1 : n - 1)) % n
  } else if (event.key === 'Enter') {
    event.preventDefault()
    void pick(shown.value[selected.value])
  } else if (event.key === 'Escape') {
    event.preventDefault()
    panels.togglePalette(false)
  }
}

function onGlobalKey(event: KeyboardEvent): void {
  if (captureActive()) return
  if (matchesBinding(event, bindingFor('palette'))) {
    event.preventDefault()
    panels.togglePalette()
  }
}

onMounted(() => window.addEventListener('keydown', onGlobalKey))
onBeforeUnmount(() => window.removeEventListener('keydown', onGlobalKey))
</script>

<template>
  <div v-if="panels.paletteOpen" class="backdrop" data-testid="palette-backdrop" @click.self="panels.togglePalette(false)">
    <div ref="paletteEl" class="palette" role="dialog" aria-modal="true" :aria-label="t('web.panels.palette_aria')" data-testid="command-palette" @keydown="dialog.onKeydown">
      <input
        ref="input"
        v-model="query"
        type="text"
        :placeholder="t('web.panels.palette_ph')"
        :aria-label="t('web.panels.palette_search_aria')"
        role="combobox"
        aria-controls="palette-list"
        :aria-expanded="true"
        :aria-activedescendant="shown[selected] ? `palette-${shown[selected]!.id}` : undefined"
        data-testid="palette-input"
        @keydown="onInputKey"
      >
      <p v-if="error" class="error" role="alert">{{ error }}</p>
      <ul id="palette-list" role="listbox">
        <li
          v-for="(entry, i) in shown"
          :id="`palette-${entry.id}`"
          :key="entry.id"
          role="option"
          :aria-selected="i === selected"
          :class="{ on: i === selected }"
          data-testid="palette-entry"
          :data-entry="entry.id"
          @mousemove="selected = i"
          @mousedown.prevent="pick(entry)"
        >
          <span class="group">{{ entry.group }}</span>
          <span class="label">{{ entry.label }}</span>
          <span v-if="entry.hint" class="hint">{{ entry.hint }}</span>
        </li>
        <li v-if="shown.length === 0" class="none">{{ t('web.panels.palette_empty') }}</li>
      </ul>
    </div>
  </div>
</template>

<style scoped>
.backdrop {
  position: fixed;
  inset: 0;
  z-index: var(--z-dialog);
  background: rgb(0 0 0 / 0.35);
  display: flex;
  justify-content: center;
  align-items: flex-start;
  padding: 12vh 1rem 1rem;
}
.palette {
  width: min(36rem, 100%);
  max-height: min(70vh, calc(100dvh - 2rem));
  display: flex;
  flex-direction: column;
  background: var(--panel);
  border: 1px solid var(--line);
  border-radius: 8px;
  box-shadow: 0 0.8rem 2rem rgb(0 0 0 / 0.4);
  overflow: hidden;
}
input {
  font: inherit;
  padding: 0.6rem 0.8rem;
  color: var(--text);
  background: var(--bg);
  border: 0;
  border-bottom: 1px solid var(--line);
  outline: none;
}
/* MA-35: this block deleted the focus ring and nothing replaced it — the global
   tokens.css rule cannot win here (same specificity, injected earlier). */
input:focus-visible {
  outline: 2px solid var(--accent);
  outline-offset: -2px;
}
ul {
  list-style: none;
  margin: 0;
  padding: 0.3rem;
  overflow-y: auto;
}
li {
  display: grid;
  grid-template-columns: 5.5rem auto 1fr;
  gap: 0.6rem;
  align-items: baseline;
  padding: 0.35rem 0.5rem;
  border-radius: 6px;
  cursor: pointer;
  min-width: 0;
}
li.on {
  background: var(--bg);
  outline: 1px solid var(--accent);
}
.group {
  font-size: 0.75rem;
  text-transform: uppercase;
  color: var(--muted);
}
.label {
  white-space: nowrap;
}
.hint {
  color: var(--muted);
  font-size: 0.8125rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  min-width: 0;
}
.none {
  display: block;
  color: var(--muted);
  cursor: default;
}
.error {
  margin: 0.4rem 0.8rem 0;
  color: var(--error);
  font-size: 0.875rem;
}
@media (max-width: 760px) {
  /* UX-12: medium */
  li {
    grid-template-columns: auto 1fr;
  }
  .group {
    display: none;
  }
}
/* CW-UX-08: on short viewports the vh gutters eat the screen - collapse them
   to a constant and tighten the cap so the dialog's own scroll stays visible.
   UX-12: short — height axis of the shared scale (App.vue header). */
@media (max-height: 480px) {
  .backdrop {
    padding: 1rem;
  }
  .palette {
    max-height: calc(100dvh - 1rem);
  }
}
</style>
