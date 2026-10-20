<script setup lang="ts">
import { computed, ref, watch } from "vue";
import type { CommandInfo, Delivery } from "../stores/session";
import { loadPromptHistory, pushPromptHistory } from "../stores/promptHistory";
import {
    MAX_PASTED_IMAGES,
    readPastedImages,
    type PastedImage,
} from "../lib/paste";

const props = defineProps<{
    modelValue: string;
    busy: boolean;
    disabled?: boolean;
    commands: CommandInfo[];
    /** The delivery offered first while a turn runs — the server's `queueMode` (`steer` unless set). */
    defaultDelivery?: Delivery;
    /** `fs.search` behind the `@` popup (WEB-5b); without it the composer is text-only. */
    searchFiles?: (query: string) => Promise<string[]>;
}>();
const emit = defineEmits<{
    "update:modelValue": [value: string];
    send: [text: string, delivery: Delivery, images: PastedImage[]];
    stop: [];
}>();

const initialDelivery = (): Delivery => props.defaultDelivery ?? "steer";
const delivery = ref<Delivery>(initialDelivery());
const selected = ref(0);
const input = ref<HTMLTextAreaElement | null>(null);
let lastEscape = 0;

// While a turn runs the default is the server's `queueMode` — what the TUI's
// Enter does mid-turn (`steer` unless set); idle, a prompt just starts a turn.
watch(
    () => props.busy,
    (busy) => {
        if (!busy) delivery.value = initialDelivery();
    },
);
watch(
    () => props.defaultDelivery,
    () => {
        if (!props.busy) delivery.value = initialDelivery();
    },
);

