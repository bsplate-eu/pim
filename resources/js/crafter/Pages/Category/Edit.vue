<template>
  <PageHeader
    sticky
    :title="$t('crafter', 'Edit Category')"
    :subtitle="`Last updated at ${dayjs(
      category.updated_at
    ).format('DD.MM.YYYY')}`"
  >
    <Button
      :leftIcon="ArrowDownTrayIcon"
      @click="submit"
      :loading="form.processing"
      v-can="'crafter.category.edit'"
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
import type { Category, CategoryForm } from "./types";
import dayjs from "dayjs";



import { useFormLocale } from "crafter/hooks/useFormLocale";


const { availableLocales, currentLocale, translatableDefaultValue, getLabelWithLocale } = useFormLocale();


interface Props {
  category: Category;
  categories: Array<Object>;
}

const props = defineProps<Props>();

const { form, submit } = useForm<CategoryForm>(
    {
          parent_id: props.category?.parent_id ?? "",
name: props.category?.name ?? { ...translatableDefaultValue }
    },
    route("crafter.categories.update", [props.category?.id])
);
</script>
