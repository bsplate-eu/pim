<template>
  <Head title="Poczta — ARGO" />

  <!-- Pasek: katalogi + filtry -->
  <div class="sticky top-0 z-10 bg-white border-b border-gray-200">
    <div class="grid grid-cols-2 divide-x divide-gray-100">
      <button
        type="button"
        class="flex items-center justify-center gap-1.5 py-2.5 text-sm active:bg-gray-50"
        :class="drawer === 'catalogs' ? 'text-primary-700 font-medium' : 'text-gray-600'"
        @click="toggleDrawer('catalogs')"
      >
        <FolderIcon class="h-5 w-5 shrink-0" />
        <span class="truncate max-w-[38vw]">{{ currentCatalogName || 'Wszystkie' }}</span>
        <ChevronDownIcon class="h-4 w-4 shrink-0 transition-transform" :class="drawer === 'catalogs' ? 'rotate-180' : ''" />
      </button>
      <button
        type="button"
        class="flex items-center justify-center gap-1.5 py-2.5 text-sm active:bg-gray-50"
        :class="(drawer === 'filters' || activeFilterCount) ? 'text-primary-700 font-medium' : 'text-gray-600'"
        @click="toggleDrawer('filters')"
      >
        <FunnelIcon class="h-5 w-5 shrink-0" />
        Filtry
        <span v-if="activeFilterCount" class="ml-0.5 rounded-full bg-primary-600 text-white text-[11px] min-w-[18px] h-[18px] px-1 leading-[18px] text-center">{{ activeFilterCount }}</span>
      </button>
    </div>

    <!-- Drawer: katalogi -->
    <div v-if="drawer === 'catalogs'" class="max-h-[55vh] overflow-y-auto border-t border-gray-100">
      <button
        type="button"
        class="w-full text-left px-4 py-2.5 active:bg-gray-50"
        :class="!filters.catalog_id ? 'bg-primary-50 text-primary-700 font-medium' : 'text-gray-700'"
        @click="selectCatalog(null)"
      >
        Wszystkie
      </button>
      <button
        v-for="c in catalogs"
        :key="c.id"
        type="button"
        class="w-full text-left px-4 py-2.5 flex items-center justify-between active:bg-gray-50"
        :class="filters.catalog_id === c.id ? 'bg-primary-50' : ''"
        @click="selectCatalog(c.id)"
      >
        <span class="flex items-center gap-2 min-w-0" :style="{ paddingLeft: (c.depth * 16) + 'px' }">
          <span class="h-2 w-2 rounded-full shrink-0" :style="{ backgroundColor: c.color || '#9ca3af' }"></span>
          <span class="truncate" :class="filters.catalog_id === c.id ? 'text-primary-700 font-medium' : 'text-gray-700'">{{ c.name }}</span>
        </span>
        <span v-if="c.unread" class="ml-2 shrink-0 rounded-full bg-primary-600 text-white text-[11px] px-2 py-0.5">{{ c.unread }}</span>
      </button>
    </div>

    <!-- Drawer: filtry -->
    <div v-if="drawer === 'filters'" class="border-t border-gray-100 p-3 space-y-3">
      <div class="flex gap-2">
        <input
          v-model="q"
          type="search"
          placeholder="Szukaj: temat, nadawca…"
          class="flex-1 rounded-lg border-gray-300 text-sm focus:border-primary-500 focus:ring-primary-500"
          @keyup.enter="applySearch"
        />
        <button type="button" class="rounded-lg bg-primary-600 px-3 text-sm text-white active:opacity-80" @click="applySearch">Szukaj</button>
      </div>

      <button type="button" class="flex items-center gap-2 text-sm text-gray-700" @click="toggleUnread">
        <span class="h-5 w-9 rounded-full p-0.5 transition-colors" :class="filters.unread ? 'bg-primary-600' : 'bg-gray-300'">
          <span class="block h-4 w-4 rounded-full bg-white transition-transform" :class="filters.unread ? 'translate-x-4' : ''"></span>
        </span>
        Tylko nieprzeczytane
      </button>

      <div v-if="categories.length">
        <div class="mb-1 text-xs text-gray-400">Kategorie</div>
        <div class="flex flex-wrap gap-1.5">
          <button
            v-for="c in categories"
            :key="c.id"
            type="button"
            class="rounded-full border px-2.5 py-1 text-xs"
            :class="filters.category_id === c.id ? 'border-primary-500 bg-primary-50 text-primary-700' : 'border-gray-200 text-gray-600'"
            @click="selectCategory(c.id)"
          >
            <span class="mr-1 inline-block h-2 w-2 rounded-full align-middle" :style="{ backgroundColor: c.color || '#9ca3af' }"></span>{{ c.name }}
          </button>
        </div>
      </div>

      <div v-if="availableColors.length">
        <div class="mb-1 text-xs text-gray-400">Kolor</div>
        <div class="flex gap-2">
          <button
            v-for="col in availableColors"
            :key="col"
            type="button"
            class="h-7 w-7 rounded-full border-2"
            :class="[COLOR_DOT[col], filters.color === col ? 'border-gray-900' : 'border-transparent']"
            @click="selectColor(col)"
          ></button>
        </div>
      </div>

      <button v-if="activeFilterCount || filters.catalog_id" type="button" class="text-sm text-gray-500 underline" @click="clearAll">
        Wyczyść wszystko
      </button>
    </div>
  </div>

  <!-- Lista maili -->
  <div class="divide-y divide-gray-100">
    <div v-if="messages.length === 0" class="p-10 text-center text-gray-400">
      <EnvelopeIcon class="mx-auto h-10 w-10 text-gray-300" />
      <p class="mt-3 text-sm">Brak wiadomości{{ (activeFilterCount || filters.catalog_id) ? ' dla tych filtrów' : '' }}.</p>
    </div>

    <button v-for="m in messages" :key="m.id" type="button" class="w-full text-left px-4 py-3 flex gap-3 active:bg-gray-50" @click="openMessage(m)">
      <span class="mt-2 h-2 w-2 shrink-0 rounded-full" :class="m.is_read ? 'bg-transparent' : 'bg-primary-600'"></span>
      <div class="min-w-0 flex-1">
        <div class="flex items-baseline justify-between gap-2">
          <span class="truncate" :class="m.is_read ? 'text-gray-700' : 'font-semibold text-gray-900'">{{ m.from }}</span>
          <span class="shrink-0 text-[11px] text-gray-400">{{ fmtDate(m.date) }}</span>
        </div>
        <div class="truncate text-sm" :class="m.is_read ? 'text-gray-600' : 'font-medium text-gray-900'">
          <span v-if="m.color" class="mr-1 inline-block h-2 w-2 rounded-full align-middle" :class="COLOR_DOT[m.color]"></span>{{ m.subject || '(bez tematu)' }}
        </div>
        <div class="truncate text-xs text-gray-400">
          <PaperClipIcon v-if="m.has_attachments" class="inline h-3 w-3 -mt-0.5 mr-0.5" />{{ m.snippet }}
        </div>
      </div>
    </button>
  </div>

  <!-- Czytnik wiadomości (pełny ekran) -->
  <div v-if="selected" class="fixed inset-0 z-30 flex flex-col bg-white">
    <header class="sticky top-0 z-10 flex h-14 items-center gap-2 border-b border-gray-200 bg-white px-3">
      <button type="button" class="-ml-1 rounded-full p-2 active:bg-gray-100" @click="closeMessage">
        <ArrowLeftIcon class="h-6 w-6 text-gray-700" />
      </button>
      <div class="min-w-0 flex-1">
        <div class="truncate font-semibold text-gray-900">{{ selected.subject || '(bez tematu)' }}</div>
        <div class="truncate text-xs text-gray-500">{{ selected.from_name || selected.from || selected.from_email }}</div>
      </div>
    </header>
    <div v-if="loading" class="flex flex-1 items-center justify-center text-sm text-gray-400">Wczytywanie…</div>
    <div v-else-if="error" class="flex flex-1 items-center justify-center px-6 text-center text-sm text-red-500">{{ error }}</div>
    <iframe v-else :srcdoc="bodyDoc" class="w-full flex-1 border-0" sandbox="allow-popups allow-popups-to-escape-sandbox" referrerpolicy="no-referrer"></iframe>
  </div>
