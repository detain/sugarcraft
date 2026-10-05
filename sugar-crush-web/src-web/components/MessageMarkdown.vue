<script lang="ts">
// Syntax colouring is its own chunk, fetched the first time a reply holds a
// fenced block with a language — never part of the first page load.
let highlighter: Promise<typeof import('../lib/highlight')> | null = null
</script>

<script setup lang="ts">
import { computed, onMounted, onUpdated, ref } from 'vue'
import { renderMarkdown } from '../lib/markdown'

const props = defineProps<{ text: string }>()
// Sanitised by renderMarkdown (DOMPurify) before it reaches v-html.
const html = computed(() => renderMarkdown(props.text))
const root = ref<HTMLElement | null>(null)

async function colour(): Promise<void> {
  const element = root.value
  if (element === null || element.querySelector('pre > code[class*="language-"]') === null) return
  try {
    highlighter ??= import('../lib/highlight')
    const { highlightIn } = await highlighter
    if (root.value === element) highlightIn(element)
  } catch {
    // An unloadable chunk (a deploy mid-session) leaves the code plain.
    highlighter = null
  }
}

onMounted(colour)
onUpdated(colour)
</script>

<template>
  <!-- eslint-disable-next-line vue/no-v-html -->
  <div ref="root" class="markdown" v-html="html" />
</template>

<style scoped>
.markdown {
  line-height: 1.55;
  overflow-wrap: anywhere;
}
.markdown :deep(p) {
  margin: 0 0 0.6rem;
}
.markdown :deep(p:last-child) {
  margin-bottom: 0;
}
.markdown :deep(blockquote) {
  margin: 0 0 0.6rem;
  padding: 0.1rem 0 0.1rem 0.75rem;
  border-left: 3px solid var(--line);
  color: var(--muted);
}
.markdown :deep(code) {
  font-family: var(--mono);
  font-size: 0.875em;
  background: var(--panel);
  padding: 0.1rem 0.3rem;
  border-radius: 4px;
}
.markdown :deep(.code-block) {
  position: relative;
  margin: 0 0 0.6rem;
}
.markdown :deep(.code-lang) {
  position: absolute;
  top: 0.25rem;
  right: 0.5rem;
  font-size: 0.75rem;
  color: var(--muted);
}
.markdown :deep(pre) {
  margin: 0;
  padding: 0.75rem;
  overflow-x: auto;
  background: var(--panel);
  border: 1px solid var(--line);
  border-radius: 6px;
}
.markdown :deep(pre code) {
  background: none;
  padding: 0;
}
.markdown :deep(.hl-comment) {
  color: var(--muted);
  font-style: italic;
}
.markdown :deep(.hl-string) {
  color: var(--ok);
}
.markdown :deep(.hl-number),
.markdown :deep(.hl-literal) {
  color: var(--warn);
}
.markdown :deep(.hl-keyword),
.markdown :deep(.hl-tag) {
  color: var(--accent);
}
.markdown :deep(.hl-variable),
.markdown :deep(.hl-attr),
.markdown :deep(.hl-key) {
  color: var(--error);
}
.markdown :deep(.hl-meta) {
  color: var(--muted);
}
.markdown :deep(.hl-add) {
  background: var(--diff-add);
}
.markdown :deep(.hl-del) {
  background: var(--diff-del);
}
.markdown :deep(a) {
  color: var(--accent);
}
.markdown :deep(table) {
  border-collapse: collapse;
  margin: 0 0 0.6rem;
}
.markdown :deep(th),
.markdown :deep(td) {
  border: 1px solid var(--line);
  padding: 0.25rem 0.5rem;
}
</style>
