<template>
    <div class="workspace-editor" ref="wrapperEl">
        <div
            v-if="marquee.active"
            class="pointer-events-none absolute z-30 bg-primary-200/30 border border-primary-400"
            :style="marqueeStyle"
        />
        <div v-if="editor" class="flex items-center justify-end gap-3 text-xs text-gray-400 mb-2">
            <button
                type="button"
                class="text-gray-500 hover:text-gray-700"
                @click="selectAll"
                title="Zaznacz wszystko (Ctrl+A)"
            >Zaznacz wszystko</button>
            <button
                type="button"
                class="text-red-500 hover:text-red-700"
                @click="clearAll"
                title="Usuń całą treść"
            >Wyczyść treść</button>
            <span class="flex-1"></span>
            <span v-if="autoSave.status.value === 'saving'" class="text-amber-600">Zapisywanie…</span>
            <span v-else-if="autoSave.status.value === 'saved'" class="text-green-600">Zapisano{{ savedAgoLabel ? ` · ${savedAgoLabel}` : '' }}</span>
            <span v-else-if="autoSave.status.value === 'error'" class="text-red-600">Błąd zapisu</span>
        </div>

        <BubbleMenuBar :editor="editor" />
        <SlashMenu :editor="editor" />

        <editor-content :editor="editor" class="prose prose-sm max-w-none min-h-[300px] focus:outline-none" />
    </div>
</template>

<script setup lang="ts">
import { ref, computed, watch, onMounted, onBeforeUnmount, reactive } from 'vue';
import { Editor, EditorContent } from '@tiptap/vue-3';
import axios from 'axios';

import { buildExtensions } from './extensions';
import { FileBlockNode } from './FileBlockNode';
import { LinkPreviewNode } from './LinkPreviewNode';
import BubbleMenuBar from './BubbleMenu.vue';
import SlashMenu from './SlashMenu.vue';
import { useAutoSave } from './useAutoSave';

const props = defineProps<{
    modelValue: string;
    taskId: number | string;
    placeholder?: string;
}>();

const emit = defineEmits<{
    (e: 'update:modelValue', value: string): void;
    (e: 'saving'): void;
    (e: 'saved'): void;
}>();

const editor = ref<Editor | null>(null);

const autoSave = useAutoSave(
    props.taskId,
    () => editor.value?.getHTML() ?? '',
    2000,
);

watch(() => autoSave.status.value, (s) => {
    if (s === 'saving') emit('saving');
    else if (s === 'saved') emit('saved');
});

const now = ref(Date.now());
const tick = setInterval(() => { now.value = Date.now(); }, 10000);
onBeforeUnmount(() => clearInterval(tick));

const savedAgoLabel = computed(() => {
    if (!autoSave.lastSavedAt.value) return '';
    const diff = Math.round((now.value - autoSave.lastSavedAt.value.getTime()) / 1000);
    if (diff < 10) return 'przed chwilą';
    if (diff < 60) return `${diff}s temu`;
    if (diff < 3600) return `${Math.round(diff / 60)} min temu`;
    return `${Math.round(diff / 3600)} h temu`;
});

const uploadFile = async (file: File): Promise<{ url: string; name: string; size: number; mime: string } | null> => {
    try {
        const form = new FormData();
        form.append('file', file);
        const resp = await axios.post(
            route('crafter.argo-task.tasks.attachments.store', props.taskId),
            form,
            { headers: { 'Content-Type': 'multipart/form-data' } },
        );
        const a = resp.data.attachment;
        return { url: a.url, name: a.name, size: a.size, mime: a.mime };
    } catch {
        return null;
    }
};

const insertFilesAtCursor = async (files: FileList | File[]) => {
    if (!editor.value) return;
    for (const file of Array.from(files)) {
        const uploaded = await uploadFile(file);
        if (!uploaded) continue;
        if ((uploaded.mime || file.type || '').startsWith('image/')) {
            editor.value.chain().focus()
                .insertContent([
                    { type: 'image', attrs: { src: uploaded.url, alt: uploaded.name } },
                    { type: 'paragraph' },
                ])
                .run();
        } else {
            (editor.value.chain().focus() as any).setFileBlock({
                url:  uploaded.url,
                name: uploaded.name,
                size: uploaded.size,
                mime: uploaded.mime,
            }).run();
        }
    }
};

const selectAll = () => {
    editor.value?.chain().focus().selectAll().run();
};

