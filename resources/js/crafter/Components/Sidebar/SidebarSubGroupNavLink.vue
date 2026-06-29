<template>
    <Link
        :href="href"
        :class="[
            'flex w-full items-center gap-1 rounded-md py-1.5 pl-7 pr-2 text-sm font-medium transition-colors duration-200',
            active
                ? 'bg-gray-100 text-sidebar-600 ring-1 ring-gray-300'
                : 'text-sidebar-600/90 hover:bg-gray-100',
        ]"
    >
        <slot />
    </Link>
</template>

<script setup lang="ts">
import { Link, usePage } from "@inertiajs/vue3";
import { computed } from "vue";
import { useSidebarActiveConsumer } from "@/crafter/hooks/useSidebarActive";

interface Props {
    href: string;
}

const props = defineProps<Props>();

const active = computed(() => {
    const url = usePage().url.split("?")[0];
    try {
        const pathname = new URL(props.href, window.location.origin).pathname;
        return url.indexOf(pathname) !== -1;
    } catch {
        return false;
    }
});

useSidebarActiveConsumer(active);
</script>
