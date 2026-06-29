<template>
    <div class="bg-white rounded-lg shadow-lg border border-gray-200 py-1 max-h-64 overflow-y-auto min-w-[220px]">
        <button
            v-for="(item, index) in items"
            :key="item.id"
            type="button"
            class="w-full flex items-center gap-2 px-3 py-1.5 text-sm text-left hover:bg-gray-100"
            :class="{ 'bg-primary-50 text-primary-700': index === selectedIndex }"
            @click="selectItem(index)"
        >
            <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-gray-200 text-xs font-semibold text-gray-600">
                {{ initialsOf(item.label) }}
            </span>
            <span class="flex-1 truncate">{{ item.label || item.email }}</span>
        </button>
        <div v-if="!items.length" class="px-3 py-2 text-xs text-gray-400">Brak wyników</div>
    </div>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue';

interface MentionItem {
    id: number;
    label: string;
    email?: string;
    avatar?: string | null;
}

const props = defineProps<{
    items: MentionItem[];
    command: (item: { id: number; label: string }) => void;
}>();

const selectedIndex = ref(0);

watch(() => props.items, () => { selectedIndex.value = 0; });

const initialsOf = (name: string): string => {
    return (name || '?')
        .split(/\s+/)
        .map(s => s.charAt(0))
        .join('')
        .slice(0, 2)
        .toUpperCase();
};

const selectItem = (index: number) => {
    const item = props.items[index];
    if (item) {
        props.command({ id: item.id, label: item.label });
    }
};

const onKeyDown = (event: KeyboardEvent): boolean => {
    if (event.key === 'ArrowUp') {
        selectedIndex.value = (selectedIndex.value + props.items.length - 1) % props.items.length;
        return true;
    }
    if (event.key === 'ArrowDown') {
        selectedIndex.value = (selectedIndex.value + 1) % props.items.length;
        return true;
    }
    if (event.key === 'Enter') {
        selectItem(selectedIndex.value);
        return true;
    }
    return false;
};

defineExpose({ onKeyDown });
</script>
