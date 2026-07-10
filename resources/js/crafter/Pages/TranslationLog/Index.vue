<template>
  <PageHeader title="Tłumaczenia: logi">
    <span class="text-sm text-gray-500">Dziennik automatycznych tłumaczeń (composer / matryca)</span>
  </PageHeader>

  <PageContent>
    <!-- Filtry -->
    <div class="mb-4 flex flex-wrap items-center gap-3">
      <div class="relative max-w-md flex-1 min-w-[240px]">
        <MagnifyingGlassIcon class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-400" />
        <input
          v-model="searchTerm"
          type="search"
          placeholder="Szukaj: ID / SKU / nazwa PL…"
          class="w-full rounded-md border border-gray-300 pl-9 pr-3 py-2 text-sm focus:border-primary-500 focus:ring-primary-500"
        />
      </div>
      <div class="flex flex-wrap gap-1.5">
        <button
          v-for="pill in statusPills"
          :key="pill.key"
          @click="setStatus(pill.key)"
          class="rounded-full px-3 py-1 text-xs font-medium border transition"
          :class="activeStatus === pill.key
            ? 'bg-primary-600 text-white border-primary-600'
            : 'bg-white text-gray-600 border-gray-300 hover:bg-gray-50'"
        >
          {{ pill.label }} <span class="opacity-70">({{ counts[pill.key] ?? 0 }})</span>
        </button>
      </div>
    </div>

    <div class="bg-white rounded-lg shadow">
      <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
          <tr>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Czas</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Produkt</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">ID</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">SKU</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Zmiany</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <tr v-for="log in logs.data" :key="log.id" class="hover:bg-gray-50">
            <td class="px-4 py-2.5 text-xs text-gray-500 whitespace-nowrap">{{ log.created_at }}</td>
            <td class="px-4 py-2.5 text-sm text-gray-900 max-w-xs truncate" :title="log.name_pl ?? ''">
              {{ log.name_pl || "—" }}
            </td>
            <td class="px-4 py-2.5 text-sm text-gray-600 whitespace-nowrap">{{ log.external_id ?? "—" }}</td>
            <td class="px-4 py-2.5 text-sm text-gray-600 whitespace-nowrap">{{ log.product_code ?? "—" }}</td>
            <td class="px-4 py-2.5 whitespace-nowrap">
              <span
                class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium"
                :class="statusClass(log.status)"
              >
                <span class="h-1.5 w-1.5 rounded-full" :class="statusDot(log.status)" />
                {{ statusLabel(log.status) }}
              </span>
            </td>
            <td class="px-4 py-2.5 text-sm">
              <!-- Podsumowanie + tooltip na hover z pełnym PRZED→PO -->
              <div v-if="log.changes.length" class="group relative inline-block">
                <span class="cursor-help border-b border-dotted border-gray-400 text-gray-700">
                  {{ log.changes.length }} {{ log.changes.length === 1 ? "język" : "języki/-ów" }}
                  <span class="text-gray-400">({{ log.changes.map((c) => c.locale).join(", ") }})</span>
                  <span v-if="integrationsCount(log)" class="text-gray-400"> · Allegro ×{{ integrationsCount(log) }}</span>
                </span>
                <!-- Tooltip -->
                <div class="pointer-events-none absolute left-0 top-full z-20 mt-1 hidden w-[420px] rounded-lg border border-gray-200 bg-white p-3 text-xs shadow-xl group-hover:block">
                  <div class="mb-2 font-semibold text-gray-700">
                    Źródło: {{ log.source_locale.toUpperCase() }} → tłumaczenia
                  </div>
                  <table class="w-full">
                    <tbody>
                      <tr v-for="c in log.changes" :key="c.locale" class="align-top">
                        <td class="py-0.5 pr-2 font-mono font-semibold text-primary-700 uppercase">{{ c.locale }}</td>
                        <td class="py-0.5">
                          <div class="text-gray-400 line-through" v-if="c.from">{{ c.from }}</div>
                          <div class="text-gray-900">{{ c.to || "—" }}</div>
                        </td>
                      </tr>
                    </tbody>
                  </table>
                  <div v-if="integrationsCount(log)" class="mt-2 border-t border-gray-100 pt-2 text-gray-500">
                    Konta Allegro zaktualizowane: {{ integrationsCount(log) }}
                  </div>
                </div>
              </div>
              <span v-else class="text-gray-400">{{ log.status === "unmatched" ? "brak dopasowania" : "brak zmian" }}</span>
            </td>
          </tr>
          <tr v-if="logs.data.length === 0">
            <td colspan="6" class="px-4 py-8 text-center text-gray-500">Brak logów.</td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-if="logs.last_page > 1" class="mt-4 flex flex-wrap justify-center gap-2">
      <Button
        v-for="page in pageWindow"
        :key="page"
        :as="Link"
        :href="pageUrl(page)"
        :color="page === logs.current_page ? 'primary' : 'gray'"
        :variant="page === logs.current_page ? 'solid' : 'outline'"
        size="sm"
      >
        {{ page }}
      </Button>
    </div>
  </PageContent>
