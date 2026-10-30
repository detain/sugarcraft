<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import type { MethodParams, MethodResult } from '../../protocol/generated'
import { diffSectionForPath } from '../../lib/diff'
import { useConnectionStore } from '../../stores/connection'
import { usePanelsStore } from '../../stores/agents/panels'
import { describe } from '../../stores/session'
import DiffView from '../DiffView.vue'
import FileViewer from '../FileViewer.vue'

/**
 * WEB-1b: the workspace's uncommitted changes in the open (`files.changed`
 * for the list, `files.diff` for the text). The diff arrives whole — the
 * wire takes a revision, not a path — so each row carves its file's section
 * out client-side; untracked rows have no section by nature and say so.
 * Fail-soft per side (w4j: Promise.allSettled, the AboutModal shape): a
 * refused diff is a stub inside the panel, never a discarded changed-list;
 * a refused list is an error line. The panel stays mounted for a reload.
 *
 * C-TUI-2.g: the diff can be taken against an earlier CHECKPOINT instead of
 * only HEAD. The wire's answer carries `checkpoints` — how many bases exist
 * for the session being viewed — and a `<select>` turns that count into the
 * same newest-first choice `/diff n` offers on the terminal. With no session
 * (or no bases) the selector degrades to HEAD alone; a refused checkpoint
 * lands in the diff stub, never as a silent HEAD fallback.
 */
const connection = useConnectionStore()
const panels = usePanelsStore()

type ChangedItem = MethodResult<'files.changed'>['items'][number]

const items = ref<ChangedItem[]>([])
const changedTruncated = ref(false)
const diffText = ref('')
const diffTruncated = ref(false)
const diffError = ref<string | null>(null)
const diffRef = ref('')
/** C-TUI-2.g: 0 is HEAD; N>0 is the Nth checkpoint base, newest first. */
const base = ref(0)
const bases = ref(0)
const selected = ref<string | null>(null)
const loading = ref(false)
const loaded = ref(false)
const error = ref<string | null>(null)
/** C-WEB-3.b: a row's "view" opens the shared read-only {@see FileViewer}. */
const viewerPath = ref<string | null>(null)

/** The `files.diff` arguments the current selection implies (C-TUI-2.g). */
function diffParams(): MethodParams<'files.diff'> {
  const session = panels.sessionId
  if (session === null) return {}
  return base.value > 0 ? { sessionId: session, checkpoint: base.value } : { sessionId: session }
}

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  const [changed, diff] = await Promise.allSettled([
    connection.request('files.changed', {}),
    connection.request('files.diff', diffParams()),
  ])
  if (changed.status === 'fulfilled') {
    items.value = changed.value.items
    changedTruncated.value = changed.value.truncated
    loaded.value = true
    if (selected.value !== null && !items.value.some((item) => item.path === selected.value)) selected.value = null
  } else {
    error.value = describe(changed.reason)
  }
  if (diff.status === 'fulfilled') {
    diffError.value = null
    diffText.value = diff.value.diff
    diffTruncated.value = diff.value.truncated
    diffRef.value = diff.value.ref
    bases.value = diff.value.checkpoints
    if (base.value > diff.value.checkpoints) base.value = diff.value.checkpoints
  } else {
    // A refused diff clears any stale text: an error stub, never last run's hunks.
    diffError.value = describe(diff.reason)
    diffText.value = ''
    diffTruncated.value = false
    diffRef.value = ''
  }
  loading.value = false
}

/** Choosing a base re-fetches the diff against it; the changed-list is unaffected. */
function chooseBase(event: Event): void {
  const value = Number((event.target as HTMLSelectElement).value)
  base.value = Number.isNaN(value) ? 0 : value
  void load()
}

watch(
  () => panels.sessionId,
  () => {
    base.value = 0
    bases.value = 0
    void load()
  },
)

/** Clicking a row narrows the diff to that file; clicking it again widens back out. */
function pick(item: ChangedItem): void {
  selected.value = selected.value === item.path ? null : item.path
}

const selection = computed(() => {
  if (selected.value === null) return null
  return items.value.find((item) => item.path === selected.value) ?? null
})

const section = computed(() => (selection.value === null ? null : diffSectionForPath(diffText.value, selection.value.path)))
const untracked = computed(() => selection.value !== null && section.value === null)
const shownDiff = computed(() => (selection.value === null ? diffText.value : (section.value ?? '')))

onMounted(() => void load())
</script>

