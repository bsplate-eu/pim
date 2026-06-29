<template>
  <PageHeader
    sticky
    :title="$t('crafter', 'Edit Integration Products')"
    :subtitle="`Last updated at ${dayjs(
      integration.updated_at
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
      v-can="'crafter.integration-product.edit'"
    >
      Aktualizuj z CSV
    </Button>
    <Button
      :leftIcon="ArrowDownTrayIcon"
      @click="exportCsv"
      v-can="'crafter.integration-product.edit'"
    >
      Eksport CSV
    </Button>
    <Button
      :leftIcon="ArrowDownTrayIcon"
      @click="submit"
      :loading="form.processing"
      v-can="'crafter.integration-product.edit'"
    >
      {{ $t("crafter", "Save") }}
    </Button>
  </PageHeader>

  <Form :form="form" :submit="submit" />
</template>

<script setup lang="ts">
import { ref } from "vue";
import { router } from "@inertiajs/vue3";
import { ArrowDownTrayIcon, ArrowUpTrayIcon } from "@heroicons/vue/24/outline";
import { PageHeader, Button } from "crafter/Components";
import { useForm } from "crafter/hooks/useForm";
import Form from "./Form.vue";
import dayjs from "dayjs";
import { Integration, IntegrationProductsForm, OverrideRow } from "crafter/Pages/Integration/types";

interface Props {
  integration: Integration;
  rows: OverrideRow[];
}

const props = defineProps<Props>();

const { form, submit } = useForm<IntegrationProductsForm>(
  {
    rows: props.rows ?? [],
  },
  route("crafter.integration-products.update", [props.integration?.id])
);

function exportCsv() {
  window.location.href = route("crafter.integration-products.export-csv", [
    props.integration?.id,
  ]);
}

const csvInput = ref<HTMLInputElement | null>(null);

function onCsvSelected(e: Event) {
  const target = e.target as HTMLInputElement;
  const file = target.files?.[0];
  if (!file) return;
  router.post(
    route("crafter.integration-products.import-csv", [props.integration?.id]),
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
