<template>
    <PageContent>
        <div class="mx-auto">
            <CardLocaleSwitcher v-model="currentLocale" class="mb-6"/>

            <Card>
                <div class="grid grid-cols-4 gap-4 mb-4">
                    <SelectInput
                        v-model="form.source_id"
                        name="source_id"
                        :label="$t('crafter', 'Source')"
                        :options="sources"
                        options-label="name"
                        options-value-prop="id"
                    />
                    <TextInput
                        v-model="form.external_id"
                        name="external_id"
                        type="text"
                        :label="$t('crafter', 'External Id')"


                    />

                    <TextInput
                        v-model="form.ean"
                        name="ean"
                        :label="$t('crafter', 'EAN')"


                    />

                    <TextInput
                        v-model="form.product_code"
                        name="product_code"
                        :label="$t('crafter', 'Product Code')"


                    />
                </div>
                <div class="grid grid-cols-2 gap-4 mb-4">
                    <div class="flex gap-1 flex-col">
                        <label
                            for="parent_id"
                            class="flex items-center justify-between gap-2 text-sm font-medium text-gray-700"
                        >
                            {{ $t('crafter', 'Categories') }} [TEST]
                        </label>
                        <treeselect
                            v-model="form.category_ids"
                            name="category_ids"
                            :multiple="true"
                            :flat="true"
                            :options="categories"
                            :max-height="500"
                            search-nested
                        />
                    </div>


                    <TextInput
                        v-model="form.category"
                        name="category"
                        :label="$t('crafter', 'Original Category')"
                    />
                </div>
                <div class="grid grid-cols-1 gap-4 mb-4">
                    <div class="flex flex-col gap-1">
                        <TextInput
                            v-model="form.name[currentLocale]"
                            name="name"
                            :label="getLabelWithLocale($t('crafter', 'Name'))"
                            :characters-count="form.name[currentLocale]?.length ?? 0"
                            :max-characters-count="71"
                        />
                    </div>
                </div>

                <div class="grid grid-cols-4 gap-4 mb-4 bg-gray-50 p-4">
                    <template v-for="priceList in form.pricelists">
                        <TextInput
                            v-model="priceList.pivot.price"
                            type="number"
                            step="0.1"
                            name="price"
                            :label="priceList.name"
                            :trailing-addon="priceList.currency"
                        />
                    </template>
                </div>

                <div class="grid grid-cols-2 gap-4 mb-4">
                    <TextInput
                        v-model="form.width"
                        type="number"
                        step="0.1"
                        name="width"
                        :label="$t('crafter', 'Width')"


                    />

                    <TextInput
                        v-model="form.weight"
                        type="number"
                        step="0.1"
                        name="weight"
                        :label="$t('crafter', 'Weight')"


                    />

                </div>
                <div class="grid grid-cols-4 gap-4 mb-4">
                    <template v-for="attribute in attributes">
                        <multiselect v-model="form.attribute_values[attribute.slug]"
                                     :label="attribute.name[page.props.auth.user.locale]"
                                     :name="attribute.slug"
                                     :createOption="true"
                                     mode="tags"
                                     trackBy="id"
                                     :options="attribute.values.map(v => ({value: v.id, label: v.name[currentLocale]}))">
                        </multiselect>
                    </template>

                </div>
                <div class="grid grid-cols-1 gap-4 mb-4">
                    <Wysiwyg
                        v-model="form.info_1[currentLocale]"
                        name="info_1"
                        :label="getLabelWithLocale($t('crafter', 'Info 1'))"
                    />
                    <Wysiwyg
                        v-model="form.info_2[currentLocale]"
                        name="info_2"
                        :label="getLabelWithLocale($t('crafter', 'Info 2'))"
                    />
                    <Wysiwyg
                        v-model="form.info_3[currentLocale]"
                        name="info_3"
                        :label="getLabelWithLocale($t('crafter', 'Info 3'))"
                    />

                    <TextInput
                        v-model="form.meta_url[currentLocale]"
                        name="meta_url"
                        :label="getLabelWithLocale($t('crafter', 'Meta URL'))"
                    />
                    <TextInput
                        v-model="form.meta_title[currentLocale]"
                        name="meta_title"
                        :label="getLabelWithLocale($t('crafter', 'Meta Title'))"
                    />
                    <TextInput
                        v-model="form.meta_description[currentLocale]"
                        name="meta_description"
                        :label="getLabelWithLocale($t('crafter', 'Meta Description'))"
                    />
                    <TextInput
                        v-model="form.meta_keywords[currentLocale]"
                        name="meta_keywords"
                        :label="getLabelWithLocale($t('crafter', 'Meta Keywords'))"
                    />
                    <Dropzone
                        v-model="form.images"
                        name="images"
                        :maxFileSize="2000000"
                        :label="$t('crafter', 'Images')"
                    />

                    <Checkbox
                        name="enabled"
                        :label="$t('crafter', 'Enabled')"
                        :checked="form.enabled"
                        v-model="form.enabled"
                    />
                </div>


            </Card>
        </div>
    </PageContent>

    <AiToolsModal
        v-model="showAiModal"
        :form="form"
        @tool-executed="handleToolExecuted"
    />
</template>

<script setup lang="ts">
import AiToolsModal from './AiToolsModal.vue';
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
    CardLocaleSwitcher, Button, FormControl
} from "crafter/Components";
import {InertiaForm} from "crafter/types";
import type {ProductForm} from "./types";
import {usePage} from "@inertiajs/vue3";
import {computed, unref} from 'vue';
import {useFormLocale} from "crafter/hooks/useFormLocale";
import {SelectInput} from "@/crafter/Components";

import Treeselect from "@zanmato/vue3-treeselect";
import "@zanmato/vue3-treeselect/dist/vue3-treeselect.min.css";

const {availableLocales, currentLocale, translatableDefaultValue, getLabelWithLocale} = useFormLocale();


interface Props {
    form: InertiaForm<ProductForm>;
    attributes: Array<any>;
    categories: Array<Object>;
    sources: Array<any>;
    submit: void;
    showAiModal: boolean;

}

const page = usePage();
const props = defineProps<Props>();

const emit = defineEmits<{
    'update:showAiModal': [value: boolean]
}>();


const showAiModal = computed({
    get: () => props.showAiModal,
    set: (value: boolean) => emit('update:showAiModal', value)
});


const handleToolExecuted = (result: any) => {
    console.log('AI tool executed:', result);

    if (result.output_fields) {
        Object.keys(result.output_fields).forEach(field => {
            if (props.form[field] !== undefined) {
                // Bezpośrednie przypisanie - wartości są już przetworzone w modal
                props.form[field] = result.output_fields[field];
            }
        });

        // Opcjonalnie: pokaż powiadomienie o sukcesie
        console.log('Formularz zaktualizowany przez AI');
    }
};


</script>
