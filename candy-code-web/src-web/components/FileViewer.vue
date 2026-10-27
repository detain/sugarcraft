<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useConnectionStore } from '../stores/connection'
import { describe } from '../stores/session'

/**
 * C-WEB-3.b: the shared read-only file viewer over `files.read`. Content
 * renders as plain text, one row per line with a line number — never
 * `v-html`, per the ChangesPanel security posture (a file's text is
 * untrusted data, same as a diff's). `binary: true` is a size notice, not
 * an attempt to print it; `truncated` offers "load more" that continues at
 * the next byte offset (the wire reads bytes, not lines, so loaded chunks
 * are appended and the whole text is re-split — line numbers stay true
 * across a mid-line chunk boundary). A truncated read consumed exactly the
 * requested limit in RAW bytes (FilesMethods truncates iff offset + the raw
 * strlen < size), so the advance is offset + READ_LIMIT_BYTES — never the
 * sanitized content's length, which is shorter when the read carried
 * invalid UTF-8 bytes.
 */
const props = defineProps<{ path: string }>()
const emit = defineEmits<{ close: [] }>()

/** Bytes asked for per read; the server caps each read at 1 MiB. */
const READ_LIMIT_BYTES = 32_768

const connection = useConnectionStore()

const text = ref('')
const offset = ref(0)
const size = ref(0)
const binary = ref(false)
const truncated = ref(false)
const loading = ref(false)
const error = ref<string | null>(null)
// A row click while the viewer is open reuses this instance (FilesPanel.vue
// binds :path): an in-flight read for the old path must not land under the
// new one's header. Same newest-wins discipline as the panel's search.
let seq = 0

const lines = computed(() => (text.value === '' ? [] : text.value.split('\n')))
/** Raw bytes consumed so far: a truncated read filled the whole limit. */
const shownBytes = computed(() => offset.value + (truncated.value ? READ_LIMIT_BYTES : 0))

async function load(startOffset: number): Promise<void> {
  const mine = ++seq
  loading.value = true
  error.value = null
  try {
    const answer = await connection.request('files.read', { path: props.path, offset: startOffset, limit: READ_LIMIT_BYTES })
    if (mine !== seq) return
    if (startOffset === 0) text.value = answer.content
    else text.value += answer.content
    offset.value = answer.offset
    size.value = answer.size
    binary.value = answer.binary
    truncated.value = answer.binary ? false : answer.truncated
  } catch (failure) {
    if (mine !== seq) return
    error.value = describe(failure)
  }
  if (mine === seq) loading.value = false
}

function loadMore(): void {
  void load(shownBytes.value)
}

function reset(): void {
  text.value = ''
  offset.value = 0
  size.value = 0
  binary.value = false
  truncated.value = false
  error.value = null
}

watch(() => props.path, () => {
  reset()
  void load(0)
})

onMounted(() => void load(0))
</script>

<template>
  <div class="viewer" data-testid="file-viewer">
    <div class="head">
      <span class="path" data-testid="file-viewer-path">{{ path }}</span>
      <button type="button" class="link close" aria-label="Close the file viewer" data-testid="file-viewer-close" @click="emit('close')">✕</button>
    </div>
    <p v-if="error" class="error" role="alert" data-testid="file-viewer-error">{{ error }}</p>
    <p v-else-if="binary" class="notice" data-testid="file-viewer-binary">{{ path }} is a binary file ({{ size }} bytes) — nothing to show as text.</p>
    <template v-else>
      <div v-if="loading && text === ''" class="notice">Reading {{ path }}…</div>
      <div class="lines" data-testid="file-viewer-lines">
        <div v-for="(line, i) in lines" :key="i" class="line" :data-testid="`file-viewer-line-${i + 1}`">
          <span class="ln">{{ i + 1 }}</span><pre class="txt">{{ line }}</pre>
        </div>
      </div>
      <p v-if="truncated" class="more">
        <button type="button" class="link" data-testid="file-viewer-load-more" :disabled="loading" @click="loadMore">load more</button>
        <span class="hint">showing {{ shownBytes }} of {{ size }} bytes</span>
      </p>
    </template>
  </div>
</template>

<style scoped>
.viewer {
  border: 1px solid var(--line);
  border-radius: 6px;
  padding: 0.5rem 0.6rem;
  margin: 0.4rem 0 0;
  min-width: 0;
}
.head {
  display: flex;
  gap: 0.5rem;
  align-items: baseline;
}
.path {
  font-family: var(--mono);
  font-size: 0.8125rem;
  word-break: break-all;
}
.close {
  margin-left: auto;
}
.lines {
  margin: 0.4rem 0 0;
  font-size: 0.75rem;
}
.line {
  display: flex;
  gap: 0.5rem;
  align-items: baseline;
}
.ln {
  flex: none;
  min-width: 4ch;
  text-align: right;
  color: var(--muted);
  font-family: var(--mono);
  user-select: none;
}
.txt {
  margin: 0;
  font: inherit;
  font-family: var(--mono);
  white-space: pre-wrap;
  word-break: break-all;
  flex: 1;
  min-width: 0;
}
.notice {
  color: var(--muted);
  font-size: 0.8125rem;
}
.error {
  color: var(--error);
  font-size: 0.875rem;
}
.more {
  margin: 0.5rem 0 0;
  display: flex;
  gap: 0.5rem;
  align-items: baseline;
}
.hint {
  color: var(--muted);
  font-size: 0.6875rem;
}
</style>
