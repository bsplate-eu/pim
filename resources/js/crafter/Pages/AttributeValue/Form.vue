<template>
  <PageContent>
    <div class="w-full">
      <CardLocaleSwitcher v-model="currentLocale" class="mb-6" />

      <Card>
        <div class="space-y-4">
            <TextInput
                v-model="form.attribute_id"
                name="attribute_id"
                :label="$t('crafter', 'Attribute Id')"
                
            /> 

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
import type { AttributeValueForm } from "./types";


import { useFormLocale } from "crafter/hooks/useFormLocale"; 


const { availableLocales, currentLocale, translatableDefaultValue, getLabelWithLocale } = useFormLocale();
            

interface Props {
  form: InertiaForm<AttributeValueForm>;
  submit: void;
  
}

const props = defineProps<Props>();
</script>
