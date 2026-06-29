<template>
    <BubbleMenu
        v-if="editor"
        :editor="editor"
        :tippy-options="{ duration: 100, placement: 'top' }"
        class="flex items-center gap-0.5 bg-white rounded-md shadow-lg border border-gray-200 p-1"
    >
        <button type="button" class="btn-bm" :class="{ 'is-active': editor.isActive('bold') }" @click="editor.chain().focus().toggleBold().run()" title="Bold (Ctrl+B)"><span class="font-bold">B</span></button>
        <button type="button" class="btn-bm" :class="{ 'is-active': editor.isActive('italic') }" @click="editor.chain().focus().toggleItalic().run()" title="Italic (Ctrl+I)"><span class="italic">I</span></button>
        <button type="button" class="btn-bm" :class="{ 'is-active': editor.isActive('underline') }" @click="editor.chain().focus().toggleUnderline().run()" title="Underline (Ctrl+U)"><span class="underline">U</span></button>
        <button type="button" class="btn-bm" :class="{ 'is-active': editor.isActive('strike') }" @click="editor.chain().focus().toggleStrike().run()" title="Strike"><span class="line-through">S</span></button>
        <button type="button" class="btn-bm" :class="{ 'is-active': editor.isActive('code') }" @click="editor.chain().focus().toggleCode().run()" title="Code inline">{{ '<>' }}</button>
        <div class="w-px h-4 bg-gray-200 mx-1"></div>
        <button type="button" class="btn-bm" :class="{ 'is-active': editor.isActive('heading', { level: 1 }) }" @click="editor.chain().focus().toggleHeading({ level: 1 }).run()" title="H1">H1</button>
        <button type="button" class="btn-bm" :class="{ 'is-active': editor.isActive('heading', { level: 2 }) }" @click="editor.chain().focus().toggleHeading({ level: 2 }).run()" title="H2">H2</button>
        <button type="button" class="btn-bm" :class="{ 'is-active': editor.isActive('heading', { level: 3 }) }" @click="editor.chain().focus().toggleHeading({ level: 3 }).run()" title="H3">H3</button>
        <div class="w-px h-4 bg-gray-200 mx-1"></div>
        <button type="button" class="btn-bm" @click="setLink" title="Link">🔗</button>
    </BubbleMenu>
</template>

<script setup lang="ts">
import { BubbleMenu } from '@tiptap/vue-3';
import type { Editor } from '@tiptap/vue-3';

const props = defineProps<{ editor: Editor | null }>();

const setLink = () => {
    if (!props.editor) return;
    const previousUrl = props.editor.getAttributes('link').href;
    const url = window.prompt('URL', previousUrl || 'https://');
    if (url === null) return;
    if (url === '') {
        props.editor.chain().focus().extendMarkRange('link').unsetLink().run();
        return;
    }
    props.editor.chain().focus().extendMarkRange('link').setLink({ href: url }).run();
};
</script>

<style scoped>
.btn-bm {
    @apply px-2 py-1 rounded text-xs text-gray-700 hover:bg-gray-100 min-w-[28px];
}
.btn-bm.is-active {
    @apply bg-primary-100 text-primary-700;
}
</style>
