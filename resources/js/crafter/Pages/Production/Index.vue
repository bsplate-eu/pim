<template>
    <PageHeader
        sticky
        title="Produkcja"
        subtitle="Wszystkie kody z bazy PIM"
    />

    <PageContent>
        <div class="w-full">
            <Card>
                <div class="mb-3 text-xs text-gray-500">
                    Widocznych: <strong>{{ visibleCount }}</strong>
                    / Wszystkich: <strong>{{ totalCount }}</strong>
                </div>

                <div class="mb-3 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                    <TextInput
                        v-model="search.code"
                        name="search_code"
                        label="Kod"
                        placeholder="np. 00.004"
                        clearable
                    />
                    <TextInput
                        v-model="search.name"
                        name="search_name"
                        label="Nazwa"
                        placeholder="fragment nazwy"
                        clearable
                    />
                    <TextInput
                        v-model="search.ean"
                        name="search_ean"
                        label="EAN"
                        placeholder="fragment EAN"
                        clearable
                    />
                    <SelectInput
                        v-model="search.material"
                        name="search_material"
                        label="Materiał"
                        :options="materialOptions"
                    />
                </div>

                <DataGrid
                    ref="gridRef"
                    v-model="rows"
                    :columns="columns"
                    :filter="filterFn"
                    keyField="product_id"
                    height="auto"
                />
            </Card>
        </div>
    </PageContent>
</template>

<script setup lang="ts">
import { computed, reactive, ref } from "vue";
import {
    Card,
    DataGrid,
    PageContent,
    PageHeader,
    SelectInput,
    TextInput,
} from "crafter/Components";

interface ProductionRow {
    product_id: number;
    product_code: string;
    name: string;
    ean: string;
    width: number | null;
    weight: number | null;
    material: string;
    enabled: boolean;
}

interface Props {
    rows: ProductionRow[];
}

const props = defineProps<Props>();

// Kopia lokalna — DataGrid pracuje na v-model (props Inertii sa zamrozone).
const rows = ref<ProductionRow[]>([...props.rows]);

const numericCompare = (prop: string, a: any, b: any): number => {
    const av = parseFloat(a?.[prop] ?? 0) || 0;
    const bv = parseFloat(b?.[prop] ?? 0) || 0;
    return av - bv;
};

const decimalCell = (h: any, p: any, prop: string): any => {
    const value = p.model?.[prop];
    if (value === null || value === undefined || value === "") {
        return h("span", { style: { color: "#9ca3af" } }, "—");
    }
    return h("span", {}, (parseFloat(value) || 0).toFixed(2));
};

const columns = [
    { prop: "product_code", name: "Kod", readonly: true, size: 150, sortable: true },
    { prop: "name", name: "Nazwa", readonly: true, size: 420, sortable: true },
    { prop: "ean", name: "EAN", readonly: true, size: 160, sortable: true },
    { prop: "material", name: "Materiał", readonly: true, size: 130, sortable: true },
    {
        prop: "width",
        name: "Szerokość",
        readonly: true,
        size: 120,
        sortable: true,
        cellCompare: numericCompare,
        cellTemplate: (h: any, p: any) => decimalCell(h, p, "width"),
    },
    {
        prop: "weight",
        name: "Waga",
        readonly: true,
        size: 110,
        sortable: true,
        cellCompare: numericCompare,
        cellTemplate: (h: any, p: any) => decimalCell(h, p, "weight"),
    },
    {
        prop: "enabled",
        name: "Status",
        readonly: true,
        size: 120,
        sortable: true,
        cellTemplate: (h: any, p: any) =>
            p.model?.enabled
                ? h("span", {}, "Aktywny")
                : h("span", { style: { color: "#b91c1c" } }, "Wyłączony"),
    },
];

// === WYSZUKIWARKA ===
const search = reactive({
    code: "",
    name: "",
    ean: "",
    material: "all",
});

// Lista materialow budowana z danych — nie ma osobnego zrodla slownika na tym ekranie.
const materialOptions = computed<Array<{ value: string; label: string }>>(() => {
    const found = new Set<string>();
    props.rows.forEach((row) => {
        if (row.material) found.add(row.material);
    });
    return [
        { value: "all", label: "Wszystkie" },
        ...Array.from(found)
            .sort()
            .map((material) => ({ value: material, label: material })),
        { value: "none", label: "Bez materiału" },
    ];
});

const filterFn = computed<((row: any) => boolean) | null>(() => {
    const code = String(search.code ?? "").trim().toLowerCase();
    const name = String(search.name ?? "").trim().toLowerCase();
    const ean = String(search.ean ?? "").trim().toLowerCase();
    const material = String(search.material ?? "all");

    if (!code && !name && !ean && material === "all") return null;

    return (row: any) => {
        if (code && !String(row.product_code ?? "").toLowerCase().includes(code)) {
            return false;
        }
        if (name && !String(row.name ?? "").toLowerCase().includes(name)) {
            return false;
        }
        if (ean && !String(row.ean ?? "").toLowerCase().includes(ean)) {
            return false;
        }
        if (material === "none" && String(row.material ?? "") !== "") {
            return false;
        }
        if (material !== "all" && material !== "none" && String(row.material ?? "") !== material) {
            return false;
        }
        return true;
    };
});

// === LICZNIKI ===
const gridRef = ref<any>(null);

const visibleCount = computed<number>(() => {
    const source = gridRef.value?.getSource?.() ?? [];
    const filter = filterFn.value;
    return filter ? source.filter(filter).length : source.length;
});

const totalCount = computed<number>(() => gridRef.value?.getSource?.()?.length ?? 0);
</script>