const clearAll = () => {
    if (!editor.value) return;
    if (!window.confirm('Usunąć całą treść? Tej operacji nie można cofnąć.')) return;
    editor.value.chain().focus().selectAll().deleteSelection().run();
};

const maybeInsertLinkPreview = async (url: string) => {
    if (!editor.value) return;
    try {
        const resp = await axios.get(route('crafter.argo-task.link-preview'), { params: { url } });
        const preview = resp.data.preview;
        if (preview) {
            (editor.value.chain().focus() as any).setLinkPreview({
                url:         preview.url,
                title:       preview.title,
                description: preview.description,
                image:       preview.image,
                host:        preview.host,
            }).run();
        }
    } catch {
        // fallback: zwykły link
        editor.value.chain().focus().insertContent(`<a href="${url}" target="_blank" rel="noopener noreferrer">${url}</a>`).run();
    }
};

editor.value = new Editor({
    content: props.modelValue || '',
    extensions: buildExtensions(props.placeholder),
    editorProps: {
        handlePaste: (_view, event) => {
            const items = event.clipboardData?.items;
            if (items) {
                const files: File[] = [];
                for (let i = 0; i < items.length; i++) {
                    const item = items[i];
                    if (item.kind === 'file') {
                        const f = item.getAsFile();
                        if (f) files.push(f);
                    }
                }
                if (files.length) {
                    event.preventDefault();
                    insertFilesAtCursor(files);
                    return true;
                }
            }
            const text = event.clipboardData?.getData('text/plain') ?? '';
            const trimmed = text.trim();
            if (/^https?:\/\/\S+$/.test(trimmed) && !trimmed.includes('\n')) {
                event.preventDefault();
                maybeInsertLinkPreview(trimmed);
                return true;
            }
            return false;
        },
        handleDrop: (_view, event) => {
            const files = (event as DragEvent).dataTransfer?.files;
            if (files && files.length) {
                event.preventDefault();
                insertFilesAtCursor(files);
                return true;
            }
            return false;
        },
    },
    onUpdate: ({ editor }) => {
        const html = editor.getHTML();
        emit('update:modelValue', html);
        autoSave.trigger();
    },
});

watch(() => props.modelValue, (newVal) => {
    if (editor.value && newVal !== editor.value.getHTML()) {
        editor.value.commands.setContent(newVal || '', false);
    }
});

// ─── Marquee select (jak w Notion) ───────────────────────────────────────────
const wrapperEl = ref<HTMLElement | null>(null);
const marquee = reactive({
    active: false,
    startX: 0,
    startY: 0,
    curX:   0,
    curY:   0,
});

const marqueeStyle = computed(() => {
    const x = Math.min(marquee.startX, marquee.curX);
    const y = Math.min(marquee.startY, marquee.curY);
    const w = Math.abs(marquee.curX - marquee.startX);
    const h = Math.abs(marquee.curY - marquee.startY);
    return {
        left:   `${x}px`,
        top:    `${y}px`,
        width:  `${w}px`,
        height: `${h}px`,
    };
});

const onMouseDown = (e: MouseEvent) => {
    if (!wrapperEl.value || !editor.value) return;
    const target = e.target as HTMLElement;
    const pm = wrapperEl.value.querySelector('.ProseMirror') as HTMLElement | null;
    // Marquee start tylko gdy klik poza kontentem ProseMirror (padding, obszar poniżej tekstu itp.)
    if (!pm) return;
    if (target !== wrapperEl.value && target !== pm && pm.contains(target)) return;
    // Lewy przycisk
    if (e.button !== 0) return;

    const rect = wrapperEl.value.getBoundingClientRect();
    marquee.active = true;
    marquee.startX = e.clientX - rect.left;
    marquee.startY = e.clientY - rect.top;
    marquee.curX   = marquee.startX;
    marquee.curY   = marquee.startY;
    e.preventDefault();

    window.addEventListener('mousemove', onMouseMove);
    window.addEventListener('mouseup',   onMouseUp);
};

const onMouseMove = (e: MouseEvent) => {
    if (!wrapperEl.value) return;
    const rect = wrapperEl.value.getBoundingClientRect();
    marquee.curX = e.clientX - rect.left;
    marquee.curY = e.clientY - rect.top;
};

