<template>
  <PageHeader sticky :title="$t('crafter', 'Create Integration Product')">
    <Button
      :leftIcon="ArrowDownTrayIcon"
      @click="submit"
      :loading="form.processing"
      v-can="'crafter.integration-product.create'"
    >
      {{ $t("crafter", "Save") }}
    </Button>
  </PageHeader>

  <Form :form="form" :submit="submit" :integrationOptions="integrationOptions" :productOptions="productOptions" />
</template>

<script setup lang="ts">
import { ArrowDownTrayIcon } from "@heroicons/vue/24/outline";
import { PageHeader, Button } from "crafter/Components";
import { useForm } from "crafter/hooks/useForm";
import Form from "./Form.vue";
import type { IntegrationProduct, IntegrationProductForm } from "./types";
import dayjs from "dayjs";
import {getMediaCollection} from "crafter/helpers";
import {Integration} from "crafter/Pages/Integration/types";
import {useFormLocale} from "crafter/hooks/useFormLocale";



interface Props {
    integration: Integration;
    integrationProduct: IntegrationProduct;
    product: product;
    locale: locale;

}
const { availableLocales, currentLocale, translatableDefaultValue, getLabelWithLocale } = useFormLocale();
const props = defineProps<Props>();

const { form, submit } = useForm<IntegrationProductForm>(
    {
        external_id: props.product?.external_id ?? "",
        category: props.product?.category ?? "",
        name: props.product?.name ?? { ...translatableDefaultValue },
        secondary_name: props.product?.secondary_name ?? "",
        product_code: props.product?.product_code ?? "",
        price: props.product?.price ?? "",
        year_start: props.product?.year_start ?? "",
        year_stop: props.product?.year_stop ?? "",
        width: props.product?.width ?? "",
        weight: props.product?.weight ?? "",
        oil: props.product?.oil ?? false,
        engine: props.product?.engine ?? "",
        gearbox: props.product?.gearbox ?? "",
        related_products: props.product?.related_products ?? "",
        comment: props.product?.comment ?? "",
        protection: props.product?.protection ?? { ...translatableDefaultValue },
    },
    route("crafter.integration-products.store", [props.integration?.id])
);
</script>
