<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { fetchArtifact, MediaFetchCancelled, objectUrls } from '../lib/media'
import type { MediaRef } from '../protocol/generated'
import { useConnectionStore } from '../stores/connection'
import { describe } from '../stores/session'

/**
 * One artifact a finished tool produced (W6.4, Appendix C-7): the event
 * carried only this ref, so the bytes are pulled here, on demand, through
 * `media.fetch` slices. Images load eagerly once the card is shown — they are
 * the payoff; videos wait for a ▶ tap, so a transcript of generations never
 * auto-dials megabyte mp4s (the lazy-video law). Every created object URL is
 * revoked on unmount and on replacement (the leak law).
 *
 * Artifact paths are server-local, so this structured field is the ONLY
 * sanctioned media render path — markdown stays `html: false` and inline
 * `<img>`s of server paths must never be conjured (the DOMPurify anchor).
 */
const props = defineProps<{ media: MediaRef; sessionId?: string }>()

type Phase = 'idle' | 'loading' | 'ready' | 'error'

const connection = useConnectionStore()
const phase = ref<Phase>('idle')
const problem = ref('')
const url = ref<string | undefined>(undefined)
const mime = ref(props.media.mime)
const unsupported = ref(false)
const expanded = ref(false)

let stopped = false
let token = 0

const imageLike = computed(() => mime.value.startsWith('image/'))
const sizeText = computed(() => {
  const bytes = props.media.bytes
  if (bytes <= 0) return ''
  return bytes < 1024 * 1024 ? `${Math.max(1, Math.round(bytes / 1024))} KiB` : `${(bytes / (1024 * 1024)).toFixed(1)} MiB`
})
const altText = computed(() => `${props.media.kind} ${props.media.artifactId}`)

function disposeUrl(): void {
  if (url.value !== undefined) {
    objectUrls.revoke(url.value)
    url.value = undefined
  }
}

async function load(): Promise<void> {
  if (props.sessionId === undefined || props.sessionId === '') {
    phase.value = 'error'
    problem.value = 'No session to fetch this artifact from.'
    return
  }
  const current = ++token
  disposeUrl()
  unsupported.value = false
  problem.value = ''
  phase.value = 'loading'
  try {
    const assembled = await fetchArtifact(connection, props.sessionId, props.media, () => stopped || current !== token)
    // A cancelled or superseded load never creates its URL — nothing to revoke.
    // (An unmount landing mid-load also passes this gate only via `stopped`;
    // disposeUrl on unmount cannot see a url this continuation has not set yet,
    // but the token check above means neither can — no leak either way.)
    // A cancelled or superseded load never creates its URL — nothing to revoke.
    // (An unmount racing this continuation still lands in disposeUrl's wake:
    // `stopped` is set first, and even mid-await the token gate above drops
    // the result before create — one created URL, one revoked URL, always.)
    if (stopped || current !== token) return
    mime.value = assembled.mime
    url.value = objectUrls.create(assembled.blob)
    phase.value = 'ready'
  } catch (failure) {
    if (failure instanceof MediaFetchCancelled || stopped || current !== token) return
    phase.value = 'error'
    problem.value = describe(failure)
  }
}

function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape') expanded.value = false
}

watch(expanded, (open) => {
  if (open) window.addEventListener('keydown', onKeydown)
  else window.removeEventListener('keydown', onKeydown)
})

onMounted(() => {
  if (props.media.kind === 'image') void load()
})

watch(
  () => props.media.artifactId,
  () => {
    mime.value = props.media.mime
    unsupported.value = false
    expanded.value = false
    phase.value = 'idle'
    if (props.media.kind === 'image') void load()
  },
)

onBeforeUnmount(() => {
  stopped = true
  window.removeEventListener('keydown', onKeydown)
  disposeUrl()
})
</script>