</template>

<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { Link, router } from "@inertiajs/vue3";
import { MagnifyingGlassIcon } from "@heroicons/vue/24/outline";
import { PageHeader, PageContent, Button } from "crafter/Components";

interface Change { locale: string; from: string; to: string }
interface LogRow {
  id: number;
  product_id: number | null;
  external_id: number | string | null;
  product_code: string | null;
  name_pl: string | null;
  status: string;
  matched: boolean;
  source_locale: string;
  changes: Change[];
  stats: Record<string, number>;
  message: string | null;
  context: string | null;
  created_at: string | null;
}

interface Props {
  logs: {
    data: LogRow[];
    total: number;
    current_page: number;
    last_page: number;
  };
  search?: string;
  status?: string;
  counts: Record<string, number>;
}

const props = defineProps<Props>();

const statusPills = [
  { key: "all", label: "Wszystkie" },
  { key: "ok", label: "OK" },
  { key: "unmatched", label: "Brak frazy" },
  { key: "skipped", label: "Bez zmian" },
  { key: "error", label: "Błąd" },
];

const activeStatus = computed(() => props.status || "all");

const statusLabel = (s: string) =>
  ({ ok: "OK", unmatched: "Brak frazy", skipped: "Bez zmian", error: "Błąd" }[s] ?? s);
const statusClass = (s: string) =>
  ({
    ok: "bg-green-50 text-green-700",
    unmatched: "bg-amber-50 text-amber-700",
    skipped: "bg-gray-100 text-gray-600",
    error: "bg-red-50 text-red-700",
  }[s] ?? "bg-gray-100 text-gray-600");
const statusDot = (s: string) =>
  ({ ok: "bg-green-500", unmatched: "bg-amber-500", skipped: "bg-gray-400", error: "bg-red-500" }[s] ?? "bg-gray-400");

const integrationsCount = (log: LogRow) => Number(log.stats?.applied_integrations ?? 0);

// === Filtr statusu ===
const setStatus = (key: string) => {
  router.get(route("crafter.translation-logs.index"), queryParams(key === "all" ? {} : { status: key }), {
    preserveState: true,
    preserveScroll: true,
    replace: true,
  });
};

// === Wyszukiwarka ===
const searchTerm = ref(props.search ?? "");
let searchDebounce: ReturnType<typeof setTimeout> | undefined;
watch(searchTerm, () => {
  clearTimeout(searchDebounce);
  searchDebounce = setTimeout(() => {
    router.get(route("crafter.translation-logs.index"), queryParams(activeStatus.value !== "all" ? { status: activeStatus.value } : {}), {
      preserveState: true,
      preserveScroll: true,
      replace: true,
    });
  }, 350);
});

const queryParams = (extra: Record<string, string> = {}) => {
  const params: Record<string, string> = { ...extra };
  if (searchTerm.value.trim()) params.search = searchTerm.value.trim();
  return params;
};

// === Paginacja ===
const pageUrl = (page: number) => {
  const params: Record<string, string> = { ...queryParams(activeStatus.value !== "all" ? { status: activeStatus.value } : {}), page: String(page) };
  const qs = new URLSearchParams(params).toString();
  return route("crafter.translation-logs.index") + (qs ? `?${qs}` : "");
};

// okno stron: max 12 przycisków wokół bieżącej
const pageWindow = computed(() => {
  const last = props.logs.last_page;
  const cur = props.logs.current_page;
  const span = 6;
  const start = Math.max(1, cur - span);
  const end = Math.min(last, cur + span);
  const out: number[] = [];
  for (let i = start; i <= end; i++) out.push(i);
  return out;
});
</script>
