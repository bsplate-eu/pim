<template>
    <PageHeader sticky :title="$t('crafter', 'Create Attribute')">
        <Button
            :leftIcon="ArrowDownTrayIcon"
            @click="submit"
            :loading="form.processing"
            v-can="'crafter.attribute.create'"
        >
            {{ $t("crafter", "Save") }}
        </Button>
    </PageHeader>

    <Form :form="form" :submit="submit"/>
</template>

<script setup lang="ts">
import {ArrowDownTrayIcon} from "@heroicons/vue/24/outline";
import {PageHeader, Button} from "crafter/Components";
import {useForm} from "crafter/hooks/useForm";
import Form from "./Form.vue";
import type {AttributeForm} from "./types";


import {useFormLocale} from "crafter/hooks/useFormLocale";


const {availableLocales, currentLocale, translatableDefaultValue, getLabelWithLocale} = useFormLocale();


interface Props {

}

const props = defineProps<Props>();

const {form, submit} = useForm<AttributeForm>(
    {
        name: {...translatableDefaultValue},
        attribute_values: [],
    },
    route("crafter.attributes.store"),
    "post"
);
</script>
