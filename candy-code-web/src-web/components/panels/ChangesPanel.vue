<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import type { MethodResult } from '../../protocol/generated'
import { diffSectionForPath } from '../../lib/diff'
import { useConnectionStore } from '../../stores/connection'
import { describe } from '../../stores/session'
import DiffView from '../DiffView.vue'

/**
 * WEB-1b: the workspace's uncommitted changes in the open (`files.changed`
 * for the list, `files.diff` for the text). The diff arrives whole — the
 * wire takes a revision, not a path — so each row carves its file's section
 * out client-side; untracked rows have no section by nature and say so.
 * Fail-soft per side (w4j: Promise.allSettled, the AboutModal shape): a
 * refused diff is a stub inside the panel, never a discarded changed-list;
 * a refused list is an error line. The panel stays mounted for a reload.
 */
const connection = useConnectionStore()

type ChangedItem = MethodResult<'files.changed'>['items'][number]

const items = ref<ChangedItem[]>([])
const changedTruncated = ref(false)
const diffText = ref('')
const diffTruncated = ref(false)
const diffError = ref<string | null>(null)
const selected = ref<string | null>(null)
const loading = ref(false)
const loaded = ref(false)
const error = ref<string | null>(null)

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  const [changed, diff] = await Promise.allSettled([
    connection.request('files.changed', {}),
    connection.request('files.diff', {}),
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
  } else {
    // A refused diff clears any stale text: an error stub, never last run's hunks.
    diffError.value = describe(diff.reason)
    diffText.value = ''
    diffTruncated.value = false
  }
  loading.value = false
}

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
      <span v-if="loaded" class="count" data-testid="changes-count">{{ items.length }} changed</span>
      <button type="button" class="link" data-testid="changes-reload" :disabled="loading" @click="load">reload</button>
    </div>
    <p v-if="error" class="error" role="alert" data-testid="changes-error">{{ error }}</p>
    <p v-else-if="loading && !loaded" class="empty">Checking the workspace…</p>
    <template v-else-if="loaded">
      <p v-if="changedTruncated" class="warn" data-testid="changes-truncated">The changed list is truncated.</p>
      <p v-if="items.length === 0" class="empty" data-testid="changes-none">The workspace is clean.</p>
      <ul v-else class="list" data-testid="changes-list">
        <li v-for="item in items" :key="item.path">
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
        </li>
      </ul>
      <p v-if="diffTruncated" class="warn" data-testid="diff-truncated">The workspace diff is truncated.</p>
      <!-- The stub precedes the untracked line in this chain: a refused diff is not an untracked file. -->
      <p v-if="diffError" class="error" role="alert" data-testid="changes-diff-error">{{ diffError }}</p>
      <p v-else-if="untracked" class="empty" data-testid="changes-untracked">{{ selected }} is untracked — no diff for it in the workspace.</p>
      <template v-else-if="shownDiff">
        <div class="diff-head">
          <span v-if="selection" class="sel" data-testid="changes-selected">{{ selection.path }}</span>
          <span v-else class="sel" data-testid="changes-selected">all files</span>
          <span v-if="selection" class="hint">click the row again for the whole diff</span>
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
.list {
  list-style: none;
  margin: 0.4rem 0 0;
  padding: 0;
  font-size: 0.8125rem;
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
