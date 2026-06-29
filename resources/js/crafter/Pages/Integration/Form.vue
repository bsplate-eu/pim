<template>
    <PageContent>
        <div class="w-full">


            <Card :title="$t('crafter', 'Integration')" class="mb-6">
                <div class="grid grid-cols-2 gap-4 mb-4">

                    <TextInput
                        v-model="form.name"
                        name="name"
                        :label="$t('crafter', 'Name')"

                    />

                    <Multiselect
                        v-model="form.category_id"
                        name="category_id"
                        :label="$t('crafter', 'Category Tree')"
                        :options="categoryOptions"
                        mode="single"
                    />
                </div>
                <div class="grid grid-cols-2 gap-4 mb-4">

                    <Multiselect
                        v-model="form.type"
                        name="type"
                        :label="$t('crafter', 'Type')"
                        :options="typeOptions"
                        mode="single"
                    />
                    <TextInput
                        v-model="form.manufacturer"
                        name="manufacturer"
                        :label="$t('crafter', 'Manufacturer')"

                    />
                </div>
                <div class="grid grid-cols-2 gap-4 mb-4">
                    <TextInput
                        v-if="isNumber(form.id) || requiresCredentials"
                        v-model="form.url"
                        :disabled="!requiresCredentials"
                        name="url"
                        :label="$t('crafter', 'URL')"
                    />
                    <TextInput
                        v-if="isNumber(form.id) || requiresCredentials"
                        v-model="form.key"
                        :disabled="!requiresCredentials"
                        name="key"
                        :label="$t('crafter', 'Key / API Token')"

                    />
                </div>

                <div class="grid grid-cols-1">
                    <Checkbox
                        name="enabled"
                        :label="$t('crafter', 'Enabled')"
                        v-model="form.enabled"
                    />
                </div>
            </Card>

            <Card :title="$t('crafter', 'Sources') + ' (' + form.integration_sources.length + ')'">
                <template #actions>
                    <IconButton size="sm" :icon="PlusIcon" class="mt-3" @click="addNewSource"/>
                </template>


                <div v-for="(integration_source, index) in form.integration_sources" :key="index" class="">

                        <div class="grid grid-cols-7 gap-4 mb-4">
                            <SelectInput
                                v-model="integration_source.source_id"
                                name="source_id"
                                :label="$t('crafter', 'Source')"
                                :options="sourceOptions"
                                mode="single"
                            />

                            <SelectInput
                                v-model="integration_source.template_id"
                                name="template_id"
                                :label="$t('crafter', 'Template')"
                                :options="templateOptions"
                                mode="single"
                            />

                            <SelectInput
                                v-model="integration_source.pricelist_id"
                                name="pricelist_id"
                                :label="$t('crafter', 'Pricelist')"
                                :options="pricelistOptions"
                                mode="single"
                            />

                            <!-- TODO: enable when Blog module exists (App\Models\Blog) -->
                            <!-- <SelectInput
                                v-model="integration_source.blog_id"
                                name="blog_id"
                                label="Blog"
                                :options="[{value: null, label: '— brak —'}, ...blogOptions]"
                                mode="single"
                            /> -->

                            <TextInput
                                type="number"
                                v-model="integration_source.tax"
                                name="tax"
                                :label="$t('crafter', 'Tax')"
                            />

                            <TextInput
                                type="number"
                                v-model="integration_source.multiplier"
                                name="multiplier"
                                :label="$t('crafter', 'Multiplier')"
                            />
                            <div class="flex-1 content-end">
                                <IconButton size="md" :icon="TrashIcon" @click="removeSource(index)" class="bg-red-700 hover:bg-red-500 text-white font-semibold py-2 px-4 rounded focus:outline-none focus:ring-2 focus:ring-red-300"></IconButton>
                            </div>
                        </div>
                    <hr class="mb-4">


                </div>

            </Card>
        </div>
    </PageContent>
</template>

<script setup lang="ts">
import {
    Card,
    TextInput,
    Toggle,
    TextArea,
    PageContent,
    DatePicker,
    Checkbox,
    Dropzone,
    ImageUpload,
    Multiselect,
    CardLocaleSwitcher, IconButton
} from "crafter/Components";
import {InertiaForm} from "crafter/types";
import {isNumber} from "lodash";
import {computed, ref, defineProps, onMounted} from 'vue';
import {Integration, IntegrationForm, IntegrationSource} from "./types";
import {PlusIcon, TrashIcon} from "@heroicons/vue/20/solid";
import {SelectInput} from "@/crafter/Components";
interface Props {
    form: InertiaForm<IntegrationForm>;
    integration: Integration<Integration>;
    submit: void;
    typeOptions: Array<{ value: string | number, label: string }>;
    pricelistOptions: Array<{ value: string | number, label: string }>;
    templateOptions: Array<{ value: string | number, label: string }>
    sourceOptions: Array<{ value: string | number, label: string }>
    categoryOptions: Array<{ value: string | number, label: string }>
    blogOptions: Array<{ value: string | number | null, label: string }>
}

const props = defineProps<Props>();
const requiresCredentials = computed(() => ['prestashop', 'litecart', 'opencart'].includes(props.form.type));
function addNewSource() {
    props.form.integration_sources.push({} as IntegrationSource);
}

function removeSource(index: number) {
    props.form.integration_sources.splice(index, 1);
}
</script>

<style scoped>
.loader {
    border: 4px solid #f3f3f3;
    border-top: 4px solid #3498db;
    border-radius: 50%;
    width: 40px;
    height: 40px;
    animation: spin 2s linear infinite;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}
</style>
