<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from "vue";
import { useRoute } from "vue-router";
import HistorySearch from "./HistorySearch.vue";
import type { CommandInfo, Delivery } from "../stores/session";
import { useSessionStore } from "../stores/session";
import { useConnectionStore } from "../stores/connection";
import {
    loadPromptHistory,
    onPromptHistoryCleared,
    pushPromptHistory,
} from "../stores/promptHistory";
import {
    documentsFromFiles,
    expandPasteChips,
    imagesFromFiles,
    MAX_PASTED_DOCUMENTS,
    MAX_PASTED_IMAGES,
    pasteChip,
    pasteNeedsCollapse,
    readDroppedDocuments,
    readPastedImages,
    type PastedDocument,
    type PastedImage,
} from "../lib/paste";
import {
    createDictation,
    dictationNoticeFor,
    dictationSupported,
    type Dictation,
} from "../lib/dictation";

/**
 * C-MCP-2.c: one row of the `@mcp:` popup. Accepting inserts the keyword
 * form `@mcp:<server>:<uri>` the server already resolves; the roster fetch
 * and its cache live in `SessionView`, which owns the connection.
 */
export type McpMention = { server: string; uri: string; name: string };

const props = defineProps<{
    modelValue: string;
    busy: boolean;
    disabled?: boolean;
    commands: CommandInfo[];
    /** The delivery offered first while a turn runs — the server's `queueMode` (`steer` unless set). */
    defaultDelivery?: Delivery;
    /** `fs.search` behind the `@` popup (WEB-5b); without it the composer is text-only. */
    searchFiles?: (query: string) => Promise<string[]>;
    /**
     * C-MCP-2.c: the `mcp.resources` roster behind an `@mcp:` partial. The
     * query is the partial after `mcp:`; the rows come filtered by
     * server/uri/name. Without it the `@` popup is file-only, as before.
     */
    searchMcpResources?: (query: string) => Promise<McpMention[]>;
    agentRoster?: () => string[];
}>();
const emit = defineEmits<{
    "update:modelValue": [value: string];
    send: [text: string, delivery: Delivery, images: PastedImage[], documents: PastedDocument[]];
    stop: [];
}>();

// C-CTX-2.d: the ghost prompt. A served `prompt.suggested` lives on the
// session store; a mount without a routed session (no id, or no pinia owner
// for one) simply never has an offer to show.
const route = useRoute();
const sessionId = computed(() =>
    typeof route?.params?.id === "string" ? route.params.id : "",
);
const suggestion = computed<string | null>(() =>
    sessionId.value === ""
        ? null
        : useSessionStore(sessionId.value).state.suggestedPrompt,
);

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
/**
 * C-WEB-4.e: the documents (PDFs) dropped or picked since the last accepted
 * send, as `session.send`'s `documents` wants them. The same strip law as
 * images: capped at the server's count, cleared only on an accepted send.
 */
const documents = ref<PastedDocument[]>([]);
/** The hidden `<input type=file>` behind the Attach button (C-WEB-4.e). */
const fileInput = ref<HTMLInputElement | null>(null);
/** Ranked `fs.search` matches for the trailing `@partial`, capped to one popup. */
const files = ref<string[]>([]);
/** Ranked `mcp.resources` rows for a trailing `@mcp:` partial (C-MCP-2.c). */
const mcpMentions = ref<McpMention[]>([]);
const agentMentions = ref<string[]>([]);
let fileSeq = 0;

/** C-MCP-2.c: an `@mcp:` partial opens the resource popup, not the file one. */
const MCP_MENTION_PREFIX = "mcp:";

/** C-AGT-4.d: an `@agent:` partial opens the roster popup, the same trick. */
const AGENT_MENTION_PREFIX = "agent:";

/**
 * The `@` completion rides the same keyboard as `/`: a trailing `@partial`
 * opens the file popup — unless the slash popup already owns the line.
 */
const mentionPartial = computed(() => {
    if (suggestions.value.length > 0) return null;
    const match = /(?:^|\s)@([^\s@]*)$/.exec(props.modelValue);
    return match ? (match[1] ?? "") : null;
});

