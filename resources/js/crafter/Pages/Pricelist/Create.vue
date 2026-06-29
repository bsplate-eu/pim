<template>
  <PageHeader sticky :title="$t('crafter', 'Create Pricelist')">
    <Button
      :leftIcon="ArrowDownTrayIcon"
      @click="submit"
      :loading="form.processing"
      v-can="'crafter.pricelist.create'"
    >
      {{ $t("crafter", "Save") }}
    </Button>
  </PageHeader>

  <Form :form="form" :submit="submit"  />
</template>

<script setup lang="ts">
import { ArrowDownTrayIcon } from "@heroicons/vue/24/outline";
import { PageHeader, Button } from "crafter/Components";
import { useForm } from "crafter/hooks/useForm";
import Form from "./Form.vue";
import type { PricelistForm } from "./types";



interface Props {
  
}

const props = defineProps<Props>();

const { form, submit } = useForm<PricelistForm>(
    {
          name: "", 
currency: ""
    },
    route("crafter.pricelists.store"),
    "post"
);
</script>
