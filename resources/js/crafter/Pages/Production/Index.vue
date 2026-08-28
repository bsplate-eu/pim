<template>
    <PageHeader
        sticky
        title="Produkcja"
        subtitle="Kody produkcyjne z bazy PIM — jeden kod, jeden wiersz"
    />

    <PageContent>
        <div class="w-full">
            <Card>
                <!-- Zakladki filtrowania po znaczniku „Projekt". -->
                <div class="mb-4 border-b border-gray-200">
                    <nav class="-mb-px flex gap-6">
                        <button
                            v-for="tab in tabs"
                            :key="tab.key"
                            type="button"
                            @click="activeTab = tab.key"
                            :class="tabClass(activeTab === tab.key)"
                        >
                            {{ tab.label }}
                            <span class="ml-1 text-xs text-gray-400">{{ tab.count }}</span>
                        </button>
                    </nav>
                </div>

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
import axios from "axios";
import { useToast } from "@brackets/vue-toastification";
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
    project: boolean;
    sales_12m: number;
}

interface Props {
    rows: ProductionRow[];
}

const props = defineProps<Props>();
const toast = useToast();

// Kopia lokalna — DataGrid pracuje na v-model (props Inertii sa zamrozone).
const rows = ref<ProductionRow[]>([...props.rows]);
const gridRef = ref<any>(null);

// RevoGrid nie sledzi mutacji pol wiersza — podmiana referencji zrodla wymusza
// przerysowanie (i przelicza liczniki zakladek oraz filtr).
function refreshGrid(): void {
    const grid = gridRef.value;
    if (grid) grid.setSource([...grid.getSource()]);
}

// === ZNACZNIK „PROJEKT" ===
// Zapis idzie od razu po kliknieciu (bez przycisku Save). Wiersz przestawiamy
// optymistycznie, a przy bledzie cofamy — inaczej grid pokazywalby stan,
// ktorego nie ma w bazie.
async function setProject(code: string, value: boolean): Promise<void> {
    const source: ProductionRow[] = gridRef.value?.getSource?.() ?? [];
    const row = source.find((r) => r.product_code === code);
    if (!row) return;

    const previous = row.project;
    row.project = value;
    refreshGrid();

    try {
        await axios.post(route("crafter.production.project"), {
            product_code: code,
            project: value,
        });
    } catch (e) {
        row.project = previous;
        refreshGrid();
        toast.error(`Nie udalo sie zapisac znacznika dla ${code}`);
    }
}

const columns = [
    {
        // Liczba porzadkowa liczona z pozycji w AKTUALNYM widoku — po posortowaniu
        // po sprzedazy Lp 1 to najlepiej schodzacy kod, po filtrze numeracja leci od nowa.
        // `p.data` to wiersze widoku (juz posortowane/przefiltrowane); `rowIndex` jest
        // awaryjnie, gdyby model nie znalazl sie w tablicy.
        prop: "__lp__",
        name: "Lp",
        readonly: true,
        sortable: false,
        size: 70,
        cellTemplate: (h: any, p: any) => {
            const index = Array.isArray(p.data) ? p.data.indexOf(p.model) : -1;
            const lp = (index >= 0 ? index : Number(p.rowIndex) || 0) + 1;
            return h("span", { style: { color: "#9ca3af" } }, String(lp));
        },
    },
    { prop: "product_code", name: "Kod", readonly: true, size: 160, sortable: true },
    {
        prop: "name",
        name: "Nazwa",
        readonly: true,
        size: 520,
        sortable: true,
        // Pod jednym kodem siedzi zwykle kilkanascie aut — nazwa to nazwa pierwszego z nich.
        // Znacznik "+N" mowi, ze wariantow jest wiecej, zeby nikt nie wzial jej za jedyna.
        cellTemplate: (h: any, p: any) => {
            const name = String(p.model?.name ?? "");
            const extra = Number(p.model?.variants ?? 1) - 1;
            if (extra <= 0) return h("span", {}, name);
            return h("span", { title: `Kod obejmuje ${extra + 1} produktow (aut)` }, [
                name,
                h("span", { style: { color: "#9ca3af", marginLeft: "6px" } }, `+${extra}`),
            ]);
        },
    },
    { prop: "material", name: "Materiał", readonly: true, size: 140, sortable: true },
    {
        // Sprzedaz z raportu Subiekta (31.08.2025 - 31.08.2026). Zero wyszarzone,
        // zeby wzrokiem od razu bylo widac, co sie w ogole nie sprzedawalo.
        prop: "sales_12m",
        name: "Sprzedaż 12 mc",
        readonly: true,
        size: 150,
        sortable: true,
        cellCompare: (prop: string, a: any, b: any): number =>
            (Number(a?.[prop]) || 0) - (Number(b?.[prop]) || 0),
        cellTemplate: (h: any, p: any) => {
            const value = Number(p.model?.sales_12m) || 0;
            return h(
                "span",
                value === 0 ? { style: { color: "#9ca3af" } } : {},
                String(value)
            );
        },
    },
    {
        prop: "project",
        name: "Projekt",
        readonly: true,
        size: 120,
        sortable: true,
        cellTemplate: (h: any, p: any) => {
            const code = String(p.model?.product_code ?? "");
            const checked = !!p.model?.project;
            return h("label", { class: "revo-project-cell" }, [
                h("input", {
                    type: "checkbox",
                    checked,
                    onClick: (e: any) => e.stopPropagation(),
                    onChange: (e: any) =>
                        setProject(code, !!(e.target as HTMLInputElement).checked),
                }),
                h(
                    "span",
                    { style: { marginLeft: "6px", color: checked ? "#15803d" : "#9ca3af" } },
                    checked ? "Tak" : "Nie"
                ),
            ]);
        },
    },
];

// === ZAKLADKI ===
type TabKey = "all" | "project" | "noproject";
const activeTab = ref<TabKey>("all");

function matchesTab(row: any, tab: TabKey): boolean {
    if (tab === "project") return !!row?.project;
    if (tab === "noproject") return !row?.project;
    return true;
}

function tabClass(active: boolean): string {
    return [
        "border-b-2 px-1 py-3 text-sm font-medium whitespace-nowrap",
        active
            ? "border-primary-500 text-primary-600"
            : "border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700",
    ].join(" ");
}

// Liczniki licza po CALYM zbiorze, nie po biezacym filtrze tekstowym — zakladka
// ma mowic ile jest kodow w danym stanie, niezaleznie od tego czego akurat szukasz.
const tabs = computed(() => {
    const source: any[] = gridRef.value?.getSource?.() ?? [];
    const withProject = source.filter((r) => r.project).length;
    return [
        { key: "all" as TabKey, label: "Wszystkie", count: source.length },
        { key: "project" as TabKey, label: "Projekt", count: withProject },
        { key: "noproject" as TabKey, label: "Bez projektu", count: source.length - withProject },
    ];
});

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
    const tab = activeTab.value;

    if (!code && !name && material === "all" && tab === "all") return null;

    return (row: any) => {
        if (!matchesTab(row, tab)) {
            return false;
        }
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
const visibleCount = computed<number>(() => {
    const source = gridRef.value?.getSource?.() ?? [];
    const filter = filterFn.value;
    return filter ? source.filter(filter).length : source.length;
});

const totalCount = computed<number>(() => gridRef.value?.getSource?.()?.length ?? 0);
</script>

<style scoped>
:deep(.revo-project-cell) {
    display: flex;
    align-items: center;
    height: 100%;
    cursor: pointer;
}
</style>
