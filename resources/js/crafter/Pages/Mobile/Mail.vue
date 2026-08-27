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

    <!-- Akcje na dole czytnika: odpowiedz / odpowiedz wszystkim -->
    <footer v-if="!loading && !error" class="flex shrink-0 gap-2 border-t border-gray-200 bg-white px-3 py-2" style="padding-bottom: calc(0.5rem + env(safe-area-inset-bottom))">
      <button type="button" class="flex flex-1 items-center justify-center gap-2 rounded-xl bg-primary-600 py-2.5 text-sm font-semibold text-white active:opacity-80" @click="openReply(false)">
        <ArrowUturnLeftIcon class="h-5 w-5" /> Odpowiedz
      </button>
      <button type="button" class="flex items-center justify-center gap-1.5 rounded-xl border border-gray-300 px-3 py-2.5 text-sm text-gray-700 active:bg-gray-50" @click="openReply(true)">
        <ArrowUturnLeftIcon class="h-5 w-5" /> Wszystkim
      </button>
    </footer>
  </div>

  <!-- Okno pisania: nowa wiadomość / odpowiedz / odpowiedz wszystkim -->
  <div v-if="compose" class="fixed inset-0 z-40 flex flex-col bg-white">
    <header class="flex h-14 shrink-0 items-center gap-2 border-b border-gray-200 px-3">
      <button type="button" class="-ml-1 rounded-full p-2 active:bg-gray-100" @click="closeCompose">
        <XMarkIcon class="h-6 w-6 text-gray-700" />
      </button>
      <div class="flex-1 truncate font-semibold text-gray-900">{{ composeTitle }}</div>
      <button
        type="button"
        :disabled="sending || !canSend"
        class="flex items-center gap-1.5 rounded-xl bg-primary-600 px-4 py-2 text-sm font-semibold text-white active:opacity-80 disabled:opacity-40"
        @click="sendCompose"
      >
        <PaperAirplaneIcon v-if="!sending" class="h-4 w-4" />
        <span>{{ sending ? 'Wysyłanie…' : 'Wyślij' }}</span>
      </button>
    </header>

    <div class="flex-1 overflow-y-auto">
      <!-- Z: skrzynka -->
      <div class="flex items-center gap-2 border-b border-gray-100 px-4 py-2.5">
        <span class="w-10 shrink-0 text-sm text-gray-400">Z:</span>
        <select v-model="compose.account_id" class="min-w-0 flex-1 truncate border-0 bg-transparent p-0 text-sm text-gray-900 focus:ring-0">
          <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.label || a.email }} ({{ a.email }})</option>
        </select>
      </div>

      <!-- Do: -->
      <div class="border-b border-gray-100 px-4 py-2.5">
        <div class="flex items-center gap-2">
          <span class="w-10 shrink-0 text-sm text-gray-400">Do:</span>
          <input
            v-model="compose.to"
            type="text"
            inputmode="email"
            autocomplete="off"
            placeholder="adres@…"
            class="min-w-0 flex-1 border-0 bg-transparent p-0 text-sm focus:ring-0"
            @input="onRecipientInput('to')"
            @focus="recipientField = 'to'"
          />
          <button type="button" class="shrink-0 text-xs text-primary-600" @click="compose.showCc = !compose.showCc">DW</button>
        </div>
      </div>
      <div v-if="compose.showCc" class="border-b border-gray-100 px-4 py-2.5">
        <div class="flex items-center gap-2">
          <span class="w-10 shrink-0 text-sm text-gray-400">DW:</span>
          <input
            v-model="compose.cc"
            type="text"
            inputmode="email"
            autocomplete="off"
            placeholder="kopia…"
            class="min-w-0 flex-1 border-0 bg-transparent p-0 text-sm focus:ring-0"
            @input="onRecipientInput('cc')"
            @focus="recipientField = 'cc'"
          />
        </div>
      </div>

      <!-- Podpowiedzi kontaktów -->
      <div v-if="contactSuggestions.length" class="border-b border-gray-100 bg-gray-50">
        <button
          v-for="c in contactSuggestions"
          :key="c.email"
          type="button"
          class="flex w-full items-center gap-2 px-4 py-2 text-left active:bg-gray-100"
          @click="pickContact(c)"
        >
          <span class="truncate text-sm text-gray-900">{{ c.name || c.email }}</span>
          <span v-if="c.name" class="truncate text-xs text-gray-400">{{ c.email }}</span>
        </button>
      </div>

      <!-- Temat -->
      <div class="flex items-center gap-2 border-b border-gray-100 px-4 py-2.5">
        <span class="w-10 shrink-0 text-sm text-gray-400">Temat:</span>
        <input v-model="compose.subject" type="text" placeholder="(bez tematu)" class="min-w-0 flex-1 border-0 bg-transparent p-0 text-sm focus:ring-0" />
      </div>

      <!-- Treść -->
      <textarea v-model="compose.body" rows="9" placeholder="Napisz wiadomość…" class="w-full resize-none border-0 px-4 py-3 text-sm focus:ring-0"></textarea>

      <!-- Cytat oryginału -->
      <div v-if="compose.quote" class="px-4 pb-3">
        <button type="button" class="flex items-center gap-2 text-xs text-gray-500" @click="compose.includeQuote = !compose.includeQuote">
          <span class="h-4 w-7 rounded-full p-0.5 transition-colors" :class="compose.includeQuote ? 'bg-primary-600' : 'bg-gray-300'">
            <span class="block h-3 w-3 rounded-full bg-white transition-transform" :class="compose.includeQuote ? 'translate-x-3' : ''"></span>
          </span>
          Dołącz oryginalną wiadomość
        </button>
        <pre v-if="compose.includeQuote" class="mt-2 max-h-40 overflow-y-auto whitespace-pre-wrap rounded-lg bg-gray-50 p-2 text-xs text-gray-500">{{ compose.quote }}</pre>
      </div>

      <!-- Załączniki -->
      <div class="px-4 pb-6">
        <label class="inline-flex items-center gap-1.5 text-sm text-primary-600 active:opacity-70">
          <PaperClipIcon class="h-5 w-5" /> Załącz plik
          <input type="file" multiple class="hidden" @change="onFiles" />
        </label>
        <ul v-if="compose.files.length" class="mt-2 space-y-1">
          <li v-for="(f, i) in compose.files" :key="i" class="flex items-center justify-between gap-2 rounded-lg bg-gray-50 px-3 py-1.5 text-xs">
            <span class="min-w-0 truncate text-gray-700">{{ f.name }}</span>
            <span class="flex shrink-0 items-center gap-2 text-gray-400">
              {{ fmtSize(f.size) }}
              <button type="button" class="text-red-500" @click="compose.files.splice(i, 1)">Usuń</button>
            </span>
          </li>
        </ul>
      </div>

      <p v-if="sendError" class="px-4 pb-6 text-sm text-red-600">{{ sendError }}</p>
    </div>
  </div>

  <!-- FAB: nowa wiadomość -->
  <button
    v-if="!selected && !compose"
    type="button"
    aria-label="Nowa wiadomość"
    class="fixed right-5 z-20 flex h-14 w-14 items-center justify-center rounded-2xl bg-primary-600 text-white shadow-lg active:scale-95"
    style="bottom: calc(6rem + env(safe-area-inset-bottom))"
    @click="openNew"
  >
    <PencilSquareIcon class="h-6 w-6" />
  </button>

  <!-- Dymek potwierdzenia -->
  <div v-if="toast" class="fixed left-1/2 z-50 -translate-x-1/2 rounded-full bg-gray-900 px-4 py-2 text-sm text-white shadow-lg" style="bottom: calc(5rem + env(safe-area-inset-bottom))">
    {{ toast }}
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
import { EnvelopeIcon, ArrowLeftIcon, PaperClipIcon, FolderIcon, FunnelIcon, ChevronDownIcon, ArrowUturnLeftIcon, XMarkIcon, PaperAirplaneIcon, PencilSquareIcon } from "@heroicons/vue/24/outline";

