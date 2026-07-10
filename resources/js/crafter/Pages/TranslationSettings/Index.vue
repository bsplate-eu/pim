<template>
  <PageHeader title="Tłumaczenia: ustawienia">
    <span class="text-sm text-gray-500">Zarządzanie automatycznym tłumaczeniem produktów z matrycy</span>
  </PageHeader>

  <PageContent>
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <!-- Statystyki -->
      <div class="lg:col-span-1 space-y-3">
        <div class="rounded-lg border border-gray-200 bg-white p-4">
          <div class="text-xs uppercase text-gray-400 mb-2">Stan katalogu</div>
          <dl class="space-y-1.5 text-sm">
            <div class="flex justify-between"><dt class="text-gray-500">Produkty (źródło)</dt><dd class="font-semibold">{{ stats.products_total }}</dd></div>
            <div class="flex justify-between"><dt class="text-gray-500">Chronione (locki)</dt><dd class="font-semibold">{{ stats.products_locked }}</dd></div>
            <div class="flex justify-between"><dt class="text-gray-500">Surowe nazwy PL</dt><dd class="font-semibold text-amber-600">{{ stats.products_pending }}</dd></div>
          </dl>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4">
          <div class="text-xs uppercase text-gray-400 mb-2">Logi</div>
          <dl class="space-y-1.5 text-sm">
            <div class="flex justify-between"><dt class="text-gray-500">Wpisów łącznie</dt><dd class="font-semibold">{{ stats.logs_total }}</dd></div>
            <div class="flex justify-between"><dt class="text-gray-500">Bez dopasowania</dt><dd class="font-semibold text-amber-600">{{ stats.logs_unmatched }}</dd></div>
            <div class="flex justify-between"><dt class="text-gray-500">Ostatnie uruchomienie</dt><dd class="font-medium text-gray-700">{{ stats.last_run_at ?? "—" }}</dd></div>
          </dl>
          <Link :href="route('crafter.translation-logs.index')" class="mt-3 inline-block text-sm text-primary-600 hover:underline">
            Zobacz logi →
          </Link>
        </div>
      </div>

      <!-- Ustawienia + akcje -->
      <div class="lg:col-span-2 space-y-6">
        <div class="rounded-lg border border-gray-200 bg-white p-5">
          <h3 class="text-base font-semibold text-gray-800 mb-4">Automatyzacja</h3>

          <label class="flex items-start justify-between gap-4 py-3 border-b border-gray-100">
            <span>
              <span class="block text-sm font-medium text-gray-800">Automatyczne tłumaczenie nowych produktów</span>
              <span class="block text-xs text-gray-500">Po synchronizacji / imporcie nowe produkty dostają nazwy z matrycy.</span>
            </span>
            <input type="checkbox" v-model="form.auto_translate_on_sync" class="mt-1 h-5 w-9 rounded-full cursor-pointer accent-primary-600" />
          </label>

          <label class="flex items-start justify-between gap-4 py-3 border-b border-gray-100">
            <span>
              <span class="block text-sm font-medium text-gray-800">Automatyczne zatwierdzanie</span>
              <span class="block text-xs text-gray-500">Produkt z wystarczającym pokryciem tłumaczeń jest włączany do eksportu bez ręcznej akceptacji.</span>
            </span>
            <input type="checkbox" v-model="form.auto_approve_enabled" class="mt-1 h-5 w-9 rounded-full cursor-pointer accent-primary-600" />
          </label>

          <div class="flex items-center justify-between gap-4 py-3" :class="{ 'opacity-50': !form.auto_approve_enabled }">
            <span>
              <span class="block text-sm font-medium text-gray-800">Próg pokrycia dla auto-zatwierdzania</span>
              <span class="block text-xs text-gray-500">Ile z 6 języków (pl, de, cs, sk, fr, es) musi być pokrytych.</span>
            </span>
            <input
              type="number" min="1" max="6"
              v-model.number="form.auto_approve_min_coverage"
              :disabled="!form.auto_approve_enabled"
              class="w-20 rounded-md border border-gray-300 px-3 py-1.5 text-sm text-right focus:border-primary-500 focus:ring-primary-500"
            />
          </div>

          <div class="mt-4 flex items-center gap-3">
            <Button color="primary" :disabled="saving" @click="save">Zapisz ustawienia</Button>
            <span v-if="savedFlash" class="text-sm text-green-600">Zapisano ✓</span>
          </div>
        </div>

        <div class="rounded-lg border border-gray-200 bg-white p-5">
          <h3 class="text-base font-semibold text-gray-800 mb-1">Uruchom teraz</h3>
          <p class="text-sm text-gray-500 mb-4">
            Wyprostuj surowe nazwy PL („Osłona pod silnik" → „osłona silnika") wg matrycy.
            Zachowuje warianty, chroni ręczne poprawki. Leci w tle — wyniki w
            <Link :href="route('crafter.translation-logs.index')" class="text-primary-600 hover:underline">Logach</Link>.
          </p>
          <Button color="success" variant="outline" :disabled="running || stats.products_pending === 0" @click="translateMissing">
            Wyprostuj surowe nazwy teraz ({{ stats.products_pending }})
          </Button>
        </div>
      </div>
    </div>
  </PageContent>
</template>

<script setup lang="ts">
import { reactive, ref } from "vue";
import { Link, router } from "@inertiajs/vue3";
import { PageHeader, PageContent, Button } from "crafter/Components";

interface Props {
  settings: {
    auto_translate_on_sync: boolean;
    auto_approve_enabled: boolean;
    auto_approve_min_coverage: number;
  };
  stats: {
    products_total: number;
    products_locked: number;
    products_pending: number;
    logs_total: number;
    logs_unmatched: number;
    last_run_at: string | null;
  };
}

const props = defineProps<Props>();

const form = reactive({ ...props.settings });
const saving = ref(false);
const savedFlash = ref(false);
const running = ref(false);

const save = () => {
  saving.value = true;
  router.put(route("crafter.translation-settings.update"), { ...form }, {
    preserveScroll: true,
    onSuccess: () => {
      savedFlash.value = true;
      setTimeout(() => (savedFlash.value = false), 2500);
    },
    onFinish: () => (saving.value = false),
  });
};

const translateMissing = () => {
  if (props.stats.products_pending === 0) return;
  if (!confirm(`Wyprostować ${props.stats.products_pending} surowych nazw PL w tle?`)) return;
  running.value = true;
  router.post(route("crafter.translation-settings.translate-missing"), {}, {
    preserveScroll: true,
    onFinish: () => (running.value = false),
  });
};
</script>
