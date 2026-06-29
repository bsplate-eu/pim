<template>
  <PageHeader sticky :title="$t('crafter', 'Create [[modelName]]')">
    <Button
      :leftIcon="ArrowDownTrayIcon"
      @click="submit"
      :loading="form.processing"
      v-can="'crafter.[[modelPermissionName]].create'"
    >
      {{ $t("crafter", "Save") }}
    </Button>
  </PageHeader>

  <Form :form="form" :submit="submit" [[relationsFormProps]] />
</template>

<script setup lang="ts">
import { ArrowDownTrayIcon } from "@heroicons/vue/24/outline";
import { PageHeader, Button } from "crafter/Components";
import { useForm } from "crafter/hooks/useForm";
import Form from "./Form.vue";
import type { [[modelIndexName]]Form } from "./types";

[[translatableFunctionality]]

interface Props {
  [[relationsProps]]
}

const props = defineProps<Props>();

const { form, submit } = useForm<[[modelIndexName]]Form>(
    {
          [[createFormColumns]]
    },
    route("[[modelStoreRoute]]"),
    "post"
);
</script>
