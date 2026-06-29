<template>
    <PageHeader
        sticky
        :title="$t('crafter', 'Edit Attribute Value')"
        :subtitle="`Last updated at ${dayjs(
      attributeValue.updated_at
    ).format('DD.MM.YYYY')}`"
    >
        <Button
            :leftIcon="ArrowDownTrayIcon"
            @click="submit"
            :loading="form.processing"
            v-can="'crafter.attribute-value.edit'"
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
import type {AttributeValue, AttributeValueForm} from "./types";
import dayjs from "dayjs";


import {useFormLocale} from "crafter/hooks/useFormLocale";


const {availableLocales, currentLocale, translatableDefaultValue, getLabelWithLocale} = useFormLocale();


interface Props {
    attributeValue: AttributeValue;

}

const props = defineProps<Props>();

const {form, submit} = useForm<AttributeValueForm>(
    {
        attribute_id: props.attributeValue?.attribute_id ?? "",
        name: props.attributeValue?.name ?? {...translatableDefaultValue}
    },
    route("crafter.attribute-values.update", [props.attributeValue?.id])
);
</script>
