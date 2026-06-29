<template>
    <PageHeader sticky :title="$t('crafter', 'Create Integration')">
        <Button
            :leftIcon="ArrowDownTrayIcon"
            @click="submit"
            :loading="form.processing"
            v-can="'crafter.integration.create'"
        >
            {{ $t("crafter", "Save") }}
        </Button>
    </PageHeader>

    <Form :form="form" :submit="submit" :pricelistOptions="pricelistOptions" :templateOptions="templateOptions"
          :typeOptions="typeOptions" :sourceOptions="sourceOptions" :categoryOptions="categoryOptions"
          :blogOptions="blogOptions"/>
</template>

<script setup lang="ts">
import {ArrowDownTrayIcon} from "@heroicons/vue/24/outline";
import {PageHeader, Button} from "crafter/Components";
import {useForm} from "crafter/hooks/useForm";
import Form from "./Form.vue";
import {type Integration, IntegrationForm, IntegrationSource} from "./types";


interface Props {
    typeOptions: Array<{ value: string | number, label: string }>;
    pricelistOptions: Array<{ value: string | number, label: string }>;
    templateOptions: Array<{ value: string | number, label: string }>;
    sourceOptions: Array<{ value: string | number, label: string }>;
    categoryOptions: Array<{ value: string | number, label: string }>
    blogOptions: Array<{ value: string | number | null, label: string }>
}

const props = defineProps<Props>();

const {form, submit} = useForm<IntegrationForm>(
    {
        type: "",
        name: "",
        manufacturer: "Pareto",
        url: "",
        key: "",
        integration_sources: [{} as IntegrationSource],
        enabled: true,
    },
    route("crafter.integrations.store"),
    "post"
);
</script>
