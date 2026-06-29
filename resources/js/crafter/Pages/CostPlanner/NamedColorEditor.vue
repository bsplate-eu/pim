<template>
    <Card>
        <CardHeader>
            <h2 class="text-base font-semibold text-gray-700">{{ title }}</h2>
            <p v-if="description" class="text-xs text-gray-500">{{ description }}</p>
        </CardHeader>
        <CardContent>
            <div class="space-y-2">
                <div
                    v-for="(item, idx) in items"
                    :key="idx"
                    class="flex gap-2 items-center"
                >
                    <input
                        v-model="item.name"
                        class="flex-1 border border-gray-300 rounded px-2 py-1.5 text-sm focus:outline-none focus:ring-1 focus:ring-blue-400"
                        placeholder="Nazwa"
                    />

                    <div class="flex gap-1 items-center">
                        <button
                            v-for="c in allowedColors"
                            :key="c"
                            type="button"
                            @click="item.color = c; emitUpdate()"
                            :title="c"
                            :class="[
                                swatchClass(c),
                                item.color === c
                                    ? 'ring-2 ring-offset-1 ring-gray-700 scale-110'
                                    : 'hover:scale-110',
                            ]"
                            class="w-5 h-5 rounded-full border border-gray-200 transition-transform"
                        ></button>
                    </div>

                    <button @click="remove(idx)" class="text-red-500 hover:text-red-700 p-1">
                        <TrashIcon class="w-4 h-4" />
                    </button>
                </div>
                <Button color="gray" variant="outline" :leftIcon="PlusIcon" @click.prevent="add">
                    Dodaj pozycję
                </Button>
            </div>
        </CardContent>
    </Card>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { PlusIcon, TrashIcon } from '@heroicons/vue/24/outline';
import {
    Card, CardHeader, CardContent,
    Button,
} from 'crafter/Components';

interface NamedColor { name: string; color: string; }

const props = defineProps<{
    modelValue: NamedColor[];
    title: string;
    description?: string;
    allowedColors: string[];
}>();

const emit = defineEmits<{
    (e: 'update:modelValue', v: NamedColor[]): void
}>();

const items = computed({
    get: () => props.modelValue,
    set: (v: NamedColor[]) => emit('update:modelValue', v),
});

function emitUpdate() {
    emit('update:modelValue', items.value);
}

function add() {
    items.value.push({ name: '', color: props.allowedColors[0] ?? 'gray' });
    emitUpdate();
}

function remove(idx: number) {
    items.value.splice(idx, 1);
    emitUpdate();
}

function swatchClass(color: string): string {
    const map: Record<string, string> = {
        green:  'bg-green-400',
        red:    'bg-red-400',
        orange: 'bg-orange-400',
        blue:   'bg-blue-400',
        yellow: 'bg-yellow-400',
        amber:  'bg-amber-400',
        purple: 'bg-purple-400',
        pink:   'bg-pink-400',
        indigo: 'bg-indigo-400',
        cyan:   'bg-cyan-400',
        gray:   'bg-gray-400',
    };
    return map[color] ?? map.gray;
}
</script>
