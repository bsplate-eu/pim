<template>
  <PageHeader title="Tłumaczenia: kolejka do akceptacji">
    <div class="flex items-center gap-3">
      <span class="text-sm text-gray-500">{{ products.total }} produktów wymaga przeglądu</span>
      <Button v-if="products.total > 0" color="primary" variant="outline" size="sm" @click="translateAll">
        Tłumacz wszystkie ({{ products.total }})
      </Button>
      <Button v-if="products.total > 0" color="success" size="sm" @click="approveAll">
        Zatwierdź wszystkie ({{ products.total }})
      </Button>
    </div>
  </PageHeader>

  <PageContent>
    <div class="mb-4 max-w-md">
      <div class="relative">
        <MagnifyingGlassIcon class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-400" />
        <input
          v-model="searchTerm"
          type="search"
          placeholder="Szukaj: nazwa PL / DE / kod produktu…"
          class="w-full rounded-md border border-gray-300 pl-9 pr-3 py-2 text-sm focus:border-primary-500 focus:ring-primary-500"
        />
      </div>
    </div>

    <!-- Pasek akcji masowych -->
    <div
      v-if="selectionCount > 0"
      class="mb-3 flex items-center justify-between rounded-md border border-primary-200 bg-primary-50 px-4 py-2.5"
    >
      <div class="text-sm text-gray-700">
        Zaznaczono <span class="font-semibold">{{ selectionCount }}</span>
        {{ selectAllMatching ? 'produktów (wszystkie pasujące)' : 'na tej stronie' }}
        <button class="ml-3 text-primary-600 hover:underline" @click="clearSelection">wyczyść</button>
      </div>
      <div class="flex gap-2">
        <Button color="primary" variant="outline" size="sm" @click="autoTranslateSelected">
          Tłumacz wszystko ({{ selectionCount }})
        </Button>
        <Button color="success" size="sm" @click="approveSelected">
          Zatwierdź zaznaczone ({{ selectionCount }})
        </Button>
      </div>
    </div>

    <div class="bg-white rounded-lg shadow overflow-hidden">
      <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
          <tr>
            <th class="px-4 py-3 w-10">
              <input
                type="checkbox"
                class="rounded border-gray-300 text-primary-600 focus:ring-primary-500 cursor-pointer"
                :checked="allOnPageSelected"
                :indeterminate.prop="someOnPageSelected && !allOnPageSelected"
                @change="togglePage"
              />
            </th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase cursor-pointer select-none hover:text-gray-700" @click="sortBy('id')">
              ID <span class="text-gray-400">{{ sortIndicator('id') }}</span>
            </th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase cursor-pointer select-none hover:text-gray-700" @click="sortBy('name_pl')">
              PL nazwa <span class="text-gray-400">{{ sortIndicator('name_pl') }}</span>
            </th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">DE</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase cursor-pointer select-none hover:text-gray-700" @click="sortBy('coverage')">
              Pokrycie <span class="text-gray-400">{{ sortIndicator('coverage') }}</span>
            </th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase cursor-pointer select-none hover:text-gray-700" @click="sortBy('status')">
              Status <span class="text-gray-400">{{ sortIndicator('status') }}</span>
            </th>
            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Akcje</th>
          </tr>
          <!-- Banner: cała strona zaznaczona, jest więcej stron -->
          <tr v-if="allOnPageSelected && !selectAllMatching && products.total > products.data.length">
            <td colspan="7" class="bg-primary-50 px-4 py-2 text-center text-sm text-gray-700">
              Zaznaczono wszystkie {{ products.data.length }} na tej stronie.
              <button class="font-semibold text-primary-600 hover:underline" @click="selectAllMatching = true">
                Zaznacz wszystkie {{ products.total }} (na wszystkich stronach)
              </button>
            </td>
          </tr>
          <tr v-else-if="selectAllMatching">
            <td colspan="7" class="bg-primary-100 px-4 py-2 text-center text-sm text-gray-700">
              Zaznaczono wszystkie <span class="font-semibold">{{ products.total }}</span> pasujące produkty.
              <button class="font-semibold text-primary-600 hover:underline" @click="clearSelection">Anuluj</button>
            </td>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-200">
          <tr v-for="p in products.data" :key="p.id" :class="['hover:bg-gray-50', isSelected(p.id) ? 'bg-primary-50/50' : '']">
            <td class="px-4 py-3">
              <input
                type="checkbox"
                class="rounded border-gray-300 text-primary-600 focus:ring-primary-500 cursor-pointer"
                :checked="isSelected(p.id)"
                @change="toggleOne(p.id)"
              />
            </td>
            <td class="px-4 py-3 text-sm font-mono">{{ p.id }}</td>
            <td class="px-4 py-3 text-sm">
              <div class="font-medium">{{ p.name_pl || '—' }}</div>
              <div class="text-xs text-gray-500">{{ p.product_code }}</div>
            </td>
            <td class="px-4 py-3 text-sm text-gray-600">{{ p.name_de || '(brak)' }}</td>
            <td class="px-4 py-3 text-sm">
              <Tag :color="p.locales_covered >= p.locales_target ? 'success' : p.locales_covered >= 3 ? 'warning' : 'danger'" size="sm" rounded>
                {{ p.locales_covered }} / {{ p.locales_target }}
              </Tag>
            </td>
            <td class="px-4 py-3 text-sm">
              <Tag :color="p.enabled ? 'success' : 'gray'" size="sm" rounded>
                {{ p.enabled ? 'enabled' : 'disabled' }}
              </Tag>
            </td>
            <td class="px-4 py-3 text-sm text-right">
              <div class="inline-flex gap-2">
                <Button @click="autoTranslate(p)" color="primary" variant="outline" size="sm">Auto-tłumacz</Button>
                <Button @click="approve(p)" color="success" size="sm" :disabled="p.enabled">Zatwierdź</Button>
                <IconButton :as="Link" :href="route('crafter.products.edit', p)" variant="ghost" color="gray" :icon="PencilSquareIcon" />
              </div>
            </td>
          </tr>
          <tr v-if="products.data.length === 0">
            <td colspan="7" class="px-4 py-8 text-center text-gray-500">Brak produktów do przeglądu.</td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-if="products.last_page > 1" class="mt-4 flex justify-center gap-2">
      <Button
        v-for="page in products.last_page"
        :key="page"
        :as="Link"
        :href="pageUrl(page)"
        :color="page === products.current_page ? 'primary' : 'gray'"
        :variant="page === products.current_page ? 'solid' : 'outline'"
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
import { PencilSquareIcon, MagnifyingGlassIcon } from "@heroicons/vue/24/outline";
import { PageHeader, PageContent, Button, IconButton, Tag } from "crafter/Components";

