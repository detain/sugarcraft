<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { describe, useSessionStore } from '../../stores/session'
import FileViewer from '../FileViewer.vue'

/**
 * C-WEB-3.b: the Files side panel — a search box over the workspace's
 * `fs.search` (the same call the composer's @-mention popup makes, through
 * `searchFiles()` on the session store), jailed server-side to the project
 * root. An empty query lists the front of the project. Clicking a row opens
 * the shared {@see FileViewer}; the panel itself never shows file contents.
 */
const props = defineProps<{ sessionId: string }>()

/** Rows asked for; the wire caps a listing at 100. */
const PANEL_LIMIT = 50

const session = useSessionStore(props.sessionId)

const query = ref('')
const matches = ref<string[]>([])
const loading = ref(false)
const error = ref<string | null>(null)
const viewing = ref<string | null>(null)
// Keystrokes race each other's answers: only the newest search may land.
let seq = 0

async function search(): Promise<void> {
  const mine = ++seq
  loading.value = true
  error.value = null
  try {
    const found = await session.searchFiles(query.value, PANEL_LIMIT)
    if (mine !== seq) return
    matches.value = found
  } catch (failure) {
    if (mine !== seq) return
    error.value = describe(failure)
  }
  if (mine === seq) loading.value = false
}

watch(query, () => void search())

function open(path: string): void {
  viewing.value = path
}

onMounted(() => void search())
</script>

<template>
  <div class="files-panel" data-testid="files-panel">
    <input
      v-model="query"
      type="search"
      class="search"
      placeholder="Search workspace files…"
      aria-label="Search workspace files"
      data-testid="files-search"
    />
    <p v-if="error" class="error" role="alert" data-testid="files-error">{{ error }}</p>
    <p v-else-if="loading && matches.length === 0" class="empty">Searching the workspace…</p>
    <p v-else-if="matches.length === 0" class="empty" data-testid="files-none">No files match that search.</p>
    <ul v-else class="list" data-testid="files-list">
      <li v-for="path in matches" :key="path">
        <button type="button" class="row" :class="{ on: viewing === path }" :data-testid="`files-row-${path}`" @click="open(path)">{{ path }}</button>
      </li>
    </ul>
    <FileViewer v-if="viewing !== null" :path="viewing" @close="viewing = null" />
  </div>
</template>

<style scoped>
.search {
  width: 100%;
  font-size: 0.875rem;
}
.list {
  list-style: none;
  margin: 0.4rem 0 0;
  padding: 0;
  font-size: 0.8125rem;
}
.row {
  display: block;
  width: 100%;
  text-align: left;
  background: none;
  border: 0;
  padding: 0.15rem 0.3rem;
  border-radius: 4px;
  cursor: pointer;
  font-family: var(--mono);
  word-break: break-all;
}
.row:hover {
  background: var(--panel-2, rgb(255 255 255 / 0.05));
}
.row.on {
  background: var(--accent-soft, rgb(255 255 255 / 0.08));
  outline: 1px solid var(--accent);
}
.empty {
  color: var(--muted);
  font-size: 0.875rem;
}
.error {
  color: var(--error);
  font-size: 0.875rem;
}
</style>
