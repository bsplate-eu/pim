<template>
    <div
        v-if="visible"
        ref="menuEl"
        class="fixed z-50 bg-white rounded-lg shadow-lg border border-gray-200 py-1 max-h-72 overflow-y-auto min-w-[240px]"
        :style="{ top: position.top + 'px', left: position.left + 'px' }"
    >
        <button
            v-for="(cmd, idx) in filteredCommands"
            :key="cmd.key"
            type="button"
            class="w-full flex items-start gap-2 px-3 py-2 text-left text-sm hover:bg-gray-100"
            :class="{ 'bg-primary-50': idx === selectedIndex }"
            @mousedown.prevent="runCommand(cmd)"
            @mouseenter="selectedIndex = idx"
        >
            <span class="text-lg leading-none mt-0.5">{{ cmd.icon }}</span>
            <span class="flex-1">
                <div class="font-medium text-gray-800">{{ cmd.label }}</div>
                <div class="text-xs text-gray-500">{{ cmd.description }}</div>
            </span>
        </button>
        <div v-if="!filteredCommands.length" class="px-3 py-2 text-xs text-gray-400">Brak komend</div>
    </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue';
import type { Editor } from '@tiptap/vue-3';

const props = defineProps<{ editor: Editor | null }>();

interface SlashCommand {
    key: string;
    label: string;
    description: string;
    icon: string;
    run: (editor: Editor) => void;
}

const commands: SlashCommand[] = [
    { key: 'h1', label: 'Nagłówek 1', description: 'Duży nagłówek', icon: 'H1', run: e => e.chain().focus().deleteRange(rangeFromSlash(e)).toggleHeading({ level: 1 }).run() },
    { key: 'h2', label: 'Nagłówek 2', description: 'Średni nagłówek', icon: 'H2', run: e => e.chain().focus().deleteRange(rangeFromSlash(e)).toggleHeading({ level: 2 }).run() },
    { key: 'h3', label: 'Nagłówek 3', description: 'Mały nagłówek', icon: 'H3', run: e => e.chain().focus().deleteRange(rangeFromSlash(e)).toggleHeading({ level: 3 }).run() },
    { key: 'bullet', label: 'Lista punktowana', description: 'Elementy z kropkami', icon: '•', run: e => e.chain().focus().deleteRange(rangeFromSlash(e)).toggleBulletList().run() },
    { key: 'ordered', label: 'Lista numerowana', description: 'Elementy z numerami', icon: '1.', run: e => e.chain().focus().deleteRange(rangeFromSlash(e)).toggleOrderedList().run() },
    { key: 'task', label: 'Lista zadań', description: 'Checkboxy', icon: '☑', run: e => e.chain().focus().deleteRange(rangeFromSlash(e)).toggleTaskList().run() },
    { key: 'quote', label: 'Cytat', description: 'Blok cytatu', icon: '❝', run: e => e.chain().focus().deleteRange(rangeFromSlash(e)).toggleBlockquote().run() },
    { key: 'code', label: 'Blok kodu', description: 'Code + highlight', icon: '</>', run: e => e.chain().focus().deleteRange(rangeFromSlash(e)).toggleCodeBlock().run() },
    { key: 'table', label: 'Tabela', description: 'Tabela 3×3', icon: '▦', run: e => e.chain().focus().deleteRange(rangeFromSlash(e)).insertTable({ rows: 3, cols: 3, withHeaderRow: true }).run() },
    { key: 'hr', label: 'Separator', description: 'Pozioma linia', icon: '―', run: e => e.chain().focus().deleteRange(rangeFromSlash(e)).setHorizontalRule().run() },
    { key: 'youtube', label: 'YouTube', description: 'Wstaw film', icon: '▶', run: e => {
        const url = window.prompt('URL YouTube');
        if (!url) return;
        e.chain().focus().deleteRange(rangeFromSlash(e)).setYoutubeVideo({ src: url, width: 640, height: 360 }).run();
    } },
];

const visible = ref(false);
const query = ref('');
const selectedIndex = ref(0);
const position = ref({ top: 0, left: 0 });
const slashFrom = ref(0);
const slashTo = ref(0);

const menuEl = ref<HTMLElement | null>(null);

const filteredCommands = computed(() => {
    const q = query.value.toLowerCase();
    if (!q) return commands;
    return commands.filter(c => c.label.toLowerCase().includes(q) || c.key.includes(q));
});

watch(filteredCommands, () => { selectedIndex.value = 0; });

function rangeFromSlash(_e: Editor) {
    return { from: slashFrom.value, to: slashTo.value };
}

const runCommand = (cmd: SlashCommand) => {
    if (!props.editor) return;
    visible.value = false;
    cmd.run(props.editor);
};

const onKeyDown = (event: KeyboardEvent) => {
    if (!visible.value) return;
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        selectedIndex.value = (selectedIndex.value + 1) % filteredCommands.value.length;
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        selectedIndex.value = (selectedIndex.value + filteredCommands.value.length - 1) % filteredCommands.value.length;
    } else if (event.key === 'Enter') {
        event.preventDefault();
        const cmd = filteredCommands.value[selectedIndex.value];
        if (cmd) runCommand(cmd);
    } else if (event.key === 'Escape') {
        visible.value = false;
    }
};

const checkSlash = () => {
    if (!props.editor) return;
    const { state } = props.editor;
    const { $from } = state.selection;
    const textBefore = state.doc.textBetween(Math.max(0, $from.pos - 50), $from.pos, '\n', ' ');
    const match = textBefore.match(/(^|\s)\/([\w]*)$/);
    if (match) {
        query.value = match[2];
        slashTo.value = $from.pos;
        slashFrom.value = $from.pos - match[2].length - 1;
        const coords = props.editor.view.coordsAtPos($from.pos);
        position.value = { top: coords.bottom + 4, left: coords.left };
        visible.value = true;
    } else {
        visible.value = false;
    }
};

onMounted(() => {
    document.addEventListener('keydown', onKeyDown, true);
    if (props.editor) {
        props.editor.on('selectionUpdate', checkSlash);
        props.editor.on('update', checkSlash);
    }
});

onBeforeUnmount(() => {
    document.removeEventListener('keydown', onKeyDown, true);
    if (props.editor) {
        props.editor.off('selectionUpdate', checkSlash);
        props.editor.off('update', checkSlash);
    }
});

watch(() => props.editor, (newEditor, oldEditor) => {
    if (oldEditor) {
        oldEditor.off('selectionUpdate', checkSlash);
        oldEditor.off('update', checkSlash);
    }
    if (newEditor) {
        newEditor.on('selectionUpdate', checkSlash);
        newEditor.on('update', checkSlash);
    }
});
</script>
