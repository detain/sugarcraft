<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { describe } from '../stores/session'
import { useNewSessionStore, type DirListing } from '../stores/newSession'

/**
 * The new-session directory picker (`serve --allow-dir-browse`): browse the
 * directories under the server's browse root and start a session in one.
 *
 * Opens where the server's own project lives (or at the browse root). The
 * breadcrumb climbs to any ancestor inside the root, Up goes to the parent
 * (disabled at the root), a click or Enter opens a child, the path box takes
 * a typed or pasted path, and "Start session here" starts the session in the
 * directory on screen. Keys, with the list focused: ↑/↓ move, Enter or →
 * opens, Backspace or ← goes up, Home/End jump; Escape cancels anywhere.
 * Only directory names ever come back from the server — never files.
 */
const picker = useNewSessionStore()
const router = useRouter()

const listing = ref<DirListing | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)
const showHidden = ref(false)
const typed = ref('')
const selected = ref(0)
const list = ref<HTMLElement | null>(null)

const entries = computed(() => listing.value?.entries ?? [])
const atRoot = computed(() => listing.value !== null && listing.value.parent === null)

/** The current path's ancestors inside the browse root, root first. */
const crumbs = computed<{ label: string; path: string }[]>(() => {
  const current = listing.value
  if (current === null) return []
  const root = current.root
  const rest = current.path === root ? '' : current.path.slice(root === '/' ? 1 : root.length + 1)
  const out = [{ label: root, path: root }]
  let path = root
  for (const part of rest.split('/').filter((p) => p !== '')) {
    path = path === '/' ? `/${part}` : `${path}/${part}`
    out.push({ label: part, path })
  }
  return out
})

async function go(path: string | null): Promise<void> {
  loading.value = true
  error.value = null
  try {
    const next = await picker.list(path, showHidden.value)
    listing.value = next
    typed.value = next.path
    selected.value = 0
  } catch (failure) {
    error.value = describe(failure)
  } finally {
    loading.value = false
  }
}

async function open(): Promise<void> {
  listing.value = null
  error.value = null
  typed.value = ''
  await go(picker.serverRoot)
  // The server's own root may lie outside the browse root: start at the root.
  if (listing.value === null) await go(null)
  await nextTick()
  list.value?.focus()
}

watch(() => picker.pickerOpen, (isOpen) => {
  if (isOpen) void open()
}, { immediate: true })

watch(showHidden, () => {
  if (listing.value !== null) void go(listing.value.path)
})

function up(): void {
  const parent = listing.value?.parent
  if (parent) void go(parent)
}

function descend(index: number): void {
  const entry = entries.value[index]
  if (entry) void go(entry.path)
}

function submitTyped(): void {
  const path = typed.value.trim()
  if (path !== '') void go(path)
}

async function start(): Promise<void> {
  const current = listing.value
  if (current === null || picker.starting) return
  try {
    const summary = await picker.startIn(current.path)
    await router.push({ name: 'session', params: { id: summary.id } })
  } catch {
    // picker.error says why; the dialog stays open.
  }
}

function onListKey(event: KeyboardEvent): void {
  const n = entries.value.length
  switch (event.key) {
    case 'ArrowDown':
      event.preventDefault()
      if (n > 0) selected.value = Math.min(n - 1, selected.value + 1)
      break
    case 'ArrowUp':
      event.preventDefault()
      if (n > 0) selected.value = Math.max(0, selected.value - 1)
      break
    case 'Home':
      event.preventDefault()
      selected.value = 0
      break
    case 'End':
      event.preventDefault()
      if (n > 0) selected.value = n - 1
      break
    case 'Enter':
    case 'ArrowRight':
      event.preventDefault()
      descend(selected.value)
      break
    case 'Backspace':
    case 'ArrowLeft':
      event.preventDefault()
      up()
      break
  }
}

function onKey(event: KeyboardEvent): void {
  if (event.key === 'Escape') {
    event.preventDefault()
    picker.cancel()
  }
}

watch(selected, async () => {
  await nextTick()
  list.value?.querySelector('[aria-selected="true"]')?.scrollIntoView({ block: 'nearest' })
})
</script>