const props = defineProps({
  messages: { type: Array, default: () => [] },
  catalogs: { type: Array, default: () => [] },
  categories: { type: Array, default: () => [] },
  colorCounts: { type: Object, default: () => ({}) },
  filters: { type: Object, default: () => ({}) },
  accounts: { type: Array, default: () => [] },
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

/* ===== Kompozytor (nowa wiadomość / odpowiedz / odpowiedz wszystkim) ===== */
const compose = ref(null);
const sending = ref(false);
const sendError = ref("");
const toast = ref("");
const recipientField = ref("to");
const contactSuggestions = ref([]);

const accountsById = computed(() =>
  Object.fromEntries((props.accounts || []).map((a) => [a.id, a]))
);
const composeTitle = computed(() =>
  ({ new: "Nowa wiadomość", reply: "Odpowiedź", replyAll: "Odpowiedz wszystkim" }[compose.value?.mode] || "Wiadomość")
);
const canSend = computed(() =>
  !!compose.value && (compose.value.to || "").trim() !== "" && !!compose.value.account_id
);

const stripHtml = (html) => {
  const tmp = document.createElement("div");
  tmp.innerHTML = String(html || "").replace(/<(style|script)\b[^>]*>[\s\S]*?<\/\1>/gi, "");
  return (tmp.textContent || "").trim();
};
const plainOf = (m) => (m.body_text && m.body_text.trim() ? m.body_text : stripHtml(m.body_html));

const buildQuote = (m) => {
  const who = m.from_name ? `${m.from_name} <${m.from_email}>` : (m.from_email || "");
  const when = m.date ? new Date(m.date).toLocaleString("pl-PL") : "";
  const quoted = plainOf(m).split("\n").map((l) => "> " + l).join("\n");
  return `Dnia ${when} ${who} napisał(a):\n${quoted}`;
};
const ensureRe = (s) => {
  const t = (s || "").trim();
  return /^re:/i.test(t) ? t : "Re: " + (t || "(bez tematu)");
};
const emailsOf = (arr) => (arr || []).map((r) => (r.email || "").toLowerCase()).filter(Boolean);
const uniq = (a) => Array.from(new Set(a));

const openReply = (all) => {
  const m = selected.value;
  if (!m || !m.from_email) return;
  const myEmail = (m.account_email || accountsById.value[m.account_id]?.email || "").toLowerCase();
  // Reply-To ma pierwszeństwo: maile z formularzy kontaktowych mają w „od" NASZ adres.
  const primary = (m.reply_to_email || m.from_email || "").toLowerCase();
  const toList = uniq([primary, ...(all ? emailsOf(m.to) : [])]).filter((e) => e && e !== myEmail);
  const ccList = all ? uniq(emailsOf(m.cc)).filter((e) => e && e !== myEmail && !toList.includes(e)) : [];
  compose.value = {
    mode: all ? "replyAll" : "reply",
    account_id: m.account_id || props.accounts[0]?.id || null,
    to: toList.join(", "),
    cc: ccList.join(", "),
    subject: ensureRe(m.subject),
    body: "",
    in_reply_to: m.message_id || null,
    quote: buildQuote(m),
    includeQuote: true,
    showCc: ccList.length > 0,
    files: [],
  };
  sendError.value = "";
  contactSuggestions.value = [];
};

const openNew = () => {
  compose.value = {
    mode: "new",
    account_id: props.accounts[0]?.id || null,
    to: "", cc: "", subject: "", body: "",
    in_reply_to: null, quote: "", includeQuote: false, showCc: false, files: [],
  };
  sendError.value = "";
  contactSuggestions.value = [];
};

const closeCompose = () => {
  compose.value = null;
  contactSuggestions.value = [];
  sendError.value = "";
};

let contactTimer = null;
const onRecipientInput = (field) => {
  recipientField.value = field;
  const frag = (compose.value[field] || "").split(/[,;]/).pop().trim();
  if (contactTimer) clearTimeout(contactTimer);
  if (frag.length < 2) { contactSuggestions.value = []; return; }
  contactTimer = setTimeout(async () => {
    try {
      const { data } = await axios.get("/admin/argo-mail/contacts", { params: { q: frag } });
      contactSuggestions.value = data.contacts || [];
    } catch (e) {
      contactSuggestions.value = [];
    }
  }, 220);
};
const pickContact = (c) => {
  const field = recipientField.value;
  const parts = (compose.value[field] || "").split(/[,;]/);
  parts[parts.length - 1] = c.email;
  compose.value[field] = parts.map((p) => p.trim()).filter(Boolean).join(", ") + ", ";
  contactSuggestions.value = [];
};

const onFiles = (e) => {
  compose.value.files.push(...Array.from(e.target.files || []));
  e.target.value = "";
};
const fmtSize = (n) => {
  if (n == null) return "";
  if (n < 1024) return n + " B";
  if (n < 1048576) return (n / 1024).toFixed(0) + " KB";
  return (n / 1048576).toFixed(1) + " MB";
};

let toastTimer = null;
const showToast = (msg) => {
  toast.value = msg;
  if (toastTimer) clearTimeout(toastTimer);
  toastTimer = setTimeout(() => { toast.value = ""; }, 2600);
};

const sendCompose = async () => {
  if (!compose.value || sending.value || !canSend.value) return;
  sending.value = true;
  sendError.value = "";
  try {
    const c = compose.value;
    let body = c.body || "";
    if (c.quote && c.includeQuote) body = (body ? body + "\n\n" : "") + c.quote;
    const fd = new FormData();
    fd.append("account_id", c.account_id);
    fd.append("to", c.to);
    if (c.cc) fd.append("cc", c.cc);
    fd.append("subject", c.subject || "");
    fd.append("body", body);
    fd.append("is_html", "0");
    if (c.in_reply_to) fd.append("in_reply_to", c.in_reply_to);
    c.files.forEach((f) => fd.append("attachments[]", f));
    const { data } = await axios.post("/admin/argo-mail/send", fd);
    if (data && data.ok === false) throw new Error(data.message || "Nie udało się wysłać.");
    closeCompose();
    selected.value = null;
    showToast("Wysłano ✓");
  } catch (e) {
    sendError.value = e?.response?.data?.message || e?.message || "Nie udało się wysłać wiadomości.";
  } finally {
    sending.value = false;
  }
};
</script>
