<template>
    <PageHeader
        sticky
        :title="$t('crafter', 'Edit Integration')"
        :subtitle="`Last updated at ${dayjs(
      integration.updated_at
    ).format('DD.MM.YYYY')}`"
    >
        <Button
            :leftIcon="ArrowDownTrayIcon"
            @click="submit"
            :loading="form.processing"
            v-can="'crafter.integration.edit'"
        >
            {{ $t("crafter", "Save") }}
        </Button>
    </PageHeader>

    <Form :form="form" :submit="submit" :integration="integration" :pricelistOptions="pricelistOptions"
          :templateOptions="templateOptions" :typeOptions="typeOptions" :sourceOptions="sourceOptions"
          :categoryOptions="categoryOptions" :blogOptions="blogOptions"/>
</template>

<script setup lang="ts">
import {ArrowDownTrayIcon} from "@heroicons/vue/24/outline";
import {PageHeader, Button} from "crafter/Components";
import {useForm} from "crafter/hooks/useForm";
import Form from "./Form.vue";
import type {Integration, IntegrationForm} from "./types";
import dayjs from "dayjs";

interface Props {
    integration: Integration;
    typeOptions: Array<{ value: string | number, label: string }>;
    pricelistOptions: Array<{ value: string | number, label: string }>;
    templateOptions: Array<{ value: string | number, label: string }>;
    sourceOptions: Array<{ value: string | number, label: string }>;
    categoryOptions: Array<{ value: string | number, label: string }>
    blogOptions: Array<{ value: string | number | null, label: string }>
}

const props = defineProps<Props>();

const {form, submit} = useForm<IntegrationForm>(
    props.integration,
    route("crafter.integrations.update", [props.integration?.id])
);
</script>
