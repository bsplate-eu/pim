<template>
    <div class="group/section mt-4" v-auto-animate>
        <button
            v-if="toggable"
            type="button"
            :title="collapsed ? title : undefined"
            @click.prevent="onClick"
            :class="[
                'flex w-full items-center gap-2 rounded-lg px-2 py-2.5 text-sidebar-600 transition-colors duration-200',
                collapsed ? 'justify-center' : '',
                hasActive ? 'bg-[#15275a]/10 ring-1 ring-[#15275a]/20' : 'hover:bg-gray-100',
            ]"
        >
            <component
                v-if="icon"
                :is="icon"
                class="h-5 w-5 flex-shrink-0"
                aria-hidden="true"
            />
            <template v-if="!collapsed">
                <p class="flex-1 text-left text-md font-medium uppercase tracking-wide">
                    {{ title }}
                </p>
                <ChevronRightIcon
                    :class="[
                        'h-4 w-4 flex-shrink-0 opacity-60 transition-transform duration-200',
                        isOpen ? 'rotate-90' : '',
                    ]"
                />
            </template>
        </button>

        <div
            v-else
            :title="collapsed ? title : undefined"
            :class="[
                'flex w-full items-center gap-2 px-2 py-2.5 text-sidebar-600',
                collapsed ? 'justify-center' : '',
            ]"
        >
            <component
                v-if="icon"
                :is="icon"
                class="h-5 w-5 flex-shrink-0"
                aria-hidden="true"
            />
            <p
                v-if="!collapsed"
                class="flex-1 text-left text-md font-medium uppercase tracking-wide"
            >
                {{ title }}
            </p>
        </div>

        <div v-if="isOpen && !collapsed" class="mt-1 space-y-1 pl-3">
            <slot />
        </div>
    </div>
</template>

<script setup lang="ts">
import { ChevronRightIcon } from "@heroicons/vue/20/solid";
import { computed, inject, ref, type Component, type Ref } from "vue";
import { useSidebarActiveProvider } from "@/crafter/hooks/useSidebarActive";

interface Props {
    title: string;
    open?: boolean;
    toggable?: boolean;
    icon?: Component;
}

const props = withDefaults(defineProps<Props>(), {
    open: true,
    toggable: true,
});

const { isOpen, hasActive } = useSidebarActiveProvider(props.open);

const sidebarCollapsedRef = inject<Ref<boolean>>("sidebarCollapsed", ref(false));
const expandSidebar = inject<() => void>("expandSidebar", () => {});
const collapsed = computed(() => sidebarCollapsedRef.value);

function onClick() {
    if (collapsed.value) {
        // Collapsed: clicking an icon expands the whole sidebar and opens this group.
        expandSidebar();
        isOpen.value = true;
    } else {
        isOpen.value = !isOpen.value;
    }
}
</script>