</template>

<script>
import MobileLayout from "crafter/Layouts/MobileLayout.vue";
export default { layout: MobileLayout };
</script>

<script setup>
import { ref, computed, watch } from "vue";
import { Head, router } from "@inertiajs/vue3";
import axios from "axios";
import { EnvelopeIcon, ArrowLeftIcon, PaperClipIcon, FolderIcon, FunnelIcon, ChevronDownIcon } from "@heroicons/vue/24/outline";

const props = defineProps({
  messages: { type: Array, default: () => [] },
  catalogs: { type: Array, default: () => [] },
  categories: { type: Array, default: () => [] },
  colorCounts: { type: Object, default: () => ({}) },
  filters: { type: Object, default: () => ({}) },
});

const COLOR_DOT = { red: "bg-red-500", green: "bg-green-500", blue: "bg-blue-500", orange: "bg-orange-500" };

const drawer = ref(null); // null | 'catalogs' | 'filters'
const toggleDrawer = (name) => { drawer.value = drawer.value === name ? null : name; };

const q = ref(props.filters.q || "");
watch(() => props.filters.q, (v) => { q.value = v || ""; });

const availableColors = computed(() => Object.keys(props.colorCounts || {}));
const currentCatalogName = computed(() => (props.catalogs.find((c) => c.id === props.filters.catalog_id) || {}).name || "");
const activeFilterCount = computed(() => {
  let n = 0;
  if (props.filters.category_id) n++;
  if (props.filters.unread) n++;
  if (props.filters.color) n++;
  if (props.filters.q) n++;
  return n;
});

