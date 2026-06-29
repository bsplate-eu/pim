<template>
  <PageContent>
    <div class="w-full">


      <Card>
        <div class="space-y-4">
            <TextInput
                v-model="form.name"
                name="name"
                :label="$t('crafter', 'Name')"

            />

            <TextInput
                v-model="form.service_class"
                name="service_class"
                :label="$t('crafter', 'Service Class')"

            />

            <TextInput
                v-model="form.options"
                name="options"
                :label="$t('crafter', 'Options')"

            />

            <Checkbox
                v-model="form.enabled"
                :checked="form.enabled"
                name="enabled"
                :label="$t('crafter', 'Enabled')"
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
import type { SourceForm } from "./types";



interface Props {
  form: InertiaForm<SourceForm>;
  submit: void;

}

const props = defineProps<Props>();
</script>
