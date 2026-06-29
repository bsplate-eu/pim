<template>
    <Link
        :href="href"
        :title="collapsed ? slotText : undefined"
        :class="[
            'group/link flex items-center rounded-md px-2 py-2 text-base font-medium transition-colors duration-200',
            collapsed ? 'justify-center' : '',
            active
                ? 'bg-gray-200 text-sidebar-600'
                : 'text-sidebar-600/90 hover:bg-gray-100',
        ]"
    >
        <component
            v-if="icon"
            :is="icon"
            :class="[
                'h-6 w-6 flex-shrink-0 transition-colors',
                collapsed ? '' : 'mr-3',
                active
                    ? 'text-sidebar-600'
                    : 'text-sidebar-600/60 group-hover/link:text-sidebar-600',
            ]"
            aria-hidden="true"
        />
        <span v-if="!collapsed"><slot /></span>
    </Link>
</template>

<script setup lang="ts">
import { Link, usePage } from "@inertiajs/vue3";
import {
    computed,
    inject,
    ref,
    useSlots,
    type Component,
    type Ref,
} from "vue";
import { useSidebarActiveConsumer } from "@/crafter/hooks/useSidebarActive";

interface Props {
    href: string;
    icon?: Component;
}

const props = defineProps<Props>();
const slots = useSlots();

const active = computed(() => {
    const url = usePage().url.split("?")[0];
    try {
        const pathname = new URL(props.href, window.location.origin).pathname;
        return url.indexOf(pathname) !== -1;
    } catch {
        return false;
    }
});

const sidebarCollapsedRef = inject<Ref<boolean>>("sidebarCollapsed", ref(false));
const collapsed = computed(() => sidebarCollapsedRef.value);

const slotText = computed(() => {
    const nodes = slots.default?.();
    if (!nodes || !nodes.length) return "";
    const child = nodes[0].children;
    return typeof child === "string" ? child.trim() : "";
});

useSidebarActiveConsumer(active);
</script>
