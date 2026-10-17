<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { oneLine } from '../../lib/format'
import { PANEL_LABELS, PANELS, usePanelsStore } from '../../stores/agents/panels'
import { useLayoutStore } from '../../stores/layout'
import { describe, useSessionStore } from '../../stores/session'
import { useNewSessionStore } from '../../stores/newSession'
import { useSessionsStore } from '../../stores/sessions'
import { useSettingsStore } from '../../stores/settings'

/**
 * The command palette (Ctrl+K / ⌘K; roadmap O-6c, Appendix O §7.4, the TUI's
 * `PaletteAction` categories): jump to a session, run a slash command in the
 * session on screen — one that takes no argument runs at once, one that does
 * is put in the composer — open a panel, start a session, change the theme.
 * Typing narrows the list (every word must appear); arrows move, Enter picks,
 * Escape closes.
 */
const props = defineProps<{ activeId: string | null }>()

interface Entry {
  id: string
  group: 'Sessions' | 'Commands' | 'Panels' | 'Actions'
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

const entries = computed<Entry[]>(() => {
  const list: Entry[] = []
  list.push({ id: 'new-session', group: 'Actions', label: newSessionFlow.canBrowse ? 'New session…' : 'New session', hint: newSessionFlow.canBrowse ? 'choose its directory' : undefined, run: newSession })
  list.push({ id: 'theme', group: 'Actions', label: `Theme: ${settings.theme} → next`, run: () => settings.cycleTheme() })
  list.push({ id: 'panels', group: 'Actions', label: panels.open ? 'Close the panels' : 'Open the panels', run: () => panels.toggle() })
  for (const panel of PANELS) {
    list.push({ id: `panel-${panel}`, group: 'Panels', label: `${PANEL_LABELS[panel]} panel`, run: () => panels.show(panel) })
  }
  if (props.activeId !== null) {
    const session = useSessionStore(props.activeId)
    for (const command of session.commands) {
      if (command.runsIn !== 'server' && command.source !== 'file') continue
      const name = command.name.replace(/^\//, '')
      list.push({
        id: `cmd-${name}`,
        group: 'Commands',
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
      group: 'Sessions',
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
  if ((event.ctrlKey || event.metaKey) && !event.altKey && !event.shiftKey && event.key.toLowerCase() === 'k') {
    event.preventDefault()
    panels.togglePalette()
  }
}

onMounted(() => window.addEventListener('keydown', onGlobalKey))
onBeforeUnmount(() => window.removeEventListener('keydown', onGlobalKey))
</script>

<template>
  <div v-if="panels.paletteOpen" class="backdrop" data-testid="palette-backdrop" @click.self="panels.togglePalette(false)">
    <div class="palette" role="dialog" aria-modal="true" aria-label="Command palette" data-testid="command-palette">
      <input
        ref="input"
        v-model="query"
        type="text"
        placeholder="Sessions, commands, panels…"
        aria-label="Search the palette"
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
        <li v-if="shown.length === 0" class="none">Nothing matches.</li>
      </ul>
    </div>
  </div>
</template>

<style scoped>
.backdrop {
  position: fixed;
  inset: 0;
  z-index: 40;
  background: rgb(0 0 0 / 0.35);
  display: flex;
  justify-content: center;
  align-items: flex-start;
  padding: 12vh 1rem 1rem;
}
.palette {
  width: min(36rem, 100%);
  max-height: 70vh;
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
  li {
    grid-template-columns: auto 1fr;
  }
  .group {
    display: none;
  }
}
</style>
