<template>
  <PageHeader sticky :title="$t('crafter', 'Create Ai Tool')">
    <Button
      :leftIcon="ArrowDownTrayIcon"
      @click="submit"
      :loading="form.processing"
      v-can="'crafter.ai-tool.create'"
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
import type { AiToolForm } from "./types";


import { useFormLocale } from "crafter/hooks/useFormLocale"; 


const { availableLocales, currentLocale, translatableDefaultValue, getLabelWithLocale } = useFormLocale();
            

interface Props {
  
}

const props = defineProps<Props>();

const { form, submit } = useForm<AiToolForm>(
    {
          name: { ...translatableDefaultValue }, 
description: { ...translatableDefaultValue }, 
provider: "", 
config: { ...translatableDefaultValue }, 
enabled: false, 
order: ""
    },
    route("crafter.ai-tools.store"),
    "post"
);
</script>
