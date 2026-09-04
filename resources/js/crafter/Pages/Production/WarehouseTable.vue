<template>
    <PageHeader
        sticky
        title="Tabela"
        :subtitle="`Arkusz inwentury „${sheet}” — układ kolumn jak w oryginale`"
    />

    <PageContent>
        <div class="w-full">
            <Card>
                <!-- Skad sa te dane. Arkusz przychodzi recznie, wiec ekran mowi
                     wprost, z ktorego importu pochodzi to, na co ktos patrzy —
                     inaczej czytaloby sie jak stan na teraz. -->
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-md border border-gray-200 bg-gray-50 px-4 py-3 text-sm">
                    <div class="text-gray-600">
                        Wczytane z pliku XLSX
                        <span v-if="importedAt" class="text-gray-900">{{ importedAt }}</span>
                        <span v-else class="text-gray-400">— jeszcze nic nie zaimportowano</span>.
                        Kolejny arkusz podmienia całą zakładkę:
                        <code class="rounded bg-white px-1 py-0.5 text-xs">
                            php artisan warehouse:import-sheet plik.xlsx
                        </code>
                    </div>
                    <Button
                        :as="Link"
                        :href="route('crafter.production.warehouse')"
                        variant="outline"
                        color="gray"
                    >
                        Przejdź do Magazyn M3R
                    </Button>
                </div>

                <div class="mb-3 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <TextInput
                        v-model="search.code"
                        name="search_code"
                        label="Kod"
                        placeholder="np. 00.004"
                        clearable
                    />
                    <TextInput
                        v-model="search.place"
                        name="search_place"
                        label="Miejsce"
                        placeholder="np. k5g2, regal, mix2"
                        clearable
                    />
                    <SelectInput
                        v-model="search.filter"
                        name="search_filter"
                        label="Pokaż"
                        :options="filterOptions"
                    />
                </div>

                <div class="mb-3 text-xs text-gray-500">
                    Widocznych: <strong>{{ visibleCount }}</strong>
                    / Kodów w arkuszu: <strong>{{ rows.length }}</strong>
                    <span class="ml-4">Sztuk łącznie: <strong>{{ totalPieces }}</strong></span>
                    <span class="ml-4">Ze stanem 0: <strong>{{ zeroCount }}</strong></span>
                    <span class="ml-4">
                        Bez pary w PIM:
                        <strong :class="unmatchedCount ? 'text-amber-700' : ''">
                            {{ unmatchedCount }}
                        </strong>
                    </span>
                </div>

                <DataGrid
                    ref="gridRef"
                    v-model="rows"
                    :columns="columns"
                    :filter="filterFn"
                    keyField="id"
                    height="auto"
                />
            </Card>
        </div>
    </PageContent>
</template>

<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { Link } from "@inertiajs/vue3";
import {
    Button,
    Card,
    DataGrid,
    PageContent,
    PageHeader,
    SelectInput,
    TextInput,
} from "crafter/Components";

/**
 * Wiersz arkusza 1:1. Ilosc jest `number | null` i ta roznica jest tu istotna:
 * null = pole w arkuszu puste, 0 = ktos policzyl i nie ma. Na ekranie pierwsze
 * jest kreska, drugie zerem.
 */
interface SheetRow {
    id: number;
    row_no: number;
    product_code: string;
    place_1: string | null; qty_1: number | null;
    place_2: string | null; qty_2: number | null;
    place_3: string | null; qty_3: number | null;
    place_4: string | null; qty_4: number | null;
    place_5: string | null; qty_5: number | null;
    place_6: string | null; qty_6: number | null;
    quantity_total: number;
    steel_team: string | null;
    uwagi: string | null;
    wymiar: string | null;
    waga: string | null;
    in_pim: boolean;
}

interface Props {
    sheet: string;
    importedAt: string | null;
    rows: SheetRow[];
}

const props = withDefaults(defineProps<Props>(), { rows: () => [], importedAt: null });

// Kopia lokalna — DataGrid pracuje na v-model, a props Inertii sa zamrozone.
const rows = ref<SheetRow[]>([...props.rows]);
const gridRef = ref<any>(null);

const search = ref({ code: "", place: "", filter: "all" });

const filterOptions = [
    { value: "all", label: "Wszystkie" },
    { value: "unmatched", label: "Bez pary w PIM" },
    { value: "zero", label: "Stan 0" },
    { value: "notes", label: "Z uwagami" },
    { value: "multi", label: "W kilku miejscach" },
];

const PAIRS = [1, 2, 3, 4, 5, 6] as const;

const placesOf = (row: SheetRow): string[] =>
    PAIRS.map((i) => (row as any)["place_" + i]).filter(Boolean).map((p: string) => String(p).toLowerCase());

