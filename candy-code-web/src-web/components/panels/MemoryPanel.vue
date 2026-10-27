<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { MEMORY_SCOPES, useMemoryStore, type MemoryRevision, type MemoryScope } from '../../stores/agents/memory'
import { useConnectionStore } from '../../stores/connection'
import { describe } from '../../stores/session'

/**
 * The notes `/memory` keeps (roadmap O-6c): a tab per scope, a search across
 * every scope, and add, edit and delete — the scope a new note goes to is the
 * tab it was written in. Since ISS-020 a `project` tab lists both stores and
 * badges each note with its source: `repo` notes live in the checkout and are
 * shared with teammates, `home` notes are private to this machine. A note
 * that could not land in the repository says so. A History tab beside the
 * scope tabs lists the home store's `memory.log` commits — loaded when the
 * tab opens, not on mount, because the log commits pending notes first — and
 * each row offers a confirmed `memory.undo` back to that revision, but only
 * with write scope; the note list reloads after an undo.
 */
const memory = useMemoryStore()
const connection = useConnectionStore()
const draft = ref('')
const query = ref('')
const editing = ref<string | null>(null)
const editText = ref('')
const error = ref<string | null>(null)
const notice = ref('')
const tab = ref<'notes' | 'history'>('notes')
const canWrite = computed(() => connection.scopes.includes('write'))

onMounted(() => void memory.load())

async function act(action: () => Promise<unknown>): Promise<void> {
  error.value = null
  notice.value = ''
  try {
    await action()
  } catch (failure) {
    error.value = describe(failure)
  }
}

function pick(scope: MemoryScope): void {
  tab.value = 'notes'
  query.value = ''
  void memory.load(scope)
}

/** `memory.log` commits pending notes first, so the history opens on demand. */
function openHistory(): void {
  tab.value = 'history'
  void memory.log()
}

async function undoTo(revision: MemoryRevision): Promise<void> {
  if (typeof window !== 'undefined' && !window.confirm(`Undo the memory store to ${revision.shortSha} ("${revision.subject}")? Every note is replaced by that commit's state.`)) return
  await act(async () => {
    await memory.undo(revision.sha)
    await memory.log()
  })
}

async function add(): Promise<void> {
  const text = draft.value.trim()
  if (text === '') return
  await act(async () => {
    const receipt = await memory.add(text)
    if (receipt.fellBackToHome) {
      notice.value = 'This project note could not be written into the repository; it was saved to your home memory instead, where teammates will not see it.'
    }
    draft.value = ''
  })
}

function startEdit(id: string, content: string): void {
  editing.value = id
  editText.value = content
}

async function saveEdit(id: string): Promise<void> {
  await act(async () => {
    await memory.edit(id, editText.value)
    editing.value = null
  })
}

async function remove(id: string): Promise<void> {
  if (typeof window !== 'undefined' && !window.confirm('Delete this note?')) return
  await act(() => memory.remove(id))
}
</script>