/**
 * WEB-5-P3 (client half): the `@` popup used to fire a synchronous `fs.search`
 * on the shared serve loop for every keystroke after `@`. This is a leading +
 * trailing debounce: a partial arriving more than MENTION_SEARCH_DEBOUNCE_MS
 * after the last search runs at once — the first `@` still opens instantly —
 * while partials inside the window collapse to one trailing search carrying
 * the newest partial. A null partial cancels the pending timer and resets the
 * window. The `fileSeq` latch (a late answer never overwrites a newer one)
 * stays; the timer is cleared on unmount.
 */
const MENTION_SEARCH_DEBOUNCE_MS = 120;
let mentionTimer: ReturnType<typeof setTimeout> | undefined;
let lastSearchAt = Number.NEGATIVE_INFINITY;

function searchMentionedFiles(partial: string): void {
    const seq = ++fileSeq;
    mcpMentions.value = []; // the file popup has taken the line back
    agentMentions.value = [];
    lastSearchAt = Date.now();
    void props
        .searchFiles!(partial)
        .then((found) => {
            if (seq === fileSeq) files.value = found.slice(0, 8);
        })
        .catch(() => {
            if (seq === fileSeq) files.value = [];
        });
}

/**
 * C-MCP-2.c: the `@mcp:` popup. Rows come from the session-cached
 * `mcp.resources` roster, filtered by the caller. No rows — `not-started`,
 * no servers, a failed fetch — hands the line to the file search the
 * partial would have run before this feature existed.
 */
function searchMcpMentions(partial: string): void {
    const seq = ++fileSeq;
    files.value = [];
    lastSearchAt = Date.now();
    void props
        .searchMcpResources!(partial.slice(MCP_MENTION_PREFIX.length))
        .then((rows) => {
            if (seq !== fileSeq) return;
            mcpMentions.value = rows.slice(0, 8);
            if (mcpMentions.value.length === 0 && props.searchFiles)
                searchMentionedFiles(partial);
        })
        .catch(() => {
            if (seq !== fileSeq) return;
            mcpMentions.value = [];
            if (props.searchFiles) searchMentionedFiles(partial);
        });
}

/**
 * C-AGT-4.d (client-only): the `@agent:` popup. The names are the agents
 * store's launch roster the StatusBar already fetched — no second wire call
 * per keystroke — filtered and sorted right here. Empty rows — before the
 * catalogue lands, or a roster with no match — hand the line back to the
 * file search, exactly as the `@mcp:` popup does.
 */
function searchAgentMentions(partial: string): void {
    const seq = ++fileSeq;
    files.value = [];
    mcpMentions.value = [];
    lastSearchAt = Date.now();
    const query = partial.slice(AGENT_MENTION_PREFIX.length).toLowerCase();
    const names = (props.agentRoster?.() ?? [])
        .filter((name) => name.toLowerCase().includes(query))
        .sort()
        .slice(0, 8);
    agentMentions.value = names;
    if (names.length === 0 && props.searchFiles) searchMentionedFiles(partial);
}

/** Route a partial to an `@agent:`/`@mcp:` roster popup or the `fs.search` file popup. */
function runMentionSearch(partial: string): void {
    if (partial.startsWith(AGENT_MENTION_PREFIX) && props.agentRoster)
        searchAgentMentions(partial);
    else if (partial.startsWith(MCP_MENTION_PREFIX) && props.searchMcpResources)
        searchMcpMentions(partial);
    else if (props.searchFiles) searchMentionedFiles(partial);
}

