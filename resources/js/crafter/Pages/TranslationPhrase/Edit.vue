<template>
  <PageHeader :title="'Edycja frazy: ' + phrase.phrase_pl">
    <div class="flex gap-2">
      <Button :as="Link" :href="route('crafter.translation-phrases.index')" color="gray" variant="outline">
        ← Powrót do listy
      </Button>
      <Button @click="reapply" color="warning" variant="outline">
        Reaplikuj do {{ phrase.product_count }} produktów
      </Button>
    </div>
  </PageHeader>

  <PageContent>
    <form @submit.prevent="save" class="space-y-6 w-full">
      <div class="bg-white rounded-lg shadow p-6 space-y-4">
        <TextInput
          v-model="form.phrase_pl"
          name="phrase_pl"
          label="Klucz PL (typ produktu, bez marki/modelu)"
          help="Renderowanie: ten tekst + ' ' + marka + ' ' + model. Marka/model brane z atrybutów PIM."
        />
        <div class="text-xs text-gray-500 font-mono">slug: {{ phrase.slug }} · produktów: {{ phrase.product_count }}</div>
      </div>

      <div class="bg-white rounded-lg shadow p-6 space-y-4">
        <h3 class="font-medium text-gray-900">Tłumaczenia per kanał (11 kanałów)</h3>
        <div class="grid gap-4">
          <div v-for="(row, idx) in form.renditions" :key="row.channel" class="grid grid-cols-12 gap-3 items-start">
            <div class="col-span-3">
              <div class="font-medium text-sm">{{ row.label }}</div>
              <div class="text-xs text-gray-500 font-mono">{{ row.channel }}</div>
              <div v-if="row.variants_count > 1" class="text-xs text-amber-600 mt-1">
                Niespójność: {{ row.variants_count }} wariantów w arkuszu
              </div>
              <div v-if="row.source" class="text-xs text-gray-400 mt-1">źródło: {{ row.source }}</div>
            </div>
            <div class="col-span-9">
              <input
                v-model="form.renditions[idx].value"
                type="text"
                class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                :class="row.value ? 'border-gray-300' : 'border-red-300 bg-red-50'"
                :placeholder="row.has_value ? '' : 'brak tłumaczenia'"
              />
            </div>
          </div>
        </div>
      </div>

      <div class="flex justify-end gap-2">
        <Button :as="Link" :href="route('crafter.translation-phrases.index')" color="gray" variant="outline">
          Anuluj
        </Button>
        <Button type="submit" color="primary">
          Zapisz frazę
        </Button>
      </div>
    </form>
  </PageContent>
</template>

<script setup lang="ts">
import { Link, useForm, router } from "@inertiajs/vue3";
import { PageHeader, PageContent, Button, TextInput } from "crafter/Components";

interface Rendition {
  channel: string;
  label: string;
  value: string;
  source: string | null;
  variants_count: number;
  has_value: boolean;
}

interface Props {
  phrase: { id: number; slug: string; phrase_pl: string; product_count: number };
  renditions: Rendition[];
}

const props = defineProps<Props>();

const form = useForm({
  phrase_pl: props.phrase.phrase_pl,
  renditions: props.renditions.map(r => ({ ...r })),
});

const save = () => {
  form.put(route('crafter.translation-phrases.update', props.phrase.id));
};

const reapply = () => {
  if (!confirm(`Reaplikować tę frazę do ${props.phrase.product_count} produktów? Pomija sloty już zablokowane (manual/sheet_import).`)) return;
  router.post(route('crafter.translation-phrases.reapply', props.phrase.id));
};
</script>
