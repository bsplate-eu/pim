<template>
    <PageHeader sticky :title="$t('crafter', 'Export/Import Products')"/>

    <PageContent>
    <div class="w-full">
            <Card>
                <RadioGroup
                    v-model="form.type"
                    name="type"
                    label="Choose an option"
                    class="mb-7"
                    :options="[
      {
        value: 'export',
        label: 'Export',
      },
      {
        value: 'import',
        label: 'Import',
      },
    ]"
                />
                <div class="grid grid-cols-2 gap-4 mb-4">

                    <Dropzone
                        v-if="form.type === 'import'"
                        v-model="form.files"
                        name="file"
                        label="Select File"
                        accept=".xlsx"
                        :maxNumberOfFiles="1"
                        @lyThumbs="true"
                        :maxFileSize="2097152"
                        :centered="false"

                    />
                    <SelectInput
                        v-if="form.type === 'export'"
                        v-model="form.source_id"
                        name="source_id"
                        :label="$t('crafter', 'Source')"
                        :options="sources"
                        options-label="name"
                        options-value-prop="id"
                    />
                    <Multiselect
                        v-if="form.type !== ''"
                        v-model="form.locale"
                        mode="single"
                        name="locale"
                        :label="$t('crafter', 'Locale')"
                        :options="available_locales"
                    >
                        <template #singlelabel="{ value }">
                            <LocaleFlag :locale="value.label" />
                        </template>
                        <template #option="{ option, search }">
                            <LocaleFlag :locale="option.label" />
                        </template>
                    </Multiselect>
                </div>

                <Button
                    v-if="form.type === 'import'"
                    :leftIcon="ArrowUpTrayIcon"
                    @click="submit"
                    :loading="form.processing"
                    v-can="'crafter.product.create'"
                >
                    {{ $t("crafter", "Import") }}
                </Button>

                <Button
                    v-if="form.type === 'export'"
                    :leftIcon="ArrowDownTrayIcon"
                    @click="exportFile"
                    :loading="form.processing"
                    v-can="'crafter.product.create'"
                >
                    {{ $t("crafter", "Export") }}
                </Button>
            </Card>
    </div>

    </PageContent>
</template>

<script setup lang="ts">
import {ArrowDownTrayIcon} from "@heroicons/vue/24/outline";
import {
    RadioGroup,
    PageHeader,
    Button,
    Dropzone,
    Multiselect,
    SelectInput,
    Card,
    LocaleFlag
} from "crafter/Components";
import {useForm} from "crafter/hooks/useForm";
import {ArrowUpTrayIcon} from "@heroicons/vue/16/solid";
import type {UploadedFile} from "@/crafter/types";


interface Props {
    sources: Array<any>;
    available_locales: Array<any>;
}

const props = defineProps<Props>();


const {form, submit} = useForm<any>(
    {
        type: "export",
        source_id: "",
        locale: "",
        files: []
    },
    route("crafter.products.import"),
    "post"
);
const exportFile = () => {
    window.location.href = route('crafter.products.export', {
        type: 'export',
        source_id: form.source_id,
        locale: form.locale
    });
};
</script>
