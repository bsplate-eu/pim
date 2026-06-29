<template>
  <PageHeader sticky :title="$t('crafter', 'Create Category')">
    <Button
      :leftIcon="ArrowDownTrayIcon"
      @click="submit"
      :loading="form.processing"
      v-can="'crafter.category.create'"
    >
      {{ $t("crafter", "Save") }}
    </Button>
  </PageHeader>

  <Form :form="form" :categories="categories" :submit="submit"  />
</template>

<script setup lang="ts">
import { ArrowDownTrayIcon } from "@heroicons/vue/24/outline";
import { PageHeader, Button } from "crafter/Components";
import { useForm } from "crafter/hooks/useForm";
import Form from "./Form.vue";
import type { CategoryForm } from "./types";


import { useFormLocale } from "crafter/hooks/useFormLocale";


const { availableLocales, currentLocale, translatableDefaultValue, getLabelWithLocale } = useFormLocale();


interface Props {
    categories: Array<Object>;
}

const props = defineProps<Props>();

const { form, submit } = useForm<CategoryForm>(
    {
          parent_id: "",
name: { ...translatableDefaultValue }
    },
    route("crafter.categories.store"),
    "post"
);
</script>