/** `/` completion: the commands that can run here (server built-ins and command files). */
const suggestions = computed(() => {
    const match = /^\/([^\s]*)$/.exec(props.modelValue);
    if (!match) return [];
    const prefix = (match[1] ?? "").toLowerCase();
    return props.commands
        .filter((c) => c.runsIn === "server" || c.source === "file")
        .filter((c) =>
            c.name.replace(/^\//, "").toLowerCase().startsWith(prefix),
        )
        .slice(0, 8);
});

watch(suggestions, () => {
    selected.value = 0;
});

function complete(command: CommandInfo): void {
    emit("update:modelValue", `/${command.name.replace(/^\//, "")} `);
    input.value?.focus();
}

/** The images pasted since the last accepted send, as `session.send` wants them. */
const images = ref<PastedImage[]>([]);
/** Ranked `fs.search` matches for the trailing `@partial`, capped to one popup. */
const files = ref<string[]>([]);
let fileSeq = 0;

/**
 * The `@` completion rides the same keyboard as `/`: a trailing `@partial`
 * opens the file popup — unless the slash popup already owns the line.
 */
const mentionPartial = computed(() => {
    if (suggestions.value.length > 0) return null;
    const match = /(?:^|\s)@([^\s@]*)$/.exec(props.modelValue);
    return match ? (match[1] ?? "") : null;
});

watch(
    mentionPartial,
    async (partial) => {
        const seq = ++fileSeq;
        if (partial === null || !props.searchFiles) {
            files.value = [];
            return;
        }
        try {
            const found = await props.searchFiles(partial);
            if (seq === fileSeq) files.value = found.slice(0, 8);
        } catch {
            if (seq === fileSeq) files.value = [];
        }
    },
    { immediate: true },
);
watch(files, () => {
    selected.value = 0;
});

function completeFile(path: string): void {
    const at = props.modelValue.lastIndexOf("@");
    if (at < 0) return;
    emit("update:modelValue", `${props.modelValue.slice(0, at)}@${path} `);
    input.value?.focus();
}

async function onPaste(event: ClipboardEvent): Promise<void> {
    const pasted = await readPastedImages(event.clipboardData);
    if (pasted.length === 0) return;
    event.preventDefault();
    for (const image of pasted) {
        if (images.value.length < MAX_PASTED_IMAGES) images.value.push(image);
    }
}

/**
 * Server laws mirrored as a disabled button, so a refusal never appears as a
 * mystery: images never ride a `/command` or `!bang`, and never a delivery
 * that does not start a turn.
 */
const blockedReason = computed(() => {
    if (images.value.length === 0) return "";
    if (/^[!/]/.test(props.modelValue.trim()))
        return "Images ride a prose prompt; a /command or !bang takes none.";
    if (props.busy && delivery.value !== "interrupt")
        return "A turn is running — switch delivery to interrupt, or wait for it to end.";
    return "";
});

// The parent clears the draft only once the send was accepted; the chips ride
// that same edge, so a refused send keeps its paste for a retry.
watch(
    () => props.modelValue,
    (value, previous) => {
        if (value === "" && previous !== "") images.value = [];
    },
);

/** WEB-5a-i: the persisted ring (cap and dedupe live in `stores/promptHistory`). */
const history = ref<string[]>(loadPromptHistory());
/** -1 while typing a fresh draft; ≥0 indexes `history` from the newest entry. */
let historyIndex = -1;
/** The draft recall interrupted, restored when recall walks back to the head. */
let draftBackup = "";

function submit(): void {
    const text = props.modelValue.trim();
    if (text === "" || props.disabled || blockedReason.value !== "") return;
    // WEB-5a-i: the ring records what was sent, newest first (a failed
    // delivery keeps its prompt too, as in a shell — the prompt happened).
    history.value = pushPromptHistory(history.value, text);
    historyIndex = -1;
    emit(
        "send",
        text,
        props.busy ? delivery.value : initialDelivery(),
        images.value,
    );
}

/** Ctrl+Up walks older, Ctrl+Down walks newer and lands back on the draft. */
function recallHistory(older: boolean): boolean {
    if (history.value.length === 0) return false;
    if (!older && historyIndex === -1) return false;
    if (historyIndex === -1) draftBackup = props.modelValue;
    const next = older
        ? Math.min(historyIndex + 1, history.value.length - 1)
        : historyIndex - 1;
    if (next === historyIndex) return true; // already at the oldest end: consume the key, change nothing
    historyIndex = next;
    emit(
        "update:modelValue",
        historyIndex === -1 ? draftBackup : (history.value[historyIndex] ?? ""),
    );
    return true;
}

/** Typing after a recall drops the recall position; the draft is live again. */
function onInput(event: Event): void {
    historyIndex = -1;
    emit("update:modelValue", (event.target as HTMLTextAreaElement).value);
}

function onKeydown(event: KeyboardEvent): void {
    if (
        event.ctrlKey &&
        !event.metaKey &&
        suggestions.value.length === 0 &&
        (event.key === "ArrowUp" || event.key === "ArrowDown") &&
        recallHistory(event.key === "ArrowUp")
    ) {
        event.preventDefault();
        return;
    }
    const commands = suggestions.value.length;
    const popup = commands > 0 ? commands : files.value.length;
    if (
        popup > 0 &&
        !event.ctrlKey &&
        !event.metaKey &&
        (event.key === "ArrowDown" || event.key === "ArrowUp")
    ) {
        event.preventDefault();
        selected.value =
            (selected.value + (event.key === "ArrowDown" ? 1 : popup - 1)) %
            popup;
        return;
    }
    if (popup > 0 && event.key === "Tab") {
        event.preventDefault();
        if (commands > 0) {
            const pick = suggestions.value[selected.value];
            if (pick) complete(pick);
        } else {
            const pick = files.value[selected.value];
            if (pick) completeFile(pick);
        }
        return;
    }
    if (event.key === "Enter" && !event.shiftKey && !event.isComposing) {
        event.preventDefault();
        if (commands === 0 && files.value.length > 0) {
            // Accept the highlighted file: unlike `/name`, a half-typed `@partial`
            // is never a sendable prompt.
            const pick = files.value[selected.value];
            if (pick) completeFile(pick);
            return;
        }
        submit();
        return;
    }
    if (event.key === "Escape") {
        // Esc Esc stops the running turn, as in the TUI.
        const now = Date.now();
        if (props.busy && now - lastEscape < 600) {
            lastEscape = 0;
            emit("stop");
        } else {
            lastEscape = now;
        }
    }
}

defineExpose({ focus: () => input.value?.focus() });
</script>

<template>
    <div class="composer">
        <ul
            v-if="suggestions.length > 0"
            class="suggest"
            role="listbox"
            data-testid="command-suggestions"
        >
            <li
                v-for="(command, i) in suggestions"
                :key="command.name"
                role="option"
                :aria-selected="i === selected"
                :class="{ on: i === selected }"
                @mousedown.prevent="complete(command)"
            >
                <span class="cmd">/{{ command.name.replace(/^\//, "") }}</span>
                <span v-if="command.argumentHint" class="hint">{{
                    command.argumentHint
                }}</span>
                <span class="desc">{{ command.description }}</span>
            </li>
        </ul>
        <ul
            v-if="suggestions.length === 0 && files.length > 0"
            class="suggest"
            role="listbox"
            data-testid="file-suggestions"
        >
            <li
                v-for="(file, i) in files"
                :key="file"
                role="option"
                :aria-selected="i === selected"
                :class="{ on: i === selected }"
                @mousedown.prevent="completeFile(file)"
            >
                <span class="cmd">{{ file }}</span>
            </li>
        </ul>
        <div class="entry">
            <div
                v-if="images.length > 0"
                class="chips"
                data-testid="paste-chips"
            >
                <figure
                    v-for="(image, i) in images"
                    :key="`${image.name}-${i}`"
                    class="chip"
                >
                    <img
                        :src="`data:${image.mime};base64,${image.data}`"
                        :alt="image.name"
                    />
                    <button
                        type="button"
                        class="remove"
                        :aria-label="`Remove ${image.name}`"
                        data-testid="paste-chip-remove"
                        @click="images.splice(i, 1)"
                    >
                        ×
                    </button>
                </figure>
            </div>
            <textarea
                ref="input"
                :value="modelValue"
                rows="3"
                :disabled="disabled"
                :placeholder="
                    busy
                        ? 'Type to queue, steer or interrupt…'
                        : 'Message CandyCode… (Enter sends, Shift+Enter for a new line, / for commands, @ for files, paste images, Ctrl+↑ history)'
                "
                aria-label="Message"
                data-testid="composer-input"
                @input="onInput"
                @keydown="onKeydown"
                @paste="onPaste"
            />
        </div>
        <div class="controls">
            <select
                v-if="busy"
                v-model="delivery"
                aria-label="Delivery"
                data-testid="composer-delivery"
            >
                <option value="queue">queue</option>
                <option value="steer">steer</option>
                <option value="interrupt">interrupt</option>
            </select>
            <button
                type="button"
                class="primary"
                :disabled="
                    disabled || modelValue.trim() === '' || blockedReason !== ''
                "
                :title="blockedReason"
                data-testid="composer-send"
                @click="submit"
            >
                {{
                    busy
                        ? delivery === "queue"
                            ? "Queue"
                            : delivery === "steer"
                              ? "Steer"
                              : "Interrupt"
                        : "Send"
                }}
            </button>
            <button
                v-if="busy"
                type="button"
                class="danger"
                data-testid="composer-stop"
                title="Stop the turn (Esc Esc)"
                @click="emit('stop')"
            >
                Stop
            </button>
        </div>
    </div>
</template>

<style scoped>
.composer {
    position: relative;
    display: flex;
    gap: 0.5rem;
    align-items: flex-end;
}
.entry {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
}
textarea {
    width: 100%;
    min-width: 0;
    resize: vertical;
    min-height: 3.2rem;
    max-height: 40vh;
    padding: 0.5rem 0.6rem;
    font: inherit;
    color: var(--text);
    background: var(--panel);
    border: 1px solid var(--line);
    border-radius: 6px;
}
textarea:focus {
    outline: 2px solid var(--accent);
    outline-offset: -1px;
}
.controls {
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
}
.chips {
    display: flex;
    flex-wrap: wrap;
    gap: 0.4rem;
}
.chip {
    position: relative;
    margin: 0;
}
.chip img {
    display: block;
    width: 3.2rem;
    height: 3.2rem;
    object-fit: cover;
    border: 1px solid var(--line);
    border-radius: 6px;
}
.chip .remove {
    position: absolute;
    top: -0.4rem;
    right: -0.4rem;
    width: 1.15rem;
    height: 1.15rem;
    padding: 0;
    line-height: 1;
    border-radius: 50%;
    border: 1px solid var(--line);
    background: var(--panel);
    color: var(--text);
    cursor: pointer;
}
.suggest {
    position: absolute;
    left: 0;
    bottom: 100%;
    margin: 0 0 0.25rem;
    padding: 0.25rem 0;
    list-style: none;
    background: var(--panel);
    border: 1px solid var(--line);
    border-radius: 6px;
    max-width: 100%;
    z-index: 5;
}
.suggest li {
    display: flex;
    gap: 0.6rem;
    padding: 0.25rem 0.6rem;
    cursor: pointer;
    white-space: nowrap;
    overflow: hidden;
}
.suggest li.on {
    background: var(--line);
}
.cmd {
    font-family: var(--mono);
}
.hint,
.desc {
    color: var(--muted);
    overflow: hidden;
    text-overflow: ellipsis;
}
@media (max-width: 600px) {
    .composer {
        flex-direction: column;
        align-items: stretch;
    }
    .controls {
        flex-direction: row;
        justify-content: flex-end;
    }
}
</style>
