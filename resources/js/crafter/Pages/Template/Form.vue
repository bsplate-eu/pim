<template>

    <PageContent>

        <template #tabs>
            <TabGroup variant="underline">
                <Tab>
                    {{ $t("crafter", "General") }}
                </Tab>
                <Tab>
                    {{ $t("crafter", "Meta") }}
                </Tab>
            </TabGroup>
        </template>

        <TabPanel>
            <Card>
                <div class="space-y-4">
                    <Multiselect
                        v-model="form.locale"
                        mode="single"
                        name="locale"
                        :label="$t('crafter', 'Locale')"
                        :options="available_locales"
                    >
                        <template #singlelabel="{ value }">
                            <LocaleFlag :locale="value.label"/>
                        </template>
                        <template #option="{ option, search }">
                            <LocaleFlag :locale="option.label"/>
                        </template>
                    </Multiselect>
                    <TextInput
                        v-model="form.name"
                        name="name"
                        :label="$t('crafter', 'Template Name')"

                    />

                    <TextInput
                        v-model="form.title"
                        name="title"
                        :label="$t('crafter', 'Title')"
                    />

                    <TextArea
                        v-model="form.short_description"
                        name="short_description"
                        :rows="5"
                        :label="$t('crafter', 'Short Description')"
                    />

                    <TextArea
                        v-model="form.description"
                        name="description"
                        :rows="30"
                        :label="$t('crafter', 'Description')"
                    />
                </div>
            </Card>
        </TabPanel>

        <TabPanel>
            <Card>
                <div class="space-y-4">
                    <TextInput
                        v-model="form.meta_title"
                        name="meta_title"
                        :label="$t('crafter', 'Meta Title')"
                    />

                    <TextArea
                        v-model="form.meta_description"
                        name="meta_description"
                        :rows="5"
                        :label="$t('crafter', 'Meta Description')"
                    />
                </div>
            </Card>
        </TabPanel>

        <Alert type="info" class="mt-4 shadow-lg">
            Available variables: {{ available_variables }}
        </Alert>
    </PageContent>

</template>

<script setup lang="ts">
import {
    Alert,
    Card,
    TextInput,
    TextArea,
    PageContent,
    Multiselect,
    LocaleFlag, TabGroup, Tab
} from "crafter/Components";
import {InertiaForm} from "crafter/types";
import type {TemplateForm} from "./types";
import {TabPanel} from "@headlessui/vue";


interface Props {
    available_locales: string[];
    available_variables: string[];
    form: InertiaForm<TemplateForm>;
    submit: void;

}

const props = defineProps<Props>();
</script>
