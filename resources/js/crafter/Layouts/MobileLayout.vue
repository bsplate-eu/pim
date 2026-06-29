<template>
  <div class="min-h-screen flex flex-col bg-gray-50 text-gray-900">
    <!-- Górny pasek -->
    <header class="sticky top-0 z-20 bg-white border-b border-gray-200">
      <div class="flex items-center justify-between px-4 h-14">
        <Link href="/admin/m" class="flex items-center">
          <img src="/icons/argo-logo.png?v=1" alt="argo" class="h-7 w-auto" />
        </Link>
        <span class="text-sm font-medium text-gray-500">{{ currentLabel }}</span>
      </div>
    </header>

    <!-- Treść strony -->
    <main class="flex-1 overflow-y-auto pb-24">
      <slot />
    </main>

    <!-- Dolna nawigacja -->
    <nav class="fixed bottom-0 inset-x-0 z-20 border-t border-gray-200 bg-white/95 backdrop-blur"
         style="padding-bottom: env(safe-area-inset-bottom)">
      <div class="grid grid-cols-4">
        <Link
          v-for="tab in tabs"
          :key="tab.name"
          :href="tab.href"
          class="flex flex-col items-center justify-center py-2.5 text-[11px] font-medium transition-colors"
          :class="isActive(tab) ? 'text-primary-600' : 'text-gray-400'"
        >
          <span class="relative">
            <component :is="tab.icon" class="h-6 w-6" />
            <span
              v-if="tab.badge"
              class="absolute -top-1.5 -right-2.5 min-w-[16px] h-4 px-1 rounded-full bg-red-500 text-white text-[10px] leading-4 text-center"
            >
              {{ tab.badge > 99 ? '99+' : tab.badge }}
            </span>
          </span>
          <span class="mt-1">{{ tab.label }}</span>
        </Link>
      </div>
    </nav>
  </div>
</template>

<script setup>
import { computed } from "vue";
import { Link, usePage } from "@inertiajs/vue3";
import {
  HomeIcon,
  EnvelopeIcon,
  RectangleStackIcon,
  BellIcon,
} from "@heroicons/vue/24/outline";

const page = usePage();
const unread = computed(() => Number(page.props?.auth?.unreadNotifications ?? 0));

const tabs = computed(() => [
  { name: "home", label: "Start", icon: HomeIcon, href: "/admin/m" },
  { name: "mail", label: "Poczta", icon: EnvelopeIcon, href: "/admin/m/mail" },
  { name: "tasks", label: "Zadania", icon: RectangleStackIcon, href: "/admin/m/tasks" },
  { name: "notifications", label: "Alerty", icon: BellIcon, href: "/admin/m/notifications", badge: unread.value },
]);

const isActive = (tab) => {
  const url = (page.url || "").split("?")[0];
  if (tab.name === "home") return url === "/admin/m" || url === "/admin/m/";
  return url.startsWith(tab.href);
};

const currentLabel = computed(() => {
  const active = tabs.value.find((t) => isActive(t));
  return active ? active.label : "";
});
</script>
