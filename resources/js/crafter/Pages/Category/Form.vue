<template>
  <PageContent>
    <div class="w-full">
      <CardLocaleSwitcher v-model="currentLocale" class="mb-6" />

      <Card>
        <div class="space-y-4">
            <div class="flex gap-1 flex-col">
                <label
                    for="parent_id"
                    class="flex items-center justify-between gap-2 text-sm font-medium text-gray-700"
                >
                    {{ $t('crafter', 'Parent') }}
                </label>

                <Treeselect v-model="form.parent_id" :multiple="false" :options="categories" />

            </div>
            <TextInput
                v-model="form.name[currentLocale]"
                name="name"
                :label="getLabelWithLocale($t('crafter', 'Name'))"

            /></div>
      </Card>
    </div>
  </PageContent>
</template>

<script setup lang="ts">
import {
    Card,
    TextInput,
    Wysiwyg,
    TextArea,
    PageContent,
    DatePicker,
    Checkbox,
    Dropzone,
    ImageUpload,
    Multiselect,
    CardLocaleSwitcher
} from "crafter/Components";
import { InertiaForm } from "crafter/types";
import type { CategoryForm } from "./types";
import Treeselect from "@zanmato/vue3-treeselect";
import "@zanmato/vue3-treeselect/dist/vue3-treeselect.min.css";

import { useFormLocale } from "crafter/hooks/useFormLocale";
import LocaleFlag from "../../Components/LocaleFlag.vue";


const { availableLocales, currentLocale, translatableDefaultValue, getLabelWithLocale } = useFormLocale();


interface Props {
  form: InertiaForm<CategoryForm>;
  categories: Array<Object>;
  submit: void;

}

const props = defineProps<Props>();
</script>