watch(
    mentionPartial,
    (partial) => {
        if (mentionTimer !== undefined) {
            clearTimeout(mentionTimer);
            mentionTimer = undefined;
        }
        if (
            partial === null ||
            (!props.searchFiles &&
                !props.searchMcpResources &&
                !props.agentRoster)
        ) {
            files.value = [];
            mcpMentions.value = [];
            agentMentions.value = [];
            lastSearchAt = Number.NEGATIVE_INFINITY; // the next `@` opens at once
            return;
        }
        if (Date.now() - lastSearchAt >= MENTION_SEARCH_DEBOUNCE_MS) {
            runMentionSearch(partial);
            return;
        }
        mentionTimer = setTimeout(() => {
            mentionTimer = undefined;
            runMentionSearch(partial);
        }, MENTION_SEARCH_DEBOUNCE_MS);
    },
    { immediate: true },
);
onBeforeUnmount(() => {
    if (mentionTimer !== undefined) clearTimeout(mentionTimer);
});
watch([files, mcpMentions, agentMentions], () => {
    selected.value = 0;
});

function completeFile(path: string): void {
    const at = props.modelValue.lastIndexOf("@");
    if (at < 0) return;
    emit("update:modelValue", `${props.modelValue.slice(0, at)}@${path} `);
    input.value?.focus();
}

/** C-MCP-2.c: accepting an `@mcp:` row inserts the server-resolvable keyword. */
function completeMcp(mention: McpMention): void {
    const at = props.modelValue.lastIndexOf("@");
    if (at < 0) return;
    emit(
        "update:modelValue",
        `${props.modelValue.slice(0, at)}@mcp:${mention.server}:${mention.uri} `,
    );
    input.value?.focus();
}

/**
 * C-AGT-4.d: accepting an agent row inserts the keyword the server's mention
 * scanner reserves — the same `@agent:<name>` text a TUI user completes, so
 * the one resolver decides the name in every front end.
 */
function completeAgent(name: string): void {
    const at = props.modelValue.lastIndexOf("@");
    if (at < 0) return;
    emit(
        "update:modelValue",
        `${props.modelValue.slice(0, at)}@agent:${name} `,
    );
    input.value?.focus();
}

/**
 * File of the long text pastes this draft has collapsed to chips
 * (C-WEB-4.a), chip text → payload, in paste order. The same machine the
 * TUI runs for ISS-051: the placeholder rides the draft, the payload waits
 * here, and `expandPasteChips()` puts it back at submit — the only reader
 * that treats the draft as sent text.
 */
const textChips = new Map<string, string>();
let pasteCount = 0;

function addImages(pasted: PastedImage[]): void {
    for (const image of pasted) {
        if (images.value.length < MAX_PASTED_IMAGES) images.value.push(image);
    }
}

function addDocuments(dropped: PastedDocument[]): void {
    for (const doc of dropped) {
        if (documents.value.length < MAX_PASTED_DOCUMENTS)
            documents.value.push(doc);
    }
}

/**
 * C-WEB-4.e: the Attach button opens a plain file picker; its answer is a
 * `FileList`, not clipboard items, so the kind filters convert through the
 * paste module's picker halves. Resetting `value` lets the very same file be
 * picked again after the user removes its chip.
 */
function pickFiles(): void {
    fileInput.value?.click();
}

async function onFilesPicked(event: Event): Promise<void> {
    const el = event.target as HTMLInputElement;
    const picked = el.files ? Array.from(el.files) : [];
    el.value = "";
    if (picked.length === 0) return;
    addImages(await imagesFromFiles(picked));
    addDocuments(await documentsFromFiles(picked));
}

/**
 * Insert at the caret, like the dictation insert: the parent owns the draft,
 * so the chip text leaves as an `update:modelValue` the way any keystroke does.
 */
function insertAtCaret(text: string): void {
    const value = props.modelValue;
    const el = input.value;
    if (el === null) {
        emit("update:modelValue", value + text);
        return;
    }
    const start = el.selectionStart ?? value.length;
    const end = el.selectionEnd ?? value.length;
    emit("update:modelValue", value.slice(0, start) + text + value.slice(end));
    input.value?.focus();
}

