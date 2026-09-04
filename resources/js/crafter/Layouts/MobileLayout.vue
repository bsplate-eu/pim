<template>
  <div class="min-h-screen flex flex-col bg-gray-50 text-gray-900">
    <!-- Gorny pasek w granacie sidebara — apka ma wygladac jak PIM, a nie jak
         osobny produkt. Czerwien zostaje na akcenty (badge, akcja). -->
    <header class="sticky top-0 z-20 bg-sidebar-600 text-white shadow-sm">
      <div class="flex items-center justify-between px-4 h-14">
        <Link href="/admin/m" class="flex items-center">
          <img src="/icons/argo-logo.png?v=1" alt="argo" class="h-7 w-auto brightness-0 invert" />
        </Link>
        <span class="text-sm font-semibold text-sidebar-100">{{ currentLabel }}</span>
      </div>
    </header>

    <!-- Tresc strony -->
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
          class="relative flex flex-col items-center justify-center py-2.5 text-[11px] font-semibold transition-colors"
          :class="isActive(tab) ? 'text-sidebar-600' : 'text-gray-400'"
        >
          <!-- Kreska nad aktywna zakladka: czytelniejsza na sloncu niz sam kolor -->
          <span
            v-if="isActive(tab)"
            class="absolute top-0 h-0.5 w-10 rounded-full bg-primary-500"
          />
          <span class="relative">
            <component :is="tab.icon" class="h-6 w-6" />
            <span
              v-if="tab.badge"
              class="absolute -top-1.5 -right-2.5 min-w-[16px] h-4 px-1 rounded-full bg-primary-500 text-white text-[10px] leading-4 text-center"
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
  ArchiveBoxIcon,
  BellIcon,
} from "@heroicons/vue/24/outline";

const page = usePage();
const unread = computed(() => Number(page.props?.auth?.unreadNotifications ?? 0));

/* Apka ma dwa moduly — Magazyn i Poczta. Start i Alerty to nie moduly, tylko
   obsluga samej apki (kafelki + skrzynka powiadomien push), stad w pasku, ale
   nie na liscie kafelkow. */
const tabs = computed(() => [
  { name: "home", label: "Start", icon: HomeIcon, href: "/admin/m" },
  { name: "warehouse", label: "Magazyn", icon: ArchiveBoxIcon, href: "/admin/m/magazyn" },
  { name: "mail", label: "Poczta", icon: EnvelopeIcon, href: "/admin/m/mail" },
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