<template>
  <figure class="media-card" :class="media.kind" :data-kind="media.kind" :data-phase="phase" data-testid="media-card">
    <figcaption class="head">
      <span class="badge" data-testid="media-badge">{{ media.kind }}</span>
      <span v-if="media.indexLabel" class="label">{{ media.indexLabel }}</span>
      <code class="id" :title="media.path">{{ media.artifactId }}</code>
      <span v-if="sizeText" class="size">{{ sizeText }}</span>
    </figcaption>

    <p v-if="phase === 'loading'" class="state" data-testid="media-loading">loading…</p>
    <p v-else-if="phase === 'error'" class="state error" role="alert" data-testid="media-error">{{ problem }}</p>
    <button v-else-if="phase === 'idle'" type="button" class="play" data-testid="media-play" @click="void load()">▶ load {{ media.kind }}</button>

    <template v-else>
      <p v-if="media.kind === 'video' && unsupported" class="state" data-testid="media-unsupported">This browser cannot play {{ mime }}.</p>
      <video v-else-if="media.kind === 'video'" controls :src="url" class="video" data-testid="media-video" @error="unsupported = true" />
      <p v-else-if="media.kind === 'image' && !imageLike" class="state" data-testid="media-file">
        <a :href="url" :download="media.artifactId">Download the {{ mime }} artifact</a>
      </p>
      <button v-else type="button" class="thumb-button" data-testid="media-image" :aria-expanded="expanded" @click="expanded = !expanded">
        <img :src="url" :alt="altText" class="thumb" />
      </button>
    </template>

    <details v-if="media.infotextDigest" class="digest" data-testid="media-digest">
      <summary>infotext digest</summary>
      <code>{{ media.infotextDigest }}</code>
    </details>

    <div v-if="expanded" class="lightbox" role="dialog" aria-modal="true" aria-label="Artifact full size" data-testid="media-lightbox" @click.self="expanded = false">
      <img :src="url" :alt="altText" class="full" />
      <button type="button" class="close" data-testid="lightbox-close" @click="expanded = false">close</button>
    </div>
  </figure>
</template>

<style scoped>
.media-card {
  margin: 0;
  display: grid;
  gap: 0.35rem;
  border: 1px solid var(--line);
  border-radius: 6px;
  padding: 0.4rem 0.5rem;
  background: var(--panel);
  min-width: 0;
}
.head {
  display: flex;
  align-items: baseline;
  gap: 0.5rem;
  min-width: 0;
  font-size: 0.8125rem;
}
.badge {
  text-transform: uppercase;
  font-size: 0.6875rem;
  font-weight: 700;
  letter-spacing: 0.04em;
  color: var(--accent);
  flex: none;
}
.label {
  color: var(--muted);
  flex: none;
}
.id {
  font-family: var(--mono);
  font-size: 0.75rem;
  color: var(--muted);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  min-width: 0;
  flex: 1;
}
.size {
  color: var(--muted);
  flex: none;
}
.state {
  margin: 0;
  color: var(--muted);
  font-size: 0.8125rem;
}
.state.error {
  color: var(--error);
}
.play {
  justify-self: start;
}
.thumb-button {
  border: 0;
  padding: 0;
  background: none;
  cursor: zoom-in;
  justify-self: start;
  max-width: 100%;
}
.thumb {
  max-width: 100%;
  max-height: 16rem;
  border-radius: 4px;
  display: block;
}
.video {
  max-width: 100%;
  border-radius: 4px;
  display: block;
}
.digest summary {
  cursor: pointer;
  color: var(--muted);
  font-size: 0.75rem;
}
.digest code {
  font-family: var(--mono);
  font-size: 0.75rem;
  overflow-wrap: anywhere;
}
.lightbox {
  position: fixed;
  inset: 0;
  z-index: 40;
  background: rgb(0 0 0 / 75%);
  display: grid;
  place-items: center;
  padding: 1.5rem;
}
.lightbox .full {
  max-width: 100%;
  max-height: 100%;
  border-radius: 6px;
}
.lightbox .close {
  position: absolute;
  top: 0.75rem;
  right: 0.75rem;
}
</style>
