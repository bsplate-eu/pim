<template>
  <div>
    <TransitionRoot as="template" :show="sidebarOpen">
      <Dialog
        as="div"
        class="fixed inset-0 z-40 flex md:hidden"
        @close="sidebarOpen = false"
      >
        <TransitionChild
          as="template"
          enter="transition-opacity ease-linear duration-300"
          enter-from="opacity-0"
          enter-to="opacity-100"
          leave="transition-opacity ease-linear duration-300"
          leave-from="opacity-100"
          leave-to="opacity-0"
        >
          <DialogOverlay class="fixed inset-0 bg-gray-600 bg-opacity-75" />
        </TransitionChild>
        <TransitionChild
          as="div"
          class="flex flex-1"
          enter="transition ease-in-out duration-300 transform"
          enter-from="-translate-x-full"
          enter-to="translate-x-0"
          leave="transition ease-in-out duration-300 transform"
          leave-from="translate-x-0"
          leave-to="-translate-x-full"
        >
          <Sidebar class="relative w-full max-w-xs" :force-expanded="true" />
        </TransitionChild>
        <div class="w-14 flex-shrink-0">
          <!-- Force sidebar to shrink to fit close icon -->
        </div>
      </Dialog>
    </TransitionRoot>

    <!-- Static sidebar for desktop -->
    <div
      :class="[
        'hidden md:fixed md:inset-y-0 md:z-10 md:flex md:flex-col transition-all duration-200',
        isCollapsed ? 'md:w-16' : 'md:w-64',
      ]"
    >
      <Sidebar class="min-h-0 bg-white" />
    </div>
    <div
      :class="[
        'flex flex-1 flex-col transition-all duration-200',
        isCollapsed ? 'md:pl-16' : 'md:pl-64',
      ]"
    >
      <div
        class="sticky top-0 z-20 flex items-center justify-between bg-white border-b border-gray-100 pl-1 pr-3 pt-1 md:pl-3 md:pt-2 md:pb-1"
      >
        <!-- Mobile hamburger -->
        <button
          type="button"
          class="-ml-0.5 -mt-0.5 inline-flex h-12 w-12 items-center justify-center rounded-md text-gray-500 hover:text-gray-700 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-primary-500 md:hidden"
          @click="sidebarOpen = true"
        >
          <span class="sr-only">{{ $t("crafter", "Open sidebar") }}</span>
          <Bars3Icon class="h-6 w-6" aria-hidden="true" />
        </button>
        <!-- Desktop collapse toggle -->
        <button
          type="button"
          class="hidden md:inline-flex h-10 w-10 items-center justify-center rounded-md text-sidebar-600 hover:bg-gray-100 transition-colors"
          :title="isCollapsed ? 'Rozwiń menu' : 'Zwiń menu'"
          @click="toggleCollapsed"
        >
          <ChevronDoubleRightIcon v-if="isCollapsed" class="h-5 w-5" aria-hidden="true" />
          <ChevronDoubleLeftIcon v-else class="h-5 w-5" aria-hidden="true" />
        </button>
        <div class="flex-1"></div>
        <NotificationBell />
      </div>
      <main class="flex min-h-screen flex-1 flex-col  bg-primary-50">
        <slot>
          <div class="mx-auto max-w-screen-2xl px-4 sm:px-6 md:px-8">
            <div
              class="flex h-96 items-center justify-center rounded-lg border-4 border-dashed border-gray-200 p-4"
            >
              <span class="text-xl italic text-gray-300">
                {{ $t("crafter", "Your content goes here...") }}
              </span>
            </div>
          </div>
        </slot>
      </main>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, provide, watch } from "vue";
import {
  Dialog,
  DialogOverlay,
  TransitionChild,
  TransitionRoot,
} from "@headlessui/vue";
import {
  Bars3Icon,
  ChevronDoubleLeftIcon,
  ChevronDoubleRightIcon,
} from "@heroicons/vue/24/outline";
import { Sidebar } from "crafter/Components";
import NotificationBell from "crafter/Components/NotificationBell/NotificationBell.vue";

const sidebarOpen = ref(false);

const STORAGE_KEY = "crafter.sidebarCollapsed";

const readInitial = (): boolean => {
  try {
    return localStorage.getItem(STORAGE_KEY) === "true";
  } catch {
    return false;
  }
};

const isCollapsed = ref<boolean>(readInitial());

// Expose collapsed state to the sidebar component tree (SidebarGroup / SidebarItem).
provide("sidebarCollapsed", isCollapsed);
provide("expandSidebar", () => {
  isCollapsed.value = false;
});

function toggleCollapsed() {
  isCollapsed.value = !isCollapsed.value;
}

watch(isCollapsed, (v) => {
  try {
    localStorage.setItem(STORAGE_KEY, String(v));
  } catch {
    /* ignore */
  }
});
</script>
