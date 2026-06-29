<template>
    <PageHeader
        sticky
        :title="$t('crafter', 'Edit Attribute')"
        :subtitle="`Last updated at ${dayjs(
      attribute.updated_at
    ).format('DD.MM.YYYY')}`"
    >
        <Button
            :leftIcon="ArrowDownTrayIcon"
            @click="submit"
            :loading="form.processing"
            v-can="'crafter.attribute.edit'"
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
import type {Attribute, AttributeForm} from "./types";
import dayjs from "dayjs";


import {useFormLocale} from "crafter/hooks/useFormLocale";


const {availableLocales, currentLocale, translatableDefaultValue, getLabelWithLocale} = useFormLocale();


interface Props {
    attribute: Attribute;

}

const props = defineProps<Props>();

const {form, submit} = useForm<AttributeForm>(
    {
        name: props.attribute?.name ?? {...translatableDefaultValue},
        attribute_values: props.attribute?.values ?? [],
    },
    route("crafter.attributes.update", [props.attribute?.id])
);
</script>
