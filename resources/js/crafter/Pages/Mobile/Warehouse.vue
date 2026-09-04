<template>
  <Head title="Magazyn" />

  <div class="flex flex-col">
    <!-- Szukajka przyklejona pod paskiem: w hali to jedyna rzecz, ktorej sie uzywa -->
    <div class="sticky top-14 z-10 bg-gray-50 px-4 pt-3 pb-2">
      <div class="relative">
        <MagnifyingGlassIcon class="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-gray-400" />
        <input
          ref="searchInput"
          v-model="q"
          type="search"
          inputmode="search"
          autocomplete="off"
          placeholder="Szukaj kodu…"
          class="w-full rounded-xl border-gray-200 bg-white py-3 pl-10 pr-10 text-base shadow-sm focus:border-primary-500 focus:ring-primary-500"
        />
        <button
          v-if="q"
          type="button"
          class="absolute right-2 top-1/2 -translate-y-1/2 rounded-full p-1.5 text-gray-400 active:bg-gray-100"
          @click="clearSearch"
        >
          <XMarkIcon class="h-5 w-5" />
        </button>
      </div>

      <div class="mt-1.5 flex items-center justify-between px-1 text-xs text-gray-400">
        <span>{{ found.length }} z {{ rows.length }} kodów</span>
        <span v-if="importedAt">arkusz z {{ importedAt }}</span>
      </div>
    </div>

    <!-- Lista wynikow -->
    <div class="px-4 pb-6 space-y-2">
      <p v-if="found.length === 0" class="py-16 text-center text-sm text-gray-400">
        Brak kodu „{{ q }}" w arkuszu.
      </p>

      <div
        v-for="row in visible"
        :key="row.id"
        class="rounded-2xl border border-gray-100 bg-white shadow-sm"
      >
        <button
          type="button"
          class="flex w-full items-center gap-3 p-4 text-left active:bg-gray-50"
          @click="toggle(row.id)"
        >
          <div class="min-w-0 flex-1">
            <div class="truncate font-semibold text-gray-900">{{ row.code }}</div>
            <div class="truncate text-sm text-gray-500">
              {{ row.places.length ? placesSummary(row) : 'brak miejsca w arkuszu' }}
            </div>
          </div>

          <span
            class="shrink-0 rounded-full px-2.5 py-1 text-sm font-bold tabular-nums"
            :class="qtyClass(row.total)"
          >
            {{ row.total ?? '—' }}
          </span>

          <ChevronDownIcon
            class="h-5 w-5 shrink-0 text-gray-300 transition-transform"
            :class="open === row.id ? 'rotate-180' : ''"
          />
        </button>

        <!-- Szczegoly: rozwijane, zeby lista zostala skanowalna kciukiem -->
        <div v-if="open === row.id" class="border-t border-gray-100 px-4 py-3 space-y-3">
          <div v-if="row.places.length" class="space-y-1.5">
            <div
              v-for="(p, i) in row.places"
              :key="i"
              class="flex items-center justify-between rounded-lg bg-gray-50 px-3 py-2"
            >
              <span class="text-sm font-medium text-gray-700">{{ p.place || '—' }}</span>
              <span class="text-sm font-bold tabular-nums text-gray-900">{{ p.qty ?? '—' }}</span>
            </div>
          </div>

          <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
            <div v-if="row.wymiar">
              <dt class="text-xs text-gray-400">Wymiar</dt>
              <dd class="text-gray-800">{{ row.wymiar }}</dd>
            </div>
            <div v-if="row.waga">
              <dt class="text-xs text-gray-400">Waga</dt>
              <dd class="text-gray-800">{{ row.waga }}</dd>
            </div>
            <div v-if="row.team">
              <dt class="text-xs text-gray-400">Ekipa</dt>
              <dd class="text-gray-800">{{ row.team }}</dd>
            </div>
          </dl>

          <div v-if="row.uwagi" class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">
            {{ row.uwagi }}
          </div>
        </div>
      </div>

      <!-- Doladowanie: 615 kart naraz zabija scroll na telefonie -->
      <button
        v-if="visible.length < found.length"
        type="button"
        class="w-full rounded-xl border border-gray-200 bg-white py-3 text-sm font-medium text-gray-600 active:bg-gray-50"
        @click="limit += PAGE"
      >
        Pokaż więcej ({{ found.length - visible.length }})
      </button>
    </div>
  </div>
</template>

<script>
import MobileLayout from "crafter/Layouts/MobileLayout.vue";
export default { layout: MobileLayout };
</script>

<script setup>
import { ref, computed, watch } from "vue";
import { Head } from "@inertiajs/vue3";
import {
  MagnifyingGlassIcon,
  XMarkIcon,
  ChevronDownIcon,
} from "@heroicons/vue/24/outline";

const props = defineProps({
  rows: { type: Array, default: () => [] },
  sheet: { type: String, default: "" },
  importedAt: { type: String, default: null },
});

const PAGE = 40;

const q = ref("");
const open = ref(null);
const limit = ref(PAGE);
const searchInput = ref(null);

/* Kod bywa zapisany „25.159 ALU" albo „25.159ALU" — porownujemy bez spacji
   i wielkosci liter, zeby wpisanie jednej wersji znalazlo obie. */
const normalize = (v) => String(v ?? "").replace(/\s+/g, "").toUpperCase();

const haystacks = computed(() =>
  props.rows.map((row) => ({
    row,
    key: normalize(row.code) + " " + normalize(row.places.map((p) => p.place).join("")),
  }))
);

const found = computed(() => {
  const needle = normalize(q.value);
  if (!needle) return props.rows;
  return haystacks.value.filter((h) => h.key.includes(needle)).map((h) => h.row);
});

const visible = computed(() => found.value.slice(0, limit.value));

// Nowe szukanie zaczyna liste od gory — inaczej „Pokaż więcej" zostaje rozwiniete.
watch(q, () => {
  limit.value = PAGE;
  open.value = null;
});

const toggle = (id) => {
  open.value = open.value === id ? null : id;
};

const clearSearch = () => {
  q.value = "";
  searchInput.value?.focus();
};

const placesSummary = (row) =>
  row.places
    .map((p) => (p.qty !== null && p.qty !== undefined ? `${p.place || "—"} · ${p.qty}` : p.place || "—"))
    .join("   ");

/* Zero to nie to samo co brak wpisu — pierwsze znaczy „nie ma na stanie",
   drugie „arkusz nie mowi". Kolor rozdziela te dwa przypadki. */
const qtyClass = (total) => {
  if (total === null || total === undefined) return "bg-gray-100 text-gray-400";
  if (total <= 0) return "bg-red-50 text-red-600";
  return "bg-green-50 text-green-700";
};
</script>
