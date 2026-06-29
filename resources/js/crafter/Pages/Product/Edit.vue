<template>
    <PageHeader
        sticky
        :title="$t('crafter', 'Edit Product')"
        :subtitle="`Last updated at ${dayjs(
      product.updated_at
    ).format('DD.MM.YYYY')}`"
    >
        <div class="flex gap-2">
            <Button
                @click="showAiModal = true"
                :leftIcon="CursorArrowRippleIcon"
                v-can="'crafter.product.edit'"
            >
                {{ $t("crafter", "AI Tools") }}
            </Button>
            <Button
                :leftIcon="ArrowDownTrayIcon"
                @click="submit"
                :loading="form.processing"
                v-can="'crafter.product.edit'"
            >
                {{ $t("crafter", "Save") }}
            </Button>
        </div>

    </PageHeader>

    <Form :form="form" :attributes="attributes" :categories="categories" :sources="sources" :submit="submit"
          v-model:showAiModal="showAiModal"/>


</template>

<script setup lang="ts">
import { ref } from 'vue';
import {ArrowDownTrayIcon} from "@heroicons/vue/24/outline";
import {PageHeader, Button} from "crafter/Components";
import {useForm} from "crafter/hooks/useForm";
import Form from "./Form.vue";
import type {Product, ProductForm} from "./types";
import dayjs from "dayjs";
import {getMediaCollection} from "crafter/helpers";


import {useFormLocale} from "crafter/hooks/useFormLocale";
import {CursorArrowRippleIcon} from "@heroicons/vue/16/solid";

const showAiModal = ref(false);
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
        source_id: props.product?.source_id ?? "",
        external_id: props.product?.external_id ?? "",
        ean: props.product?.ean ?? "",
        category_ids: props.product?.category_ids ?? [],
        category: props.product?.category ?? "",
        name: props.product?.name ?? {...translatableDefaultValue},
        product_code: props.product?.product_code ?? "",
        width: props.product?.width ?? "",
        weight: props.product?.weight ?? "",
        info_1: props.product?.info_1 ?? {...translatableDefaultValue},
        info_2: props.product?.info_2 ?? {...translatableDefaultValue},
        info_3: props.product?.info_3 ?? {...translatableDefaultValue},
        meta_url: props.product?.meta_url ?? {...translatableDefaultValue},
        meta_title: props.product?.meta_title ?? {...translatableDefaultValue},
        meta_description: props.product?.meta_description ?? {...translatableDefaultValue},
        meta_keywords: props.product?.meta_keywords ?? {...translatableDefaultValue},
        enabled: props.product?.enabled ?? false,
        attribute_values: props.product?.attribute_values ?? {},
        pricelists: props.product?.pricelists ?? [],
        images: getMediaCollection(props.product?.media, 'images')
    },
    route("crafter.products.update", [props.product?.id])
);

</script>