<template>
  <div class="changes-panel" data-testid="changes-panel">
    <div class="head">
      <!-- C-TUI-2.g: the base selector only exists once a session is being viewed;
           with no session, or no bases, HEAD is the only honest option. -->
      <select
        v-if="panels.sessionId !== null"
        class="base"
        :value="base"
        data-testid="changes-base"
        aria-label="Diff base"
        :disabled="loading"
        @change="chooseBase"
      >
        <option :value="0">HEAD</option>
        <option v-for="k in bases" :key="k" :value="k">{{ k }} turn{{ k === 1 ? '' : 's' }} ago</option>
      </select>
      <span v-if="loaded" class="count" data-testid="changes-count">{{ items.length }} changed</span>
      <button type="button" class="link" data-testid="changes-reload" :disabled="loading" @click="load">reload</button>
    </div>
    <p v-if="error" class="error" role="alert" data-testid="changes-error">{{ error }}</p>
    <p v-else-if="loading && !loaded" class="empty">Checking the workspace…</p>
    <template v-else-if="loaded">
      <p v-if="changedTruncated" class="warn" data-testid="changes-truncated">The changed list is truncated.</p>
      <p v-if="items.length === 0" class="empty" data-testid="changes-none">The workspace is clean.</p>
      <ul v-else class="list" data-testid="changes-list">
        <li v-for="item in items" :key="item.path" class="entry">
          <button
            type="button"
            class="row"
            :class="{ on: selected === item.path }"
            :data-testid="`changed-row-${item.path}`"
            :aria-pressed="selected === item.path"
            @click="pick(item)"
          >
            <span class="status" :data-status="item.status">{{ item.status }}</span>
            <span class="path">{{ item.path }}</span>
            <span v-if="item.from" class="from">{{ item.from }} →</span>
          </button>
          <button type="button" class="view" :data-testid="`changed-view-${item.path}`" title="View the file" @click="viewerPath = item.path">view</button>
        </li>
      </ul>
      <FileViewer v-if="viewerPath !== null" :path="viewerPath" @close="viewerPath = null" />
      <p v-if="diffTruncated" class="warn" data-testid="diff-truncated">The workspace diff is truncated.</p>
      <!-- The stub precedes the untracked line in this chain: a refused diff is not an untracked file. -->
      <p v-if="diffError" class="error" role="alert" data-testid="changes-diff-error">{{ diffError }}</p>
      <p v-else-if="untracked" class="empty" data-testid="changes-untracked">{{ selected }} is untracked — no diff for it in the workspace.</p>
      <template v-else-if="shownDiff">
        <div class="diff-head">
          <span v-if="selection" class="sel" data-testid="changes-selected">{{ selection.path }}</span>
          <span v-else class="sel" data-testid="changes-selected">all files</span>
          <span v-if="base > 0" class="hint" data-testid="changes-diff-ref">against {{ diffRef }}</span>
          <span v-else-if="selection" class="hint">click the row again for the whole diff</span>
        </div>
        <DiffView :diff="shownDiff" />
      </template>
    </template>
  </div>
</template>

<style scoped>
.head {
  display: flex;
  gap: 0.5rem;
  align-items: baseline;
}
.count {
  font-weight: 600;
  font-size: 0.8125rem;
}
.head button {
  margin-left: auto;
}
.base {
  font-size: 0.75rem;
  padding: 0.05rem 0.2rem;
}
.list {
  list-style: none;
  margin: 0.4rem 0 0;
  padding: 0;
  font-size: 0.8125rem;
}
.entry {
  display: flex;
  gap: 0.3rem;
  align-items: baseline;
}
.entry .row {
  flex: 1;
  min-width: 0;
}
.view {
  flex: none;
  font-size: 0.6875rem;
  padding: 0.1rem 0.4rem;
}
.row {
  display: flex;
  gap: 0.5rem;
  width: 100%;
  text-align: left;
  background: none;
  border: 0;
  padding: 0.15rem 0.3rem;
  border-radius: 4px;
  cursor: pointer;
}
.row:hover {
  background: var(--panel-2, rgb(255 255 255 / 0.05));
}
.row.on {
  background: var(--accent-soft, rgb(255 255 255 / 0.08));
  outline: 1px solid var(--accent);
}
.status {
  flex: none;
  min-width: 2.5ch;
  color: var(--warn);
  font-family: var(--mono);
}
.status[data-status='??'] {
  color: var(--muted);
}
.path {
  word-break: break-all;
}
.from {
  color: var(--muted);
  word-break: break-all;
}
.diff-head {
  display: flex;
  gap: 0.5rem;
  align-items: baseline;
  margin: 0.6rem 0 0.3rem;
}
.sel {
  font-family: var(--mono);
  font-size: 0.8125rem;
  word-break: break-all;
}
.hint {
  color: var(--muted);
  font-size: 0.6875rem;
  margin-left: auto;
}
.empty {
  color: var(--muted);
  font-size: 0.875rem;
}
.warn {
  color: var(--warn);
  font-size: 0.8125rem;
  margin: 0.3rem 0;
}
.error {
  color: var(--error);
  font-size: 0.875rem;
}
</style>