async function onPaste(event: ClipboardEvent): Promise<void> {
    const pasted = await readPastedImages(event.clipboardData);
    // C-WEB-4.e: a PDF on the clipboard is a document, not a skipped image.
    const dropped = await readDroppedDocuments(event.clipboardData);
    if (pasted.length > 0 || dropped.length > 0) {
        event.preventDefault();
        addImages(pasted);
        addDocuments(dropped);
        return;
    }
    // C-WEB-4.a: the TUI's collapse, client-side. A paste over the chip
    // thresholds is filed under a one-line placeholder instead of flooding
    // the textarea; short text still falls through to the browser's insert.
    // The `getData` duck-check keeps fixtures that only model image items
    // (the older paste tests) falling through untouched.
    const data = event.clipboardData as DataTransfer | null | undefined
    if (typeof data?.getData !== "function") return;
    const text = data.getData("text/plain");
    if (text === "" || !pasteNeedsCollapse(text)) return;
    event.preventDefault();
    const chip = pasteChip(++pasteCount, text.split("\n").length);
    textChips.set(chip, text);
    insertAtCaret(chip);
}

/**
 * C-WEB-4.a: dropping files over the composer is a paste that arrived by
 * mouse — a DataTransfer satisfies `ClipboardFiles` structurally, so the
 * drop shares the paste's reader, the MAX_PASTED_IMAGES strip, and (through
 * the image chips) the same busy/command refusals on send. C-WEB-4.e adds
 * the document twin: the same drop fills the PDF strip under its own cap.
 *
 * Only a Files drag is ours to claim. Cancelling every drop would silently
 * break the browser's native text-drag into the textarea, so the types
 * guard answers synchronously, before any await, and a text/plain drag
 * falls through unpreventended for the browser to insert.
 */
async function onDrop(event: DragEvent): Promise<void> {
    if (!event.dataTransfer?.types?.includes("Files")) return;
    event.preventDefault();
    // C-WEB-4.e: the same drop feeds both strips — images through the paste
    // reader, PDFs through the document reader, each capped independently,
    // exactly how the server counts the two arrays.
    const [pasted, dropped] = await Promise.all([
        readPastedImages(event.dataTransfer),
        readDroppedDocuments(event.dataTransfer),
    ]);
    if (pasted.length === 0 && dropped.length === 0) return;
    addImages(pasted);
    addDocuments(dropped);
}

/**
 * Server laws mirrored as a disabled button, so a refusal never appears as a
 * mystery: attachments never ride a `/command` or `!bang` (the images leg
 * answers first, as the server checks it first), and never a delivery that
 * does not start a turn.
 */
const blockedReason = computed(() => {
    if (images.value.length === 0 && documents.value.length === 0) return "";
    if (/^[!/]/.test(props.modelValue.trim())) {
        if (images.value.length === 0)
            return "Documents ride a prose prompt; a /command or !bang takes none.";
        return "Images ride a prose prompt; a /command or !bang takes none.";
    }
    if (props.busy && delivery.value !== "interrupt")
        return "A turn is running — switch delivery to interrupt, or wait for it to end.";
    return "";
});

// The parent clears the draft only once the send was accepted; the chips ride
// that same edge, so a refused send keeps its paste for a retry.
watch(
    () => props.modelValue,
    (value, previous) => {
        if (value === "" && previous !== "") {
            images.value = [];
            documents.value = [];
        }
        // C-WEB-4.a: a placeholder the user deleted is no longer a chip —
        // its payload is dropped with it, so re-typing the same text later
        // is just typing, never a resurrection of the filed paste.
        for (const chip of [...textChips.keys()]) {
            if (!value.includes(chip)) textChips.delete(chip);
        }
    },
);

/** WEB-5a-i: the persisted ring (cap and dedupe live in `stores/promptHistory`). */
const history = ref<string[]>(loadPromptHistory());
/** -1 while typing a fresh draft; ≥0 indexes `history` from the newest entry. */
let historyIndex = -1;
/** The draft recall interrupted, restored when recall walks back to the head. */
let draftBackup = "";

/**
 * WEB-6-P1: the ring was loaded once and lived only here, so a clear made in
 * another tab (sign-out, DevTools, the palette) left this copy standing — and
 * the next send re-persisted the whole pre-clear history from memory. The
 * subscription drops the local copy the moment the stored ring is erased,
 * here or anywhere; unsubscribed on unmount.
 */
