<template>
  <PageHeader
    sticky
    :title="$t('crafter', 'Edit Pricelist') + ' - ' + pricelist.name"
    :subtitle="`Last updated at ${dayjs(
      pricelist.updated_at
    ).format('DD.MM.YYYY')}`"
  >
    <input
      ref="csvInput"
      type="file"
      accept=".csv,text/csv"
      class="hidden"
      @change="onCsvSelected"
    />
    <Button
      :leftIcon="ArrowUpTrayIcon"
      @click="csvInput?.click()"
      v-can="'crafter.pricelist.edit'"
    >
      Aktualizuj z CSV
    </Button>
    <Button
      :leftIcon="ArrowDownTrayIcon"
      @click="exportCsv"
      v-can="'crafter.pricelist.edit'"
    >
      Eksport CSV
    </Button>
    <Button
      :leftIcon="ArrowDownTrayIcon"
      @click="submit"
      :loading="form.processing"
      v-can="'crafter.pricelist.edit'"
    >
      {{ $t("crafter", "Save") }}
    </Button>
  </PageHeader>

  <Form :form="form" :submit="submit" :pricelist="pricelist" :sources="sources" />
</template>

<script setup lang="ts">
import { ref } from "vue";
import { router } from "@inertiajs/vue3";
import { ArrowDownTrayIcon, ArrowUpTrayIcon } from "@heroicons/vue/24/outline";
import { PageHeader, Button } from "crafter/Components";
import { useForm } from "crafter/hooks/useForm";
import Form from "./Form.vue";
import type { Pricelist, PricelistForm, PriceRow, SourceOption } from "./types";
import dayjs from "dayjs";

interface Props {
  pricelist: Pricelist;
  rows: PriceRow[];
  sources: SourceOption[];
}

const props = defineProps<Props>();

const { form, submit } = useForm<PricelistForm>(
  {
    name: props.pricelist?.name ?? "",
    currency: props.pricelist?.currency ?? "",
    rows: props.rows ?? [],
    price_formula: props.pricelist?.price_formula ?? "",
    price_formula_mode: props.pricelist?.price_formula_mode ?? "multiply",
  },
  route("crafter.pricelists.update", [props.pricelist?.id])
);

function exportCsv() {
  window.location.href = route("crafter.pricelists.export-csv", [
    props.pricelist?.id,
  ]);
}

const csvInput = ref<HTMLInputElement | null>(null);

function onCsvSelected(e: Event) {
  const target = e.target as HTMLInputElement;
  const file = target.files?.[0];
  if (!file) return;
  router.post(
    route("crafter.pricelists.import-csv", [props.pricelist?.id]),
    { file },
    {
      forceFormData: true,
      preserveScroll: true,
      onFinish: () => {
        target.value = "";
      },
    }
  );
}
</script>
