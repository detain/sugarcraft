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

/**
 * C-TUI-3.b: one copy button per fenced block. The buttons cannot ride the
 * sanitised HTML — DOMPurify's FORBID_TAGS drops `button` (the injection
 * surface is exactly the point of that list) — so this pass appends real DOM
 * nodes after each v-html render, skipping blocks that already carry one.
 * The clipboard needs a secure context (the notifications precedent,
 * `stores/settings.ts`), so outside one no buttons appear at all. The text
 * is read from the code element at click time: a tokenised block's
 * textContent is unchanged by the colouring pass, and an edit that lands
 * between paint and click copies what is on screen.
 *
 * The walk keys on each `pre`, not on the `.code-block` wrapper: sanitising
 * a fragment whose first element is the wrapper drops the wrapper but keeps
 * its children under some DOM implementations (happy-dom under vitest,
 * reproducibly), so a wrapper-keyed pass would silently decorate nothing in
 * those tests. In a browser the wrapper survives and `closest()` returns it,
 * which keeps the absolutely-positioned button anchored to the block box
 * rather than the (horizontally scrollable) `pre`.
 */
function decorate(): void {
  const element = root.value
  if (element === null) return
  if (typeof window !== 'undefined' && window.isSecureContext === false) return
  for (const pre of Array.from(element.querySelectorAll<HTMLElement>('pre'))) {
    const code = pre.querySelector('code')
    if (code === null) continue
    const block = pre.closest<HTMLElement>('.code-block') ?? pre
    if (block.querySelector('.code-copy') !== null) continue
    const button = document.createElement('button')
    button.type = 'button'
    button.className = 'code-copy'
    button.setAttribute('data-testid', 'copy-code-block')
    button.textContent = 'copy'
    button.title = 'Copy this code block'
    button.addEventListener('click', () => {
      const text = (code.textContent ?? '').replace(/\n$/, '')
      if (typeof navigator === 'undefined' || !navigator.clipboard) {
        button.textContent = "couldn't copy"
        return
      }
      navigator.clipboard.writeText(text).then(
        () => { button.textContent = 'copied' },
        () => { button.textContent = "couldn't copy" },
      )
    })
    block.appendChild(button)
  }
}

function paint(): void {
  void colour()
  decorate()
}

onMounted(paint)
onUpdated(paint)
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
.markdown :deep(.code-copy) {
  position: absolute;
  bottom: 0.4rem;
  right: 0.6rem;
  padding: 0.05rem 0.45rem;
  font-size: 0.75rem;
  color: var(--muted);
  background: var(--panel);
  border: 1px solid var(--line);
  border-radius: 4px;
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