const stopHistoryCleared = onPromptHistoryCleared(() => {
    history.value = [];
    historyIndex = -1;
    draftBackup = "";
});
onBeforeUnmount(stopHistoryCleared);

function submit(): void {
    const text = props.modelValue.trim();
    if (text === "" || props.disabled || blockedReason.value !== "") return;
    // C-CTX-3.c: a `#` draft is answered by the quick-add offer, not sent.
    if (answerQuickAdd()) return;
    // C-WEB-4.a: the wire and the ring carry the expanded text — the model
    // never sees a placeholder, same reader law as the TUI's submit arm.
    const sent = expandPasteChips(text, textChips);
    // WEB-5a-i: the ring records what was sent, newest first (a failed
    // delivery keeps its prompt too, as in a shell — the prompt happened).
    history.value = pushPromptHistory(history.value, sent);
    historyIndex = -1;
    emit(
        "send",
        sent,
        props.busy ? delivery.value : initialDelivery(),
        images.value,
        documents.value,
    );
}

/**
 * C-WEB-4.a: Ctrl+G opens the history search over the same ring Ctrl+↑/↓
 * walks, seeded with the draft so Escape can put it back — the TUI rule that
 * the draft the user is typing is not fuel for the search and never moves
 * while it is up. With nothing to search the chord is not consumed, exactly
 * as `Chat::buildHistorySearch()` answers null.
 */
const searchOpen = ref(false);
let searchSeed = "";

function openHistorySearch(): void {
    if (history.value.length === 0) return;
    searchSeed = props.modelValue;
    searchOpen.value = true;
}

function onHistoryAccept(entry: string): void {
    searchOpen.value = false;
    historyIndex = -1;
    emit("update:modelValue", entry);
    input.value?.focus();
}

