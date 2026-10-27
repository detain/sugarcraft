<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { sessionLabel } from '../approvals/attention'
import { describe } from '../../stores/session'
import { loadTabChord, matchesTabChord, nextTabChord, saveTabChord, tabChordLabel, tabDigit, type TabChord } from '../../lib/tabChord'
import { useApprovalsStore } from '../../stores/approvals'
import { useLayoutStore, type Showing } from '../../stores/layout'
import { useNewSessionStore } from '../../stores/newSession'
import { useSessionsStore } from '../../stores/sessions'

/**
 * The open sessions as tabs, and the switch between one-at-a-time and the
 * grid (Appendix O §7.4). Opening a session opens its tab; a tab shows the
 * session's status and its open questions. A configurable chord + a physical
 * digit picks a tab (ISS-106; Alt+digit by default, cycled from the strip).
 *
 * A trailing `+` starts a new session (P-TABS.h): the same picker-aware
 * `newSession.start()` the dashboard uses, then routes to the fresh tab. A
 * tab whose session finished while it was not the active one carries an
 * unseen badge, cleared by selecting the session ({@see useLayoutStore}).
 *
 * It is also where the page tells the server what is on screen
 * (`client.viewing`): the open session in the tabs view; every tile in the
 * grid, narrated but the focused one.
 */
const layout = useLayoutStore()
const sessions = useSessionsStore()
const approvals = useApprovalsStore()
const newSession = useNewSessionStore()
const route = useRoute()
const router = useRouter()

const routeId = computed(() => (route.name === 'session' ? String(route.params.id) : null))
const inGrid = computed(() => route.name === 'grid')
const active = computed(() => (inGrid.value ? layout.focused : routeId.value))
const visible = computed(() => layout.tabs.length > 0 || inGrid.value)

function title(id: string): string {
  return sessionLabel(sessions.byId[id], id)
}

function status(id: string): string {
  return sessions.byId[id]?.status ?? 'closed'
}

function select(id: string): void {
  if (inGrid.value) {
    layout.focus(id)
    return
  }
  void router.push({ name: 'session', params: { id } })
}

function close(id: string): void {
  const next = layout.closeTab(id)
  if (routeId.value !== id) return
  if (next !== null) void router.push({ name: 'session', params: { id: next } })
  else void router.push({ name: 'dashboard' })
}

function toGrid(): void {
  layout.setMode('grid')
  void router.push({ name: 'grid' })
}

const starting = ref(false)
const createError = ref<string | null>(null)

/** P-TABS.h: `+` starts a session (through the picker when the server offers one). */
async function create(): Promise<void> {
  starting.value = true
  createError.value = null
  try {
    const summary = await newSession.start()
    if (summary) void router.push({ name: 'session', params: { id: summary.id } })
  } catch (failure) {
    // The no-picker path is `sessions.create()`, which records nothing on the
    // store — surface the refusal here, as the dashboard and grid do.
    createError.value = describe(failure)
  } finally {
    starting.value = false
  }
}

function toTabs(): void {
  layout.setMode('tabs')
  const id = layout.focused ?? layout.tabs[0]
  void router.push(id ? { name: 'session', params: { id } } : { name: 'dashboard' })
}

// Opening a session opens (and fronts) its tab.
watch(routeId, (id) => {
  if (id === null) return
  layout.focus(id)
  layout.setMode('tabs')
}, { immediate: true })

// A deleted session's tab goes with it.
watch(() => [sessions.loaded, sessions.byId] as const, ([loaded]) => {
  if (loaded) layout.pruneTabs((id) => sessions.byId[id] !== undefined)
})

// What is on screen, for the server.
watch(() => [route.name, routeId.value, layout.tiles, layout.focused] as const, () => {
  let showing: Showing
  if (inGrid.value) {
    const tiles = layout.tiles
    const front = layout.focused !== null && tiles.includes(layout.focused) ? layout.focused : tiles[0] ?? null
    showing = { sessionIds: [...tiles], foreground: front, narrate: true }
  } else if (routeId.value !== null) {
    showing = { sessionIds: [routeId.value], foreground: routeId.value, narrate: false }
  } else {
    showing = { sessionIds: [], foreground: null, narrate: false }
  }
  layout.show(showing)
}, { immediate: true })

// ISS-106: match the physical digit under a configurable chord. A `storage`
// event fires in the *other* tabs when one changes the setting, so the hint
// in the tooltip stays truthful everywhere.
const chord = ref<TabChord>(loadTabChord())

function onKey(event: KeyboardEvent): void {
  if (!matchesTabChord(event, chord.value)) return
  const digit = tabDigit(event)
  if (digit === null) return
  const id = layout.tabs[digit - 1]
  if (id === undefined) return
  event.preventDefault()
  select(id)
}

function cycleChord(): void {
  chord.value = nextTabChord(chord.value)
  saveTabChord(chord.value)
}

function onStorage(event: StorageEvent): void {
  if (event.key === 'candy-code-web:tab-chord') chord.value = loadTabChord()
}

onMounted(() => {
  window.addEventListener('keydown', onKey)
  window.addEventListener('storage', onStorage)
})
onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKey)
  window.removeEventListener('storage', onStorage)
})
</script>