<template>
  <div class="memory-panel" data-testid="memory-panel">
    <div class="scopes" role="tablist" aria-label="Memory scope">
      <button
        v-for="scope in MEMORY_SCOPES"
        :key="scope"
        type="button"
        role="tab"
        :aria-selected="tab === 'notes' && memory.query === '' && memory.scope === scope"
        :class="{ on: tab === 'notes' && memory.query === '' && memory.scope === scope }"
        :data-testid="`memory-scope-${scope}`"
        @click="pick(scope)"
      >{{ scope }}</button>
      <button
        type="button"
        role="tab"
        aria-label="Memory history"
        :aria-selected="tab === 'history'"
        :class="{ on: tab === 'history' }"
        data-testid="memory-tab-history"
        @click="openHistory"
      >history</button>
    </div>
    <p v-if="error || memory.error" class="error" role="alert">{{ error || memory.error }}</p>
    <p v-if="notice" class="notice" role="status" data-testid="memory-notice">{{ notice }}</p>
    <template v-if="tab === 'notes'">
    <form class="search" role="search" @submit.prevent="memory.search(query)">
      <input v-model="query" type="search" placeholder="Search every scope…" aria-label="Search notes" data-testid="memory-search">
    </form>
    <form v-if="canWrite && memory.query === ''" class="add" @submit.prevent="add">
      <textarea v-model="draft" rows="2" :placeholder="`A new ${memory.scope} note…`" aria-label="New note" data-testid="memory-draft" />
      <button type="submit" class="primary" :disabled="draft.trim() === ''" data-testid="memory-add">Add</button>
    </form>
    <p v-if="memory.entries.length === 0 && !memory.loading" class="empty">{{ memory.query ? 'No note matches.' : `No ${memory.scope} notes.` }}</p>
    <ul class="notes">
      <li v-for="entry in memory.entries" :key="entry.id" data-testid="memory-entry" :data-id="entry.id">
        <template v-if="editing === entry.id">
          <textarea v-model="editText" rows="3" aria-label="Edit note" />
          <div class="actions">
            <button type="button" class="primary" :disabled="editText.trim() === ''" @click="saveEdit(entry.id)">Save</button>
            <button type="button" @click="editing = null">Cancel</button>
          </div>
        </template>
        <template v-else>
          <p class="content">{{ entry.content }}</p>
          <p class="meta">
            <span v-if="entry.source" class="source" :data-testid="`memory-source-${entry.source}`">{{ entry.source }}</span>
            <span v-if="memory.query">{{ entry.scope }} · </span>{{ entry.modifiedAt || entry.createdAt || '' }}<span v-if="entry.tags && entry.tags.length > 0"> · {{ entry.tags.join(', ') }}</span>
          </p>
          <div v-if="canWrite" class="actions">
            <button type="button" class="link" @click="startEdit(entry.id, entry.content)">edit</button>
            <button type="button" class="link" data-testid="memory-delete" @click="remove(entry.id)">delete</button>
          </div>
        </template>
      </li>
    </ul>
    </template>
    <template v-else>
      <p v-if="memory.revisions.length === 0 && !memory.historyLoading" class="empty">No memory history yet.</p>
      <ul class="revisions" data-testid="memory-history">
        <li v-for="revision in memory.revisions" :key="revision.sha" data-testid="memory-revision" :data-sha="revision.sha">
          <p class="meta"><span data-testid="memory-rev-sha">{{ revision.shortSha }}</span> · <span>{{ revision.committedAt }}</span> · <span data-testid="memory-rev-subject">{{ revision.subject }}</span></p>
          <div v-if="canWrite" class="actions">
            <button type="button" class="link" data-testid="memory-undo" @click="undoTo(revision)">undo to this revision</button>
          </div>
        </li>
      </ul>
    </template>
  </div>
</template>

<style scoped>
.memory-panel {
  display: grid;
  gap: 0.5rem;
  min-width: 0;
}
.scopes {
  display: flex;
  gap: 0.3rem;
}
.scopes .on {
  border-color: var(--accent);
  color: var(--accent);
}
input,
textarea {
  width: 100%;
  font: inherit;
  padding: 0.3rem 0.5rem;
  color: var(--text);
  background: var(--bg);
  border: 1px solid var(--line);
  border-radius: 6px;
  min-width: 0;
}
textarea {
  resize: vertical;
}
.add {
  display: flex;
  gap: 0.4rem;
  align-items: flex-end;
}
.empty,
.meta {
  margin: 0;
  color: var(--muted);
  font-size: 0.8125rem;
}
.error {
  margin: 0;
  color: var(--error);
  font-size: 0.875rem;
}
.notice {
  margin: 0;
  color: var(--warn);
  font-size: 0.875rem;
}
.source {
  display: inline-block;
  padding: 0 0.35rem;
  border: 1px solid var(--line);
  border-radius: 999px;
  font-size: 0.75rem;
  color: var(--muted);
}
.notes,
.revisions {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: 0.5rem;
}
.notes li,
.revisions li {
  padding: 0.4rem 0.5rem;
  border: 1px solid var(--line);
  border-radius: 6px;
  background: var(--bg);
  display: grid;
  gap: 0.25rem;
}
.content {
  margin: 0;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}
.actions {
  display: flex;
  gap: 0.6rem;
}
</style>
