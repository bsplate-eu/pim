<template>
  <Head title="Powiadomienia — ARGO" />

  <div class="flex items-center justify-between px-4 py-3">
    <span class="text-sm text-gray-500">
      {{ unreadCount > 0 ? unreadCount + ' nieprzeczytanych' : 'Wszystko przeczytane' }}
    </span>
    <button
      v-if="unreadCount > 0"
      type="button"
      class="text-sm font-medium text-primary-600 active:opacity-70"
      @click="markAll"
    >
      Oznacz wszystkie
    </button>
  </div>

  <div class="divide-y divide-gray-100 border-t border-gray-100">
    <div v-if="notifications.length === 0" class="p-10 text-center text-gray-400">
      <BellIcon class="mx-auto h-10 w-10 text-gray-300" />
      <p class="mt-3 text-sm">Brak powiadomień.</p>
    </div>

    <div
      v-for="n in notifications"
      :key="n.id"
      class="px-4 py-3 flex gap-3"
      :class="n.read_at ? 'bg-white' : 'bg-primary-50/60'"
      @click="open(n)"
    >
      <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full" :class="n.read_at ? 'bg-transparent' : 'bg-primary-600'"></span>
      <div class="min-w-0 flex-1">
        <div class="flex items-baseline justify-between gap-2">
          <span class="font-medium text-gray-900">{{ title(n) }}</span>
          <span class="shrink-0 text-[11px] text-gray-400">{{ fmtDate(n.created_at) }}</span>
        </div>
        <div v-if="subtitle(n)" class="truncate text-sm text-gray-600">{{ subtitle(n) }}</div>
        <a
          v-if="taskUrl(n)"
          :href="taskUrl(n)"
          class="mt-1 inline-flex items-center gap-1 text-xs font-medium text-primary-600"
          @click.stop
        >
          Otwórz zadanie
          <ChevronRightIcon class="h-3.5 w-3.5" />
        </a>
      </div>
    </div>
  </div>
</template>

<script>
import MobileLayout from "crafter/Layouts/MobileLayout.vue";
export default { layout: MobileLayout };
</script>

<script setup>
import { Head, router } from "@inertiajs/vue3";
import { BellIcon, ChevronRightIcon } from "@heroicons/vue/24/outline";

defineProps({
  notifications: { type: Array, default: () => [] },
  unreadCount: { type: Number, default: 0 },
});

const fmtDate = (iso) => {
  if (!iso) return "";
  const d = new Date(iso);
  const now = new Date();
  const sameDay = d.toDateString() === now.toDateString();
  return new Intl.DateTimeFormat("pl-PL", sameDay
    ? { hour: "2-digit", minute: "2-digit" }
    : { day: "2-digit", month: "2-digit" }
  ).format(d);
};

const title = (n) => {
  if (n.type === "TaskAssignedNotification") return "Przypisano Cię do zadania";
  if (n.type === "UserMentionedInTaskNotification") return "Wspomniano Cię w zadaniu";
  if (n.type === "TaskDueSoonNotification") return "Termin zadania dziś";
  if (n.type === "NewMailNotification") {
    const c = Number(n.data?.count || 1);
    return c > 1 ? `Nowe wiadomości (${c})` : "Nowy e-mail";
  }
  return (n.data && (n.data.title || n.data.subject)) || "Powiadomienie";
};

const subtitle = (n) => {
  const d = n.data || {};
  if (n.type === "NewMailNotification") {
    return [d.from, d.subject].filter(Boolean).join(": ");
  }
  return d.task_name || d.message || d.excerpt || "";
};

const taskUrl = (n) => {
  const id = n.data && n.data.task_id;
  return id ? "/admin/argo-task/tasks/" + id : null;
};

const open = (n) => {
  if (!n.read_at) {
    router.post("/admin/m/notifications/" + n.id + "/read", {}, { preserveScroll: true });
  }
};

const markAll = () => {
  router.post("/admin/m/notifications/read-all", {}, { preserveScroll: true });
};
</script>