const navigate = (overrides) => {
  const base = {
    catalog_id: props.filters.catalog_id || "",
    category_id: props.filters.category_id || "",
    unread: props.filters.unread ? 1 : "",
    color: props.filters.color || "",
    q: props.filters.q || "",
  };
  const params = { ...base, ...overrides };
  Object.keys(params).forEach((k) => { if (params[k] === "" || params[k] == null) delete params[k]; });
  router.get("/admin/m/mail", params, { preserveScroll: true, preserveState: true });
};

const selectCatalog = (id) => { drawer.value = null; navigate({ catalog_id: id || "" }); };
const selectCategory = (id) => navigate({ category_id: props.filters.category_id === id ? "" : id });
const selectColor = (col) => navigate({ color: props.filters.color === col ? "" : col });
const toggleUnread = () => navigate({ unread: props.filters.unread ? "" : 1 });
const applySearch = () => navigate({ q: q.value.trim() });
const clearAll = () => { drawer.value = null; router.get("/admin/m/mail", {}, { preserveScroll: true, preserveState: true }); };

// ── Czytnik ──
const selected = ref(null);
const loading = ref(false);
const error = ref("");

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

const escapeHtml = (s) =>
  String(s ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));

const bodyDoc = computed(() => {
  const m = selected.value;
  if (!m) return "";
  const inner = m.body_html
    ? m.body_html
    : (m.body_text
        ? `<pre style="white-space:pre-wrap;font-family:inherit;margin:0">${escapeHtml(m.body_text)}</pre>`
        : "<p style='color:#9ca3af'>(brak treści)</p>");
  return `<!doctype html><html><head><meta charset="utf-8"><base target="_blank">`
    + `<meta name="referrer" content="no-referrer">`
    + `<meta name="viewport" content="width=device-width, initial-scale=1">`
    + `<style>html,body{margin:0}body{font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;padding:14px;color:#111;font-size:15px;line-height:1.5;word-break:break-word}img{max-width:100%;height:auto}table{max-width:100%}</style>`
    + `</head><body>${inner}</body></html>`;
});

const openMessage = async (m) => {
  loading.value = true;
  error.value = "";
  selected.value = { id: m.id, subject: m.subject, from: m.from, from_email: m.from_email, body_html: null, body_text: null };
  try {
    const { data } = await axios.get("/admin/argo-mail/messages/" + m.id);
    selected.value = data;
    m.is_read = true;
  } catch (e) {
    error.value = "Nie udało się wczytać wiadomości.";
  } finally {
    loading.value = false;
  }
};

const closeMessage = () => {
  selected.value = null;
  error.value = "";
};
</script>
