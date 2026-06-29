<template>
  <PageHeader sticky :title="$t('crafter', 'Create Source')">
    <Button
      :leftIcon="ArrowDownTrayIcon"
      @click="submit"
      :loading="form.processing"
      v-can="'crafter.source.create'"
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
import type { SourceForm } from "./types";



interface Props {
  
}

const props = defineProps<Props>();

const { form, submit } = useForm<SourceForm>(
    {
          name: "", 
service_class: "", 
options: "", 
enabled: false
    },
    route("crafter.sources.store"),
    "post"
);
</script>
