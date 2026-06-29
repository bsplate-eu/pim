<template>
    <PageContent>
        <div class="mx-auto w-full">
            <Card>
                <DataGrid
                    v-model="form.rows"
                    :columns="columns"
                    keyField="product_id"
                    height="auto"
                />
            </Card>
        </div>
    </PageContent>
</template>

<script setup lang="ts">
import { Card, PageContent, DataGrid } from "crafter/Components";
import { InertiaForm } from "crafter/types";
import type { IntegrationProductsForm } from "../Integration/types";

interface Props {
    form: InertiaForm<IntegrationProductsForm>;
    submit: void;
}

defineProps<Props>();

const numericCompare = (prop: string, a: any, b: any): number => {
    const av = parseFloat(a?.[prop] ?? 0) || 0;
    const bv = parseFloat(b?.[prop] ?? 0) || 0;
    return av - bv;
};

const columns = [
    { prop: "product_id", name: "PIM ID", readonly: true, size: 90, sortable: true, cellCompare: numericCompare },
    { prop: "product_code", name: "Kod", readonly: true, size: 140, sortable: true },
    { prop: "price", name: "Cena (cennik)", readonly: true, size: 120, sortable: true, cellCompare: numericCompare },
    { prop: "name", name: "Nazwa (bazowa)", readonly: true, size: 280, sortable: true },
    { prop: "override_name", name: "Nazwa (nadpisanie)", size: 280, sortable: true },
    { prop: "ean", name: "EAN", readonly: true, size: 140, sortable: true },
    { prop: "override_ean", name: "EAN (nadpisanie)", size: 150, sortable: true },
    {
        prop: "enabled",
        name: "Aktywny (bazowy)",
        readonly: true,
        size: 130,
        sortable: true,
        cellCompare: numericCompare,
    },
    {
        prop: "override_enabled",
        name: "Aktywny (nadpisanie)",
        size: 160,
        sortable: true,
        cellCompare: numericCompare,
    },
];
</script>
