<template>
  <PageHeader
    sticky
    :title="$t('crafter', 'Edit Ai Tool')"
    :subtitle="`Last updated at ${dayjs(
      aiTool.updated_at
    ).format('DD.MM.YYYY')}`"
  >
    <Button
      :leftIcon="ArrowDownTrayIcon"
      @click="submit"
      :loading="form.processing"
      v-can="'crafter.ai-tool.edit'"
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
import type { AiTool, AiToolForm } from "./types";
import dayjs from "dayjs";



import { useFormLocale } from "crafter/hooks/useFormLocale"; 


const { availableLocales, currentLocale, translatableDefaultValue, getLabelWithLocale } = useFormLocale();
            

interface Props {
  aiTool: AiTool;
  
}

const props = defineProps<Props>();

const { form, submit } = useForm<AiToolForm>(
    {
          name: props.aiTool?.name ?? { ...translatableDefaultValue }, 
description: props.aiTool?.description ?? { ...translatableDefaultValue }, 
provider: props.aiTool?.provider ?? "", 
config: props.aiTool?.config ?? { ...translatableDefaultValue }, 
enabled: props.aiTool?.enabled ?? false, 
order: props.aiTool?.order ?? ""
    },
    route("crafter.ai-tools.update", [props.aiTool?.id])
);
</script>
