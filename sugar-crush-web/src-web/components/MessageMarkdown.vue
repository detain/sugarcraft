<script setup lang="ts">
import { computed } from 'vue'
import { renderMarkdown } from '../lib/markdown'

const props = defineProps<{ text: string }>()
// Sanitised by renderMarkdown (DOMPurify) before it reaches v-html.
const html = computed(() => renderMarkdown(props.text))
</script>

<template>
  <!-- eslint-disable-next-line vue/no-v-html -->
  <div class="markdown" v-html="html" />
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
