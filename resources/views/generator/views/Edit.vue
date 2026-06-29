<template>
  <PageHeader
    sticky
    :title="$t('crafter', 'Edit [[modelName]]')"
    :subtitle="`Last updated at ${dayjs(
      [[modelNameLowerCase]].updated_at
    ).format('DD.MM.YYYY')}`"
  >
    <Button
      :leftIcon="ArrowDownTrayIcon"
      @click="submit"
      :loading="form.processing"
      v-can="'crafter.[[modelPermissionName]].edit'"
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
import type { [[modelIndexName]], [[modelIndexName]]Form } from "./types";
import dayjs from "dayjs";
[[editVueImports]]

[[translatableFunctionality]]

interface Props {
  [[modelNameLowerCase]]: [[modelIndexName]];
  [[relationsProps]]
}

const props = defineProps<Props>();

const { form, submit } = useForm<[[modelIndexName]]Form>(
    {
          [[editFormColumns]]
    },
    route("[[modelUpdateRoute]]", [props.[[modelNameLowerCase]]?.id])
);
</script>