interface ReviewProduct {
  id: number;
  external_id: number | null;
  product_code: string;
  name_pl: string | null;
  name_de: string | null;
  enabled: boolean;
  locales_covered: number;
  locales_target: number;
}

interface Props {
  products: {
    data: ReviewProduct[];
    total: number;
    current_page: number;
    last_page: number;
  };
  sort?: string;
  search?: string;
}

const props = defineProps<Props>();

// === Wyszukiwarka ===
const searchTerm = ref(props.search ?? "");
let searchDebounce: ReturnType<typeof setTimeout> | undefined;
watch(searchTerm, () => {
  clearTimeout(searchDebounce);
  searchDebounce = setTimeout(() => {
    router.get(
      route("crafter.translation-review.index"),
      queryParams(props.sort ? { sort: props.sort } : {}),
      { preserveState: true, preserveScroll: true, replace: true },
    );
  }, 350);
});

const queryParams = (extra: Record<string, string> = {}) => {
  const params: Record<string, string> = { ...extra };
  if (searchTerm.value.trim()) params.search = searchTerm.value.trim();
  return params;
};

// === Zaznaczanie ===
const selectedIds = ref<Set<number>>(new Set());
const selectAllMatching = ref(false);

const isSelected = (id: number) => selectAllMatching.value || selectedIds.value.has(id);
const allOnPageSelected = computed(() =>
  props.products.data.length > 0 && props.products.data.every((p) => isSelected(p.id)),
);
const someOnPageSelected = computed(() => props.products.data.some((p) => isSelected(p.id)));
const selectionCount = computed(() => (selectAllMatching.value ? props.products.total : selectedIds.value.size));