<template>
  <nav v-if="visible" class="tabs-strip" aria-label="Open sessions" data-testid="session-tabs">
    <div class="tabs" role="tablist">
      <div
        v-for="(id, index) in layout.tabs"
        :key="id"
        class="tab"
        :class="{ active: id === active }"
        role="tab"
        :aria-selected="id === active ? 'true' : 'false'"
        data-testid="session-tab"
        :data-session-id="id"
      >
        <button type="button" class="pick" :title="index < 9 ? `${title(id)} (${tabChordLabel(chord)} ${index + 1})` : title(id)" @click="select(id)">
          <span class="dot" :class="status(id)" aria-hidden="true" />
          <span class="name">{{ title(id) }}</span>
          <span v-if="approvals.countFor(id) > 0" class="badge" data-testid="tab-ask-badge">{{ approvals.countFor(id) }}</span>
          <span v-else-if="layout.unseen[id] === true" class="fin" aria-label="finished since you last looked" data-testid="tab-unseen" title="Finished since you last looked" />
        </button>
        <button type="button" class="x" :aria-label="`Close ${title(id)}`" data-testid="tab-close" @click="close(id)">&#10005;</button>
      </div>
      <button type="button" class="new-tab" data-testid="new-session-tab" :disabled="starting" title="Start a new session" @click="create">&#43;</button>
    </div>
    <p v-if="createError" class="error" role="alert" data-testid="new-session-error">{{ createError }}</p>
    <div class="modes" role="group" aria-label="Layout">
      <button type="button" class="chord" data-testid="tab-chord" title="Key chord that picks a tab — click to cycle (the digit is the physical key, so laptop Option and AZERTY glyphs do not get in the way)" @click="cycleChord()">tabs: {{ tabChordLabel(chord) }}+1</button>
      <button type="button" :aria-pressed="!inGrid" data-testid="layout-tabs" @click="toTabs">&#9635; tabs</button>
      <button type="button" :aria-pressed="inGrid" data-testid="layout-grid" @click="toGrid">&#9638; grid</button>
    </div>
  </nav>
</template>

<style scoped>
.tabs-strip {
  flex: none;
  display: flex;
  align-items: stretch;
  gap: 0.5rem;
  /* The right end stays clear for the side panels' opener, which floats over
     this row (SidePanels.vue `.opener`): without the room it covers the
     layout buttons. */
  padding: 0.3rem 7rem 0 0.5rem;
  border-bottom: 1px solid var(--line);
  background: var(--panel);
  min-width: 0;
}
.tabs {
  flex: 1;
  display: flex;
  gap: 0.25rem;
  overflow-x: auto;
  min-width: 0;
}
.tab {
  flex: none;
  display: flex;
  align-items: center;
  max-width: 14rem;
  border: 1px solid var(--line);
  border-bottom: none;
  border-radius: 6px 6px 0 0;
  background: var(--bg);
  opacity: 0.75;
}
.tab.active {
  opacity: 1;
  border-color: var(--accent);
}
.tab button {
  border: none;
  background: none;
  border-radius: 0;
}
.pick {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  min-width: 0;
  padding: 0.25rem 0.3rem 0.25rem 0.6rem;
}
.name {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.x {
  padding: 0.25rem 0.5rem 0.25rem 0.2rem;
  color: var(--muted);
}
.dot {
  flex: none;
  width: 0.5rem;
  height: 0.5rem;
  border-radius: 50%;
  border: 1px solid var(--muted);
}
.dot.idle {
  background: var(--muted);
}
.dot.busy {
  background: var(--accent);
  border-color: var(--accent);
}
.dot.waiting_permission {
  background: var(--warn);
  border-color: var(--warn);
}
.badge {
  background: var(--warn);
  color: var(--bg);
  border-radius: 999px;
  padding: 0 0.35rem;
  font-size: 0.75rem;
  font-weight: 700;
}
/* P-TABS.h: the unseen-finished mark — a dot in the accent colour, so a tab
   that completed while you were elsewhere catches the eye until you look. */
.fin {
  flex: none;
  width: 0.4rem;
  height: 0.4rem;
  border-radius: 50%;
  background: var(--accent);
}
.new-tab {
  flex: none;
  align-self: center;
  border: 1px dashed var(--line);
  background: none;
  border-radius: 6px;
  color: var(--muted);
  padding: 0.15rem 0.6rem;
  font-size: 1rem;
  line-height: 1.2;
}
.new-tab:hover {
  border-color: var(--accent);
  color: var(--accent);
}
/* P-TABS.h review fix: a refused `+` surfaces locally, as the dashboard
   (DashboardView) and grid (PaneGrid) errors do. */
.error {
  align-self: center;
  margin: 0;
  color: var(--error);
  font-size: 0.875rem;
}
.modes {
  flex: none;
  display: flex;
  gap: 0.25rem;
  align-items: center;
  padding-bottom: 0.3rem;
}
.modes button {
  padding: 0.15rem 0.5rem;
  font-size: 0.8125rem;
}
.modes button[aria-pressed='true'] {
  border-color: var(--accent);
  color: var(--accent);
}
@media (max-width: 760px) {
  .modes button {
    padding: 0.15rem 0.35rem;
  }
}
</style>
