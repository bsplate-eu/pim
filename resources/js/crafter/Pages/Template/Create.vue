<template>
    <PageHeader sticky :title="$t('crafter', 'Create Template')">
        <Button
            :leftIcon="ArrowDownTrayIcon"
            @click="submit"
            :loading="form.processing"
            v-can="'crafter.template.create'"
        >
            {{ $t("crafter", "Save") }}
        </Button>
    </PageHeader>

    <Form :form="form" :submit="submit" :available_locales="available_locales" :available_variables="available_variables"/>
</template>

<script setup lang="ts">
import {ArrowDownTrayIcon} from "@heroicons/vue/24/outline";
import {PageHeader, Button} from "crafter/Components";
import {useForm} from "crafter/hooks/useForm";
import Form from "./Form.vue";
import type {TemplateForm} from "./types";


interface Props {
    available_locales: string[];
    available_variables: string[];
}

const props = defineProps<Props>();

const {form, submit} = useForm<TemplateForm>(
    {
        locale: "en",
        name: "",
        title: "",
        description: "",
        meta_title: "",
        meta_description: "",
        short_description: "",
    },
    route("crafter.templates.store"),
    "post"
);
</script>
