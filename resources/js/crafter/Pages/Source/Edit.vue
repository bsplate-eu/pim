<template>
  <PageHeader
    sticky
    :title="$t('crafter', 'Edit Source')"
    :subtitle="`Last updated at ${dayjs(
      source.updated_at
    ).format('DD.MM.YYYY')}`"
  >
    <Button
      :leftIcon="ArrowDownTrayIcon"
      @click="submit"
      :loading="form.processing"
      v-can="'crafter.source.edit'"
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
import type { Source, SourceForm } from "./types";
import dayjs from "dayjs";




interface Props {
  source: Source;
  
}

const props = defineProps<Props>();

const { form, submit } = useForm<SourceForm>(
    {
          name: props.source?.name ?? "", 
service_class: props.source?.service_class ?? "", 
options: props.source?.options ?? "", 
enabled: props.source?.enabled ?? false
    },
    route("crafter.sources.update", [props.source?.id])
);
</script>
