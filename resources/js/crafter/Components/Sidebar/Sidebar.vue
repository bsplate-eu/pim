<template>
    <div class="flex flex-1 flex-col overflow-hidden border-r border-gray-200 bg-white">
        <div class="h-0 flex-1 overflow-y-auto overflow-x-hidden pt-3 pb-4 px-3">
            <div
                :class="[
                    'flex flex-shrink-0 items-center',
                    collapsed ? 'justify-center' : '',
                ]"
            >
                <Logo />
            </div>
            <SidebarContent />
        </div>
        <div class="flex flex-shrink-0 border-t border-gray-200 p-4 bg-gray-50">
            <div
                :class="[
                    'flex w-full items-center',
                    collapsed ? 'justify-center' : '',
                ]"
            >
                <Avatar
                    :src="user?.avatar_url"
                    :name="`${user?.first_name} ${user?.last_name}`"
                />
                <template v-if="!collapsed">
                    <div class="ml-3">
                        <p class="text-sm font-medium text-sidebar-600">
                            {{ `${user?.first_name} ${user?.last_name}` }}
                        </p>
                    </div>
                    <UserDropdown class="ml-auto" />
                </template>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { computed, inject, provide, ref, type Ref } from "vue";
import { UserDropdown, Avatar } from "../index";
import { useUser } from "../../hooks/useUser";
import Logo from "@/crafter/Components/Logo.vue";
import SidebarContent from "@/crafter/Components/Sidebar.vue";

interface Props {
    // Used by the mobile dialog: always render full-width regardless of desktop collapse.
    forceExpanded?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    forceExpanded: false,
});

const { user } = useUser();

const injectedCollapsed = inject<Ref<boolean>>("sidebarCollapsed", ref(false));
const collapsed = computed(() =>
    props.forceExpanded ? false : injectedCollapsed.value
);

// When forced expanded (mobile), override the collapsed flag for all descendants.
if (props.forceExpanded) {
    provide("sidebarCollapsed", ref(false));
}
</script>