const totalPieces = computed(() => props.rows.reduce((sum, r) => sum + (r.quantity_total || 0), 0));
const zeroCount = computed(() => props.rows.filter((r) => r.quantity_total === 0).length);
const unmatchedCount = computed(() => props.rows.filter((r) => !r.in_pim).length);
const visibleCount = ref(props.rows.length);

/**
 * Pary 5 i 6 nie maja naglowkow w arkuszu i korzysta z nich jeden wiersz
 * (98.041). Pokazujemy je tylko wtedy, gdy w danych faktycznie sa — inaczej
 * dwie puste kolumny rozpychalyby tabele bez powodu.
 */
const visiblePairs = computed(() =>
    PAIRS.filter((i) => i <= 4 || props.rows.some((r) => (r as any)["place_" + i] || (r as any)["qty_" + i] !== null))
);

function filterFn(row: SheetRow): boolean {
    const code = search.value.code.trim().toLowerCase();
    if (code && !String(row.product_code).toLowerCase().includes(code)) return false;

    const place = search.value.place.trim().toLowerCase();
    if (place && !placesOf(row).some((p) => p.includes(place))) return false;

    switch (search.value.filter) {
        case "unmatched":
            return !row.in_pim;
        case "zero":
            return row.quantity_total === 0;
        case "notes":
            return Boolean(row.steel_team || row.uwagi);
        case "multi":
            return placesOf(row).length > 1;
        default:
            return true;
    }
}

const textColumn = (prop: string, name: string, size: number) => ({
    prop,
    name,
    readonly: true,
    size,
    sortable: true,
    cellTemplate: (h: any, p: any) => {
        const value = p.model?.[prop];
        return value === null || value === undefined || value === ""
            ? h("span", { style: { color: "#d1d5db" } }, "—")
            : h("span", {}, String(value));
    },
});

/** Ilosc: pusta komorka arkusza to kreska, policzone zero to szare „0". */
const qtyColumn = (prop: string) => ({
    prop,
    name: "il.",
    readonly: true,
    size: 70,
    sortable: true,
    cellCompare: (key: string, a: any, b: any): number =>
        (a?.[key] ?? -1) - (b?.[key] ?? -1),
    cellTemplate: (h: any, p: any) => {
        const value = p.model?.[prop];
        if (value === null || value === undefined) {
            return h("span", { style: { color: "#d1d5db" } }, "—");
        }
        return h("span", { style: value === 0 ? { color: "#9ca3af" } : {} }, String(value));
    },
});

const columns = computed(() => [
    {
        // Numer wiersza z arkusza, nie Lp z widoku — po to, zeby dalo sie
        // powiedziec „patrz wiersz 567" i trafic w to samo miejsce w Excelu.
        prop: "row_no",
        name: "W.",
        readonly: true,
        size: 70,
        sortable: true,
        cellTemplate: (h: any, p: any) =>
            h("span", { style: { color: "#9ca3af" } }, String(p.model?.row_no ?? "")),
    },
    {
        prop: "product_code",
        name: "Kod",
        readonly: true,
        size: 160,
        sortable: true,
        // Kod bez pary w PIM dostaje bursztynowa kropke — to kandydat do
        // zakladki „Do zmapowania", a nie blad w arkuszu.
        cellTemplate: (h: any, p: any) => {
            const code = String(p.model?.product_code ?? "");
            if (p.model?.in_pim) return h("span", {}, code);

            return h("span", { class: "revo-code-cell", title: "Brak pary w PIM" }, [
                h("span", {
                    style: {
                        display: "inline-block",
                        width: "6px",
                        height: "6px",
                        borderRadius: "9999px",
                        background: "#d97706",
                        marginRight: "6px",
                    },
                }),
                h("span", { style: { color: "#b45309" } }, code),
            ]);
        },
    },
    ...visiblePairs.value.flatMap((i) => [
        textColumn("place_" + i, "Miejsce", 110),
        qtyColumn("qty_" + i),
    ]),
    {
        prop: "quantity_total",
        name: "Razem",
        readonly: true,
        size: 90,
        sortable: true,
        cellTemplate: (h: any, p: any) => {
            const value = Number(p.model?.quantity_total ?? 0);
            return h("span", { style: value ? { fontWeight: "600" } : { color: "#9ca3af" } }, String(value));
        },
    },
    textColumn("steel_team", "steel team", 180),
    textColumn("uwagi", "Uwagi", 200),
    textColumn("wymiar", "WYMIAR", 120),
    textColumn("waga", "WAGA", 120),
]);

// DataGrid filtruje po swojej stronie; licznik „widocznych" bierzemy z tego
// samego predykatu, zeby nie rozjechal sie z tym, co widac.
const recount = (): void => {
    visibleCount.value = rows.value.filter(filterFn).length;
};

// Kazda zmiana filtra przelicza licznik — watch na calym obiekcie, bo pola sa trzy.
watch(search, recount, { deep: true });
</script>
