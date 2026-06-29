<template>
  <Head title="Zadania — ARGO" />

  <div class="divide-y divide-gray-100">
    <div v-if="tasks.length === 0" class="p-10 text-center text-gray-400">
      <RectangleStackIcon class="mx-auto h-10 w-10 text-gray-300" />
      <p class="mt-3 text-sm">Nie masz przypisanych zadań.</p>
    </div>

    <div v-for="t in tasks" :key="t.id">
      <button type="button" class="w-full text-left px-4 py-3 active:bg-gray-50" @click="toggle(t.id)">
        <div class="flex items-start gap-3">
          <span class="mt-1 h-2.5 w-2.5 shrink-0 rounded-full" :style="{ backgroundColor: t.project?.color || '#9ca3af' }"></span>
          <div class="min-w-0 flex-1">
            <div class="font-medium text-gray-900">{{ t.name }}</div>
            <div class="mt-0.5 flex flex-wrap items-center gap-1.5 text-[11px]">
              <span v-if="t.project" class="text-gray-400">{{ t.project.name }}</span>
              <span class="rounded-full bg-gray-100 px-2 py-0.5 text-gray-600">{{ prettyColumn(t.column) }}</span>
              <span v-if="t.priority" class="rounded-full bg-amber-100 px-2 py-0.5 text-amber-700">{{ t.priority }}</span>
              <span
                v-if="t.due_date"
                class="rounded-full px-2 py-0.5"
                :class="isOverdue(t.due_date) ? 'bg-red-100 text-red-700' : 'bg-primary-50 text-primary-600'"
              >
                <ClockIcon class="inline h-3 w-3 -mt-0.5 mr-0.5" />{{ fmtDue(t.due_date) }}
              </span>
            </div>
          </div>
          <ChevronRightIcon class="h-5 w-5 text-gray-300 shrink-0 transition-transform" :class="expanded === t.id ? 'rotate-90' : ''" />
        </div>
      </button>

      <div v-if="expanded === t.id" class="px-4 pb-4 -mt-1">
        <div class="rounded-xl bg-gray-50 p-3 text-sm text-gray-600">
          <div v-if="t.labels && t.labels.length" class="mb-2 flex flex-wrap gap-1">
            <span v-for="(lbl, i) in t.labels" :key="i" class="rounded-full bg-white border border-gray-200 px-2 py-0.5 text-[11px] text-gray-600">
              {{ typeof lbl === 'string' ? lbl : (lbl.name || '') }}
            </span>
          </div>
          <a
            :href="'/admin/argo-task/tasks/' + t.id"
            class="inline-flex items-center gap-1 font-medium text-primary-600"
          >
            Otwórz pełną kartę
            <ChevronRightIcon class="h-4 w-4" />
          </a>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import MobileLayout from "crafter/Layouts/MobileLayout.vue";
export default { layout: MobileLayout };
</script>

<script setup>
import { ref } from "vue";
import { Head } from "@inertiajs/vue3";
import { RectangleStackIcon, ChevronRightIcon, ClockIcon } from "@heroicons/vue/24/outline";

defineProps({
  tasks: { type: Array, default: () => [] },
});

const expanded = ref(null);
const toggle = (id) => {
  expanded.value = expanded.value === id ? null : id;
};

const prettyColumn = (key) =>
  String(key || "").replace(/_/g, " ").replace(/^\w/, (c) => c.toUpperCase());

const startOfToday = () => {
  const d = new Date();
  d.setHours(0, 0, 0, 0);
  return d;
};
const isOverdue = (due) => !!due && new Date(due) < startOfToday();
const fmtDue = (due) =>
  due ? new Intl.DateTimeFormat("pl-PL", { day: "2-digit", month: "2-digit" }).format(new Date(due)) : "";
</script>
