<template>
    <PageHeader sticky :title="$t('crafter', 'Create Product')">
        <Button
            :leftIcon="ArrowDownTrayIcon"
            @click="submit"
            :loading="form.processing"
            v-can="'crafter.product.create'"
        >
            {{ $t("crafter", "Save") }}
        </Button>
    </PageHeader>

    <Form :form="form"  :attributes="attributes" :categories="categories" :sources="sources" :submit="submit"/>
</template>

<script setup lang="ts">
import {ArrowDownTrayIcon} from "@heroicons/vue/24/outline";
import {PageHeader, Button} from "crafter/Components";
import {useForm} from "crafter/hooks/useForm";
import Form from "./Form.vue";
import type {Product, ProductForm} from "./types";
import random from "lodash/random";


import {useFormLocale} from "crafter/hooks/useFormLocale";


const {availableLocales, currentLocale, translatableDefaultValue, getLabelWithLocale} = useFormLocale();


interface Props {
    product: Product;
    attributes: Array<any>;
    categories: Array<Object>;
    sources: Array<any>;
}

const props = defineProps<Props>();

const {form, submit} = useForm<ProductForm>(
    {
        external_id: random(1000000, 9000000),
        source_id: "",
        ean: "",
        category_ids: [],
        category: "",
        name: {...translatableDefaultValue},
        product_code: "",
        width: "",
        weight: "",
        info_1: {...translatableDefaultValue},
        info_2: {...translatableDefaultValue},
        info_3: {...translatableDefaultValue},
        meta_url: {...translatableDefaultValue},
        meta_title: {...translatableDefaultValue},
        meta_description: {...translatableDefaultValue},
        meta_keywords: {...translatableDefaultValue},
        attribute_values: {},
        pricelists: props.product?.pricelists ?? [],
        images: [],
        enabled: true
    },
    route("crafter.products.store"),
    "post"
);
</script>
