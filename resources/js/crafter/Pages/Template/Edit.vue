<template>
    <PageHeader
        sticky
        :title="$t('crafter', 'Edit Template')"
        :subtitle="`Last updated at ${dayjs(
      template.updated_at
    ).format('DD.MM.YYYY')}`"
    >

            <Button
                :leftIcon="ArrowDownTrayIcon"
                @click="submit"
                :loading="form.processing"
                v-can="'crafter.template.edit'"
            >
                {{ $t("crafter", "Save") }}
            </Button>

    </PageHeader>

    <Form :form="form" :submit="submit" :available_locales="available_locales" :available_variables="available_variables"/>
</template>

<script setup lang="ts">
import {ArrowDownTrayIcon, EyeIcon} from "@heroicons/vue/24/outline";
import {PageHeader, Button, ButtonGroup} from "crafter/Components";
import {useForm} from "crafter/hooks/useForm";
import Form from "./Form.vue";
import type {Template, TemplateForm} from "./types";
import dayjs from "dayjs";


interface Props {
    template: Template;
    available_locales: string[];
    available_variables: string[];
}

const props = defineProps<Props>();

const {form, submit} = useForm<TemplateForm>(
    {
        locale: props.template?.locale ?? "",
        name: props.template?.name ?? "",
        title: props.template?.title ?? "",
        description: props.template?.description ?? "",
        meta_title: props.template?.meta_title ?? "",
        meta_description: props.template?.meta_description ?? "",
        short_description: props.template?.short_description ?? "",
    },
    route("crafter.templates.update", [props.template?.id])
);


</script>