const onMouseUp = () => {
    window.removeEventListener('mousemove', onMouseMove);
    window.removeEventListener('mouseup',   onMouseUp);

    if (!marquee.active || !wrapperEl.value || !editor.value) {
        marquee.active = false;
        return;
    }
    const rect = wrapperEl.value.getBoundingClientRect();
    const mLeft   = rect.left + Math.min(marquee.startX, marquee.curX);
    const mTop    = rect.top  + Math.min(marquee.startY, marquee.curY);
    const mRight  = rect.left + Math.max(marquee.startX, marquee.curX);
    const mBottom = rect.top  + Math.max(marquee.startY, marquee.curY);

    marquee.active = false;

    // Klik bez ruchu → nie zaznaczaj
    if ((mRight - mLeft) < 4 && (mBottom - mTop) < 4) return;

    const pm = wrapperEl.value.querySelector('.ProseMirror') as HTMLElement | null;
    if (!pm) return;

    // Zbierz bezpośrednie dzieci ProseMirror (bloki top-level)
    const blocks = Array.from(pm.children) as HTMLElement[];
    const hits: HTMLElement[] = [];
    for (const el of blocks) {
        const b = el.getBoundingClientRect();
        const intersects = b.right > mLeft && b.left < mRight && b.bottom > mTop && b.top < mBottom;
        if (intersects) hits.push(el);
    }
    if (!hits.length) return;

    try {
        const view = editor.value.view;
        const firstPos = view.posAtDOM(hits[0], 0);
        const last = hits[hits.length - 1];
        const lastPos = view.posAtDOM(last, last.childNodes.length);
        const from = Math.min(firstPos, lastPos);
        const to   = Math.max(firstPos, lastPos);
        editor.value.chain().focus().setTextSelection({ from, to }).run();
    } catch {
        // ignore
    }
};

onMounted(() => {
    if (wrapperEl.value) {
        wrapperEl.value.addEventListener('mousedown', onMouseDown);
    }
});

onBeforeUnmount(() => {
    if (wrapperEl.value) {
        wrapperEl.value.removeEventListener('mousedown', onMouseDown);
    }
    window.removeEventListener('mousemove', onMouseMove);
    window.removeEventListener('mouseup',   onMouseUp);
    editor.value?.destroy();
});
</script>

<style>
.workspace-editor {
    position: relative;
    background: #ffffff;
    color: #111827;
    border: 1px solid #e5e7eb;
    border-radius: 0.5rem;
    padding: 1rem 1.25rem;
}
.workspace-editor .ProseMirror {
    min-height: 300px;
    outline: none;
    color: #111827;
}
.workspace-editor .ProseMirror p,
.workspace-editor .ProseMirror li,
.workspace-editor .ProseMirror h1,
.workspace-editor .ProseMirror h2,
.workspace-editor .ProseMirror h3,
.workspace-editor .ProseMirror h4,
.workspace-editor .ProseMirror blockquote {
    color: #111827;
}
.workspace-editor .ProseMirror hr {
    border: none;
    border-top: 3px solid #111827;
    margin: 1.25rem 0;
}
.workspace-editor .ProseMirror p.is-editor-empty:first-child::before {
    color: #adb5bd;
    content: attr(data-placeholder);
    float: left;
    height: 0;
    pointer-events: none;
}
.workspace-editor .ProseMirror table {
    border-collapse: collapse;
    margin: 0.5rem 0;
    overflow: hidden;
    table-layout: fixed;
    width: 100%;
}
.workspace-editor .ProseMirror table td,
.workspace-editor .ProseMirror table th {
    border: 1px solid #e5e7eb;
    box-sizing: border-box;
    min-width: 1em;
    padding: 6px 8px;
    position: relative;
    vertical-align: top;
}
.workspace-editor .ProseMirror table th {
    background-color: #f9fafb;
    font-weight: 600;
    text-align: left;
}
.workspace-editor .ProseMirror ul[data-type="taskList"] {
    list-style: none;
    padding: 0;
}
.workspace-editor .ProseMirror ul[data-type="taskList"] li {
    display: flex;
    align-items: flex-start;
    gap: 0.5rem;
}
.workspace-editor .ProseMirror pre {
    background: #0f172a;
    color: #f8fafc;
    border-radius: 0.5rem;
    padding: 0.75rem 1rem;
    overflow-x: auto;
    font-family: 'JetBrains Mono', Menlo, Consolas, monospace;
    font-size: 0.85rem;
}
.workspace-editor .ProseMirror code {
    background: rgba(107, 114, 128, 0.15);
    padding: 0.1rem 0.3rem;
    border-radius: 0.25rem;
    font-size: 0.85em;
}
.workspace-editor .ProseMirror pre code {
    background: transparent;
    padding: 0;
}
</style>