const toggleOne = (id: number) => {
  if (selectAllMatching.value) {
    selectAllMatching.value = false;
    selectedIds.value = new Set(props.products.data.map((p) => p.id));
  }
  const next = new Set(selectedIds.value);
  next.has(id) ? next.delete(id) : next.add(id);
  selectedIds.value = next;
};

// Header checkbox: zaznacz/odznacz wszystkie WIDOCZNE na stronie
const togglePage = () => {
  if (allOnPageSelected.value) {
    clearSelection();
  } else {
    selectAllMatching.value = false;
    selectedIds.value = new Set(props.products.data.map((p) => p.id));
  }
};

const clearSelection = () => {
  selectedIds.value = new Set();
  selectAllMatching.value = false;
};

// reset zaznaczenia gdy zmienia się zbiór (inny filtr/sort/strona)
watch(() => props.products.data.map((p) => p.id).join(","), () => clearSelection());

const bulkPayload = () =>
  selectAllMatching.value
    ? { all: true, search: searchTerm.value.trim() || undefined }
    : { ids: Array.from(selectedIds.value) };

const approveSelected = () => {
  const count = selectionCount.value;
  if (count === 0) return;
  if (!confirm(`Zatwierdzić i włączyć do eksportu ${count} produktów?`)) return;
  router.post(route("crafter.translation-review.approve-bulk"), bulkPayload(), {
    preserveScroll: true,
    onSuccess: () => clearSelection(),
  });
};

const autoTranslateSelected = () => {
  const count = selectionCount.value;
  if (count === 0) return;
  router.post(route("crafter.translation-review.auto-translate-bulk"), bulkPayload(), {
    preserveScroll: true,
    onSuccess: () => clearSelection(),
  });
};

// Stałe buttony w nagłówku — działają na CAŁĄ kolejkę (z uwzględnieniem wyszukiwarki), bez zaznaczania.
const allPayload = () => ({ all: true, search: searchTerm.value.trim() || undefined });

const translateAll = () => {
  if (!confirm(`Przetłumaczyć z matrycy wszystkie ${props.products.total} produktów z kolejki?`)) return;
  router.post(route("crafter.translation-review.auto-translate-bulk"), allPayload(), {
    preserveScroll: true,
    onSuccess: () => clearSelection(),
  });
};

const approveAll = () => {
  if (!confirm(`Zatwierdzić i włączyć do eksportu wszystkie ${props.products.total} produktów z kolejki?`)) return;
  router.post(route("crafter.translation-review.approve-bulk"), allPayload(), {
    preserveScroll: true,
    onSuccess: () => clearSelection(),
  });
};

// === Sortowanie ===
const currentColumn = computed(() => (props.sort ?? "-id").replace(/^-/, ""));
const currentDesc = computed(() => (props.sort ?? "-id").startsWith("-"));

const sortBy = (column: string) => {
  const next = currentColumn.value === column && !currentDesc.value ? `-${column}` : column;
  router.get(route("crafter.translation-review.index"), queryParams({ sort: next }), {
    preserveState: true,
    preserveScroll: true,
  });
};

const sortIndicator = (column: string) =>
  currentColumn.value === column ? (currentDesc.value ? "▼" : "▲") : "";

const pageUrl = (page: number) => {
  const params = new URLSearchParams({ page: String(page) });
  if (props.sort) params.set("sort", props.sort);
  if (searchTerm.value.trim()) params.set("search", searchTerm.value.trim());
  return `${route("crafter.translation-review.index")}?${params.toString()}`;
};

// === Akcje pojedyncze ===
const autoTranslate = (p: ReviewProduct) => {
  router.post(route("crafter.translation-review.auto-translate", p.id));
};

const approve = (p: ReviewProduct) => {
  if (!confirm(`Zatwierdzić produkt ${p.id} i włączyć do eksportu?`)) return;
  router.post(route("crafter.translation-review.approve", p.id));
};
</script>