function onHistoryClose(): void {
    searchOpen.value = false;
    // Restore the seed verbatim (the overlay never wrote the draft, so this
    // is the TUI's Escape arm applied — the draft is the user's again).
    emit("update:modelValue", searchSeed);
    input.value?.focus();
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

/**
 * C-TUI-6.k: Web Speech dictation. The mic appears only where the browser
 * has the API and the page is a secure context; the audio is heard by the
 * BROWSER vendor's speech service and never reaches the candy-code server
 * (the button's title says so). Interim text shows as a dim hint under the
 * textarea; a final utterance is inserted at the caret — dictation never
 * sends on its own. A lone Esc stops it, like the TUI's key.
 */
const canDictate = ref(dictationSupported(window));
const listening = ref(false);
const interim = ref("");
const dictationNotice = ref("");
let dictation: Dictation | null = null;

/** The final text lands between the caret's ends; the parent owns the draft. */
function insertDictated(text: string): void {
    const value = props.modelValue;
    const el = input.value;
    if (el === null) {
        emit("update:modelValue", value + text);
        return;
    }
    const start = el.selectionStart ?? value.length;
    const end = el.selectionEnd ?? value.length;
    emit("update:modelValue", value.slice(0, start) + text + value.slice(end));
}

function stopDictation(): void {
    listening.value = false;
    interim.value = "";
    dictation?.stop();
}

function toggleDictation(): void {
    if (listening.value) {
        stopDictation();
        return;
    }
    dictationNotice.value = "";
    interim.value = "";
    // Explicit window: the component and its tests share this one object.
    dictation = createDictation(
        {
            lang: navigator.language,
            onInterim: (text) => {
                interim.value = text;
            },
            onFinal: (text) => {
                interim.value = "";
                insertDictated(text);
            },
            onError: (code) => {
                dictationNotice.value = dictationNoticeFor(code);
                if (code === "already-running") {
                    // The session died before it ever ran; the mic must not
                    // stay lit while the notice says it is already running.
                    listening.value = false;
                }
            },
            onEnd: () => {
                listening.value = false;
                interim.value = "";
            },
        },
        window,
    );
    if (dictation === null) {
        // Detection said yes, the platform said no: drop the button.
        canDictate.value = false;
        return;
    }
    listening.value = true;
    dictation.start();
}

onBeforeUnmount(() => {
    dictation?.stop();
});

function onKeydown(event: KeyboardEvent): void {
    if (event.key === "Escape" && listening.value) {
        // While dictation runs, a lone Esc is its stop key (C-TUI-6.k).
        event.preventDefault();
        stopDictation();
        return;
    }
    if (
        event.ctrlKey &&
        !event.metaKey &&
        !searchOpen.value &&
        history.value.length > 0 &&
        event.key.toLowerCase() === "g"
    ) {
        // C-WEB-4.a: the Ctrl+G search chord (browsers reserve Ctrl+R, so
        // this surface lands on the TUI's Ctrl+G); while the overlay is up
        // it owns the keyboard itself.
        event.preventDefault();
        openHistorySearch();
        return;
    }
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
    const agentRows = agentMentions.value.length;
    const mcpRows = mcpMentions.value.length;
    const popup =
        commands > 0
            ? commands
            : agentRows > 0
              ? agentRows
              : mcpRows > 0
                ? mcpRows
                : files.value.length;
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
        } else if (agentRows > 0) {
            const pick = agentMentions.value[selected.value];
            if (pick) completeAgent(pick);
        } else if (mcpRows > 0) {
            const pick = mcpMentions.value[selected.value];
            if (pick) completeMcp(pick);
        } else {
            const pick = files.value[selected.value];
            if (pick) completeFile(pick);
        }
        return;
    }
    if (
        popup === 0 &&
        !event.ctrlKey &&
        !event.metaKey &&
        !event.altKey &&
        props.modelValue === "" &&
        suggestion.value !== null &&
        (event.key === "ArrowRight" || event.key === "Tab")
    ) {
        // C-CTX-2.d: an empty draft and a ghost offer — → (or Tab, the TUI's
        // accept chord) fills the draft and retires the offer exactly once.
        // Read the offer before retiring it: the store is reactive, and a
        // clear-then-read would emit null.
        event.preventDefault();
        const offer = suggestion.value;
        if (sessionId.value !== "")
            useSessionStore(sessionId.value).state.suggestedPrompt = null;
        emit("update:modelValue", offer);
        return;
    }
    if (event.key === "Enter" && !event.shiftKey && !event.isComposing) {
        event.preventDefault();
        if (
            commands === 0 &&
            (agentRows > 0 || mcpRows > 0 || files.value.length > 0)
        ) {
            // Accept the highlighted row: unlike `/name`, a half-typed
            // `@partial` — file, `mcp:` or `agent:` — is never a sendable
            // prompt.
            const agent =
                agentRows > 0 ? agentMentions.value[selected.value] : undefined;
            if (agent !== undefined) {
                completeAgent(agent);
                return;
            }
            const pick =
                mcpRows > 0
                    ? mcpMentions.value[selected.value]
                    : undefined;
            if (pick !== undefined) {
                completeMcp(pick);
                return;
            }
            const file = files.value[selected.value];
            if (file) completeFile(file);
            return;
        }
        submit();
        return;
    }
    if (event.key === "Escape") {
        if (quickAdd.value !== null) {
            // C-CTX-3.c: the offer answers Escape first — the draft never
            // left the textarea, so retiring the chip is the restore.
            event.preventDefault();
            cancelQuickAdd();
            return;
        }
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

/**
 * C-CTX-3.c: the web twin of the TUI's `#` quick-add. A single-line draft
 * starting with `#` (not `##`, not bare) raises a confirm chip instead of a
 * turn — confirming appends `- text\n` to the personal file through the
 * served `context.instructionWrite`, the same write the Context panel's Edit
 * uses, so the note reaches the model as an instruction, never as prompt
 * text. Escape or Cancel retires the chip and the draft is untouched (it
 * never left the textarea — the TUI's Escape-restore in web shape); a send
 * with an edited draft re-evaluates the shape. While a turn runs the offer
 * is never raised, and attachments never ride one: busy text belongs to the
 * queue and a note is prose, exactly as in the TUI.
 */
const quickAdd = ref<{ line: string } | null>(null);
const quickAddBusy = ref(false);
const quickAddError = ref<string | null>(null);

function quickAddLine(value: string): string | null {
    const text = value.trim();
    if (!text.startsWith("#") || text.startsWith("##") || text.includes("\n")) return null;
    const body = text.slice(1).trim();
    if (body === "") return null;
    return `- ${body}\n`;
}

/** Returns true when the send was consumed as (or answered by) the offer. */
function answerQuickAdd(): boolean {
    if (quickAddBusy.value) return true;
    if (quickAdd.value !== null) {
        // Round-1 fix (MINOR-3): an offer is answered from the DRAFT, not
        // from the moment the chip was raised. Re-derive the line against the
        // current modelValue so an edited draft appends the EDITED line —
        // the stale offer used to win and then clear the edit. A draft the
        // `#` shape no longer describes retires the chip and the Enter goes
        // through as a plain send, exactly as the block doc above promises.
        const fresh = quickAddLine(props.modelValue);
        if (fresh === null) {
            cancelQuickAdd();
            return false;
        }
        quickAdd.value = { line: fresh };
        void confirmQuickAdd();
        return true;
    }
    if (props.busy || sessionId.value === "") return false;
    if (images.value.length > 0 || documents.value.length > 0) return false;
    const line = quickAddLine(props.modelValue);
    if (line === null) return false;
    quickAdd.value = { line };
    quickAddError.value = null;
    return true;
}

async function confirmQuickAdd(): Promise<void> {
    const offer = quickAdd.value;
    if (offer === null || quickAddBusy.value || sessionId.value === "") return;
    quickAddBusy.value = true;
    quickAddError.value = null;
    try {
        await useConnectionStore().request("context.instructionWrite", {
            sessionId: sessionId.value,
            path: "personal",
            append: offer.line,
        });
        quickAdd.value = null;
        emit("update:modelValue", "");
    } catch (failure) {
        quickAddError.value = failure instanceof Error ? failure.message : String(failure);
    } finally {
        quickAddBusy.value = false;
    }
}

function cancelQuickAdd(): void {
    quickAdd.value = null;
    quickAddError.value = null;
}

defineExpose({ focus: () => input.value?.focus() });
</script>

<template>
    <div
        class="composer"
        data-testid="composer-root"
        @dragover.prevent
        @drop="onDrop"
    >
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
            v-if="suggestions.length === 0 && mcpMentions.length > 0"
            class="suggest"
            role="listbox"
            data-testid="mcp-suggestions"
        >
            <li
                v-for="(mention, i) in mcpMentions"
                :key="`${mention.server}:${mention.uri}`"
                role="option"
                :aria-selected="i === selected"
                :class="{ on: i === selected }"
                @mousedown.prevent="completeMcp(mention)"
            >
                <span class="cmd">{{ `mcp:${mention.server}:${mention.uri}` }}</span>
                <span v-if="mention.name" class="desc">{{ mention.name }}</span>
            </li>
        </ul>
        <ul
            v-if="suggestions.length === 0 && agentMentions.length > 0"
            class="suggest"
            role="listbox"
            data-testid="agent-suggestions"
        >
            <li
                v-for="(name, i) in agentMentions"
                :key="name"
                role="option"
                :aria-selected="i === selected"
                :class="{ on: i === selected }"
                @mousedown.prevent="completeAgent(name)"
            >
                <span class="cmd">{{ `agent:${name}` }}</span>
                <span class="desc">delegate this prompt</span>
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
            <div
                v-if="documents.length > 0"
                class="chips"
                data-testid="document-chips"
            >
                <span
                    v-for="(doc, i) in documents"
                    :key="`${doc.name}-${i}`"
                    class="chip doc-chip"
                >
                    <span class="doc-name" :title="doc.name">{{ doc.name }}</span>
                    <button
                        type="button"
                        class="remove"
                        :aria-label="`Remove ${doc.name}`"
                        data-testid="document-chip-remove"
                        @click="documents.splice(i, 1)"
                    >
                        ×
                    </button>
                </span>
            </div>
            <textarea
                ref="input"
                :value="modelValue"
                rows="3"
                :disabled="disabled"
                :placeholder="
                    busy
                        ? 'Type to queue, steer or interrupt…'
                        : (suggestion ??
                            'Message CandyCode… (Enter sends, Shift+Enter for a new line, / for commands, @ for files, paste or drop images and PDFs, Ctrl+↑ history, Ctrl+G search)')
                "
                aria-label="Message"
                data-testid="composer-input"
                @input="onInput"
                @keydown="onKeydown"
                @paste="onPaste"
            />
            <div
                v-if="interim !== ''"
                class="dictation-hint"
                aria-live="polite"
                data-testid="composer-dictation-hint"
            >
                {{ interim }}
            </div>
            <div
                v-if="dictationNotice !== ''"
                class="dictation-notice"
                aria-live="polite"
                data-testid="composer-dictation-notice"
            >
                {{ dictationNotice }}
            </div>
            <div
                v-if="quickAdd !== null"
                class="quick-add"
                aria-live="polite"
                data-testid="composer-quick-add"
            >
                <span class="quick-add-note"
                    >Add to your personal instructions:
                    {{ quickAdd.line.trim() }}</span
                >
                <button
                    type="button"
                    data-testid="composer-quick-add-confirm"
                    :disabled="quickAddBusy"
                    @click="confirmQuickAdd"
                >
                    Add (Enter)
                </button>
                <button
                    type="button"
                    data-testid="composer-quick-add-cancel"
                    @click="cancelQuickAdd"
                >
                    Cancel (Esc)
                </button>
                <span
                    v-if="quickAddError"
                    class="quick-add-error"
                    role="alert"
                    data-testid="composer-quick-add-error"
                    >{{ quickAddError }}</span
                >
            </div>
        </div>
        <div class="controls">
            <button
                type="button"
                class="attach"
                aria-label="Attach files"
                title="Attach images or PDFs to this prompt"
                data-testid="composer-attach"
                @click="pickFiles"
            >
                Attach
            </button>
            <input
                ref="fileInput"
                type="file"
                multiple
                accept="application/pdf,image/*"
                tabindex="-1"
                data-testid="composer-file-input"
                class="file-input"
                @change="onFilesPicked"
            />
            <button
                v-if="canDictate"
                type="button"
                class="mic"
                :class="{ live: listening }"
                :aria-pressed="listening"
                :aria-label="listening ? 'Stop dictation' : 'Dictate'"
                title="Dictate — your browser's speech service hears this audio; the candy-code server does not."
                data-testid="composer-mic"
                @click="toggleDictation"
            >
                {{ listening ? "Dictating…" : "Dictate" }}
            </button>
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
        <HistorySearch
            v-if="searchOpen"
            :entries="history"
            @accept="onHistoryAccept"
            @close="onHistoryClose"
        />
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
/* C-WEB-4.e: the document chip is the same corner-close machine, a name
   instead of a thumbnail. */
.doc-chip {
    display: flex;
    gap: 0.4rem;
    align-items: center;
    max-width: 100%;
    padding: 0.25rem 0.6rem;
}
.doc-name {
    max-width: 14rem;
    overflow: hidden;
    font-family: var(--mono);
    white-space: nowrap;
    text-overflow: ellipsis;
}
.file-input {
    display: none;
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
.mic.live {
    border-color: var(--error);
    color: var(--error);
}
.dictation-hint {
    color: var(--muted);
    font-style: italic;
    overflow-wrap: anywhere;
}
.dictation-notice {
    color: var(--error);
}
.quick-add {
    display: flex;
    flex-wrap: wrap;
    gap: 0.4rem;
    align-items: baseline;
    border: 1px solid var(--accent);
    border-radius: 6px;
    padding: 0.3rem 0.5rem;
}
.quick-add-note {
    flex: 1 1 auto;
    overflow-wrap: anywhere;
}
.quick-add-error {
    color: var(--error);
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
