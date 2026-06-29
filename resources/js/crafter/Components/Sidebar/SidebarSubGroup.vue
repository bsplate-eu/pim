<template>
    <div v-auto-animate>
        <button
            type="button"
            @click.prevent="onClick"
            :class="[
                'flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-sm font-medium text-sidebar-600/90 transition-colors',
                hasActive ? 'bg-gray-100 ring-1 ring-gray-300' : 'hover:bg-gray-100',
            ]"
        >
            <p class="flex-1 text-left">{{ title }}</p>
            <ChevronRightIcon
                :class="[
                    'h-4 w-4 flex-shrink-0 opacity-60 transition-transform duration-200',
                    isOpen ? 'rotate-90' : '',
                ]"
            />
        </button>

        <div
            v-if="isOpen"
            class="ml-3 mt-1 space-y-0.5 border-l border-gray-300 pl-2 [&_a]:!text-sm"
        >
            <slot />
        </div>
    </div>
</template>

<script setup lang="ts">
import { ChevronRightIcon } from "@heroicons/vue/20/solid";
import { useSidebarActiveProvider } from "@/crafter/hooks/useSidebarActive";

interface Props {
    title: string;
    open?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    open: false,
});

const { isOpen, hasActive } = useSidebarActiveProvider(props.open);

function onClick() {
    isOpen.value = !isOpen.value;
}
</script>
