import { defineStore } from 'pinia'
import { computed, ref, shallowRef } from 'vue'
import { useConnectionStore } from '../connection'
import {
  changeSet,
  editability,
  sameValue,
  type EffectiveValue,
  type SavePreview,
  type SaveResult,
  type SettingRow,
  type SettingsFile,
  type Staged,
  type TierInfo,
  type TierScope,
} from './fields'

/**
 * The server's settings, as the web settings form edits them (roadmap O-6b,
 * Appendix O §7.4 "Settings view"): the rows of `settings.schema` — the same
 * schema the TUI settings editor is built from — the effective values with
 * their provenance (`settings.get`), and an edit set staged against one tier,
 * previewed (`settings.preview`) and then saved in one write
 * (`settings.set`). Nothing here decides what may be written: the schema's
 * `writableRemotely`, the effective value's lock and the tier's `writable`
 * come from the server, and its writer refuses again on save.
 *
 * The viewer's own UI preferences (the page theme, notifications) are a
 * different thing and live in `stores/settings.ts`.
 */
export const useServerSettingsStore = defineStore('serverSettings', () => {
  const connection = useConnectionStore()

  const rows = shallowRef<SettingRow[]>([])
  const tiers = shallowRef<TierInfo[]>([])
  const values = shallowRef<Record<string, EffectiveValue>>({})
  const files = shallowRef<SettingsFile[]>([])
  /**
   * What each tier's OWN file holds (raw key=>value, each read with a
   * `settings.get` for that scope). `stage()` compares an edit against this,
   * never against the merged effective value in `values`: an edit that
   * deliberately shadows a higher-priority tier matches effective BY DESIGN,
   * and comparing against effective discarded every such pin silently
   * (cc-review-1111 CW-QUAL-A n45). A tier whose read failed is unknown, and
   * an unknown tier never swallows an edit — fail open.
   */
  const ownValues = ref<Partial<Record<TierScope, Record<string, unknown>>>>({})
  const loaded = ref(false)
  const loading = ref(false)
  const error = ref<string | null>(null)
  const scope = ref<TierScope>('user')
  const staged = ref<Record<string, Staged>>({})
  const preview = shallowRef<SavePreview | null>(null)
  const previewing = ref(false)
  const saving = ref(false)
  const saved = shallowRef<SaveResult | null>(null)

  const byKey = computed(() => new Map(rows.value.map((row) => [row.key, row])))
  const tier = computed(() => tiers.value.find((candidate) => candidate.scope === scope.value))
  const stagedCount = computed(() => Object.keys(staged.value).length)
  const dirty = computed(() => stagedCount.value > 0)

  function message(failure: unknown): string {
    return failure instanceof Error ? failure.message : String(failure)
  }

  /** Read the schema and the effective values; again after every save and reconnect. */
  async function load(): Promise<void> {
    loading.value = true
    error.value = null
    try {
      const [schema, effective] = await Promise.all([
        connection.request('settings.schema', {}),
        connection.request('settings.get', { scope: 'effective' }),
      ])
      rows.value = schema.items
      tiers.value = schema.tiers ?? []
      values.value = effective.values as Record<string, EffectiveValue>
      files.value = effective.files ?? []
      loaded.value = true
      // The staged edits compare against what each tier's own file holds,
      // and that never appears in the merged effective answer above (see
      // stage()). These reads ride load()'s settle so the save's re-read
      // leaves the comparison basis fresh, and a refused or failed tier
      // read is absorbed: its absence makes stage() fail open, never the
      // form's whole load fail.
      const own = await Promise.all(
        tiers.value.map(async (entry: TierInfo): Promise<[TierScope, Record<string, unknown> | undefined]> => {
          try {
            const read = await connection.request('settings.get', { scope: entry.scope })
            return [entry.scope, read.values as Record<string, unknown>]
          } catch {
            return [entry.scope, undefined]
          }
        }),
      )
      const ownMap: Partial<Record<TierScope, Record<string, unknown>>> = {}
      for (const [entryScope, read] of own) if (read !== undefined) ownMap[entryScope] = read
      ownValues.value = ownMap
    } catch (failure) {
      error.value = message(failure)
    } finally {
      loading.value = false
    }
  }

  connection.onHello(() => {
    if (loaded.value) void load()
  })

  function row(key: string): SettingRow | undefined {
    return byKey.value.get(key)
  }

  function editabilityOf(key: string): { editable: boolean; reason: string | null } {
    const found = row(key)
    if (found === undefined) return { editable: false, reason: 'no such setting' }
    return editability(found, values.value[key], tier.value)
  }

  /** Switch the tier a save writes to. The edit set stays; the preview is redone. */
  function setScope(next: TierScope): void {
    scope.value = next
    preview.value = null
  }

  /**
   * Stage `value` for `key`; drops the edit only when it restages the value
   * the tier being written already holds in its OWN file. Comparing against
   * the merged effective value instead silently discarded every deliberate
   * shadow — pinning a value a higher-priority tier already provides looks
   * identical in the merged view but is a real write (cc-review-1111
   * CW-QUAL-A n45). When the staged tier's own file is unknown (its read
   * failed) the edit stands: fail open, never swallow a user's write.
   */
  function stage(key: string, value: unknown): void {
    const next = { ...staged.value }
    const own = ownValues.value[scope.value]
    if (own !== undefined && sameValue(value, own[key])) delete next[key]
    else next[key] = { action: 'set', value }
    staged.value = next
    preview.value = null
    saved.value = null
  }

  /** Stage a reset: the save deletes the key from the tier's file. */
  function stageReset(key: string): void {
    staged.value = { ...staged.value, [key]: { action: 'reset' } }
    preview.value = null
    saved.value = null
  }

  function unstage(key: string): void {
    if (staged.value[key] === undefined) return
    const next = { ...staged.value }
    delete next[key]
    staged.value = next
    preview.value = null
  }

  function discard(): void {
    staged.value = {}
    preview.value = null
  }

  /** Ask the server what the save would do — the diff, when each change applies, what blocks it. */
  async function requestPreview(): Promise<SavePreview | null> {
    if (!dirty.value) return null
    previewing.value = true
    error.value = null
    try {
      preview.value = await connection.request('settings.preview', { scope: scope.value, ...changeSet(staged.value) })
      return preview.value
    } catch (failure) {
      error.value = message(failure)
      return null
    } finally {
      previewing.value = false
    }
  }

  function closePreview(): void {
    preview.value = null
  }

  /** Write the previewed edit set in one save, then re-read where every value now comes from. */
  async function save(): Promise<SaveResult | null> {
    if (preview.value === null || !preview.value.canSave) return null
    saving.value = true
    error.value = null
    try {
      const result = await connection.request('settings.set', { scope: scope.value, ...changeSet(staged.value) })
      saved.value = result
      staged.value = {}
      preview.value = null
      await load()
      return result
    } catch (failure) {
      error.value = message(failure)
      return null
    } finally {
      saving.value = false
    }
  }

  return {
    rows,
    tiers,
    values,
    files,
    loaded,
    loading,
    error,
    scope,
    tier,
    staged,
    stagedCount,
    dirty,
    preview,
    previewing,
    saving,
    saved,
    load,
    row,
    editabilityOf,
    setScope,
    stage,
    stageReset,
    unstage,
    discard,
    requestPreview,
    closePreview,
    save,
  }
})
