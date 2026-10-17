<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { MEMORY_SCOPES, useMemoryStore, type MemoryScope } from '../../stores/agents/memory'
import { useConnectionStore } from '../../stores/connection'
import { describe } from '../../stores/session'

/**
 * The notes `/memory` keeps (roadmap O-6c): a tab per scope, a search across
 * every scope, and add, edit and delete — the scope a new note goes to is the
 * tab it was written in.
 */
const memory = useMemoryStore()
const connection = useConnectionStore()
const draft = ref('')
const query = ref('')
const editing = ref<string | null>(null)
const editText = ref('')
const error = ref<string | null>(null)
const canWrite = computed(() => connection.scopes.includes('write'))

onMounted(() => void memory.load())

async function act(action: () => Promise<unknown>): Promise<void> {
  error.value = null
  try {
    await action()
  } catch (failure) {
    error.value = describe(failure)
  }
}

function pick(scope: MemoryScope): void {
  query.value = ''
  void memory.load(scope)
}

async function add(): Promise<void> {
  const text = draft.value.trim()
  if (text === '') return
  await act(async () => {
    await memory.add(text)
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
        :aria-selected="memory.query === '' && memory.scope === scope"
        :class="{ on: memory.query === '' && memory.scope === scope }"
        :data-testid="`memory-scope-${scope}`"
        @click="pick(scope)"
      >{{ scope }}</button>
    </div>
    <form class="search" role="search" @submit.prevent="memory.search(query)">
      <input v-model="query" type="search" placeholder="Search every scope…" aria-label="Search notes" data-testid="memory-search">
    </form>
    <form v-if="canWrite && memory.query === ''" class="add" @submit.prevent="add">
      <textarea v-model="draft" rows="2" :placeholder="`A new ${memory.scope} note…`" aria-label="New note" data-testid="memory-draft" />
      <button type="submit" class="primary" :disabled="draft.trim() === ''" data-testid="memory-add">Add</button>
    </form>
    <p v-if="error || memory.error" class="error" role="alert">{{ error || memory.error }}</p>
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
            <span v-if="memory.query">{{ entry.scope }} · </span>{{ entry.modifiedAt || entry.createdAt || '' }}<span v-if="entry.tags && entry.tags.length > 0"> · {{ entry.tags.join(', ') }}</span>
          </p>
          <div v-if="canWrite" class="actions">
            <button type="button" class="link" @click="startEdit(entry.id, entry.content)">edit</button>
            <button type="button" class="link" data-testid="memory-delete" @click="remove(entry.id)">delete</button>
          </div>
        </template>
      </li>
    </ul>
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
.notes {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: 0.5rem;
}
.notes li {
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