<template>
  <div v-if="picker.pickerOpen" class="backdrop" data-testid="dir-picker-backdrop" @click.self="picker.cancel()" @keydown="onKey">
    <div class="picker" role="dialog" aria-modal="true" aria-labelledby="dir-picker-title" data-testid="dir-picker">
      <h2 id="dir-picker-title">Start a session in…</h2>

      <nav class="crumbs" aria-label="Current directory" data-testid="picker-crumbs">
        <template v-for="(crumb, i) in crumbs" :key="crumb.path">
          <span v-if="i > 0" class="sep" aria-hidden="true">/</span>
          <button
            type="button"
            class="link crumb"
            :aria-current="i === crumbs.length - 1 ? 'location' : undefined"
            data-testid="picker-crumb"
            @click="go(crumb.path)"
          >{{ crumb.label }}</button>
        </template>
      </nav>

      <form class="path" @submit.prevent="submitTyped">
        <input
          v-model="typed"
          type="text"
          spellcheck="false"
          autocomplete="off"
          aria-label="Directory path"
          placeholder="/path/to/project"
          data-testid="picker-path"
        >
        <button type="submit" :disabled="loading" data-testid="picker-go">Go</button>
      </form>

      <div class="tools">
        <button type="button" :disabled="atRoot || loading || listing === null" title="Parent directory (Backspace)" data-testid="picker-up" @click="up">↑ Up</button>
        <label class="hidden-toggle">
          <input v-model="showHidden" type="checkbox" data-testid="picker-hidden">
          show hidden
        </label>
        <span v-if="listing?.project" class="badge here" title="This directory looks like a project root">project</span>
      </div>

      <p v-if="error" class="error" role="alert" data-testid="picker-error">{{ error }}</p>
      <p v-if="picker.error" class="error" role="alert" data-testid="picker-start-error">{{ picker.error }}</p>

      <ul
        ref="list"
        class="entries"
        role="listbox"
        tabindex="0"
        aria-label="Subdirectories"
        :aria-activedescendant="entries[selected] ? `picker-entry-${selected}` : undefined"
        :aria-busy="loading"
        data-testid="picker-list"
        @keydown="onListKey"
      >
        <li
          v-for="(entry, i) in entries"
          :id="`picker-entry-${i}`"
          :key="entry.path"
          role="option"
          :aria-selected="i === selected"
          :class="{ on: i === selected, unreadable: !entry.readable }"
          data-testid="picker-entry"
          :data-name="entry.name"
          @mousemove="selected = i"
          @click="descend(i)"
        >
          <span class="name">{{ entry.name }}/</span>
          <span v-if="entry.project" class="badge" data-testid="picker-project">project</span>
          <span v-if="!entry.readable" class="muted">no access</span>
        </li>
        <li v-if="listing !== null && !listing.readable" class="none">This directory cannot be read.</li>
        <li v-else-if="listing !== null && entries.length === 0" class="none">No subdirectories.</li>
        <li v-if="listing?.truncated" class="none">Only the first {{ entries.length }} are shown — type a path to go further.</li>
      </ul>

      <footer>
        <span class="target" :title="listing?.path ?? ''" data-testid="picker-current">{{ listing?.path ?? '…' }}</span>
        <button type="button" data-testid="picker-cancel" @click="picker.cancel()">Cancel</button>
        <button type="button" class="primary" :disabled="listing === null || picker.starting" data-testid="picker-start" @click="start">Start session here</button>
      </footer>
    </div>
  </div>
</template>

<style scoped>
.backdrop {
  position: fixed;
  inset: 0;
  z-index: 45;
  background: rgb(0 0 0 / 0.35);
  display: flex;
  justify-content: center;
  align-items: flex-start;
  padding: 8vh 1rem 1rem;
}
.picker {
  width: min(40rem, 100%);
  max-height: 80vh;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  padding: 0.8rem;
  background: var(--panel);
  border: 1px solid var(--line);
  border-radius: 8px;
  box-shadow: 0 0.8rem 2rem rgb(0 0 0 / 0.4);
  min-width: 0;
}
h2 {
  margin: 0;
  font-size: 1rem;
}
.crumbs {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  gap: 0.15rem;
  font-family: var(--mono);
  font-size: 0.8125rem;
  min-width: 0;
}
.crumb {
  overflow-wrap: anywhere;
}
.crumb[aria-current] {
  color: var(--text);
  font-weight: 600;
}
.sep {
  color: var(--muted);
}
.path {
  display: flex;
  gap: 0.4rem;
}
.path input {
  flex: 1;
  min-width: 0;
  font-family: var(--mono);
}
.tools {
  display: flex;
  align-items: center;
  gap: 0.8rem;
}
.hidden-toggle {
  display: flex;
  align-items: center;
  gap: 0.3rem;
  color: var(--muted);
  font-size: 0.875rem;
}
.entries {
  list-style: none;
  margin: 0;
  padding: 0.3rem;
  overflow-y: auto;
  min-height: 8rem;
  flex: 1;
  border: 1px solid var(--line);
  border-radius: 6px;
  background: var(--bg);
}
.entries:focus-visible {
  outline: 2px solid var(--accent);
}
.entries li {
  display: flex;
  align-items: baseline;
  gap: 0.5rem;
  padding: 0.3rem 0.5rem;
  border-radius: 6px;
  cursor: pointer;
  min-width: 0;
}
.entries li.on {
  outline: 1px solid var(--accent);
  background: var(--panel);
}
.entries li.unreadable .name {
  color: var(--muted);
}
.name {
  font-family: var(--mono);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  min-width: 0;
}
.badge {
  font-size: 0.6875rem;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  padding: 0 0.35rem;
  border-radius: 4px;
  border: 1px solid var(--ok);
  color: var(--ok);
}
.muted {
  color: var(--muted);
  font-size: 0.8125rem;
}
.entries li.none {
  display: block;
  color: var(--muted);
  cursor: default;
}
footer {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}
.target {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  direction: rtl;
  text-align: left;
  font-family: var(--mono);
  font-size: 0.8125rem;
  color: var(--muted);
}
.error {
  margin: 0;
  color: var(--error);
}
</style>
