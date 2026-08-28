<template>
    <PageHeader
        sticky
        title="Produkcja"
        subtitle="Kody produkcyjne z bazy PIM — jeden kod, jeden wiersz"
    />

    <PageContent>
        <div class="w-full">
            <Card>
                <div class="mb-3 text-xs text-gray-500">
                    Widocznych: <strong>{{ visibleCount }}</strong>
                    / Wszystkich kodów: <strong>{{ totalCount }}</strong>
                </div>

                <div class="mb-3 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
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
                    keyField="product_code"
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
    material: string;
    variants: number;
}

interface Props {
    rows: ProductionRow[];
}

const props = defineProps<Props>();

// Kopia lokalna — DataGrid pracuje na v-model (props Inertii sa zamrozone).
const rows = ref<ProductionRow[]>([...props.rows]);

const columns = [
    { prop: "product_code", name: "Kod", readonly: true, size: 160, sortable: true },
    {
        prop: "name",
        name: "Nazwa",
        readonly: true,
        size: 560,
        sortable: true,
        // Pod jednym kodem siedzi zwykle kilkanascie aut — nazwa to nazwa pierwszego z nich.
        // Znacznik "+N" mowi, ze wariantow jest wiecej, zeby nikt nie wzial jej za jedyna.
        cellTemplate: (h: any, p: any) => {
            const name = String(p.model?.name ?? "");
            const extra = Number(p.model?.variants ?? 1) - 1;
            if (extra <= 0) return h("span", {}, name);
            return h("span", { title: `Kod obejmuje ${extra + 1} produktów (aut)` }, [
                name,
                h("span", { style: { color: "#9ca3af", marginLeft: "6px" } }, `+${extra}`),
            ]);
        },
    },
    { prop: "material", name: "Materiał", readonly: true, size: 140, sortable: true },
];

// === WYSZUKIWARKA ===
const search = reactive({
    code: "",
    name: "",
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
    const material = String(search.material ?? "all");

    if (!code && !name && material === "all") return null;

    return (row: any) => {
        if (code && !String(row.product_code ?? "").toLowerCase().includes(code)) {
            return false;
        }
        if (name && !String(row.name ?? "").toLowerCase().includes(name)) {
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
