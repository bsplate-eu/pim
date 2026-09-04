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

                <!-- Ten sam podzial co na liscie M3R: osobno to, co ma pare
                     w PIM, osobno kubelek roboczy. Kolizje kodow miedzy arkuszem
                     a Subiektem sa pewne, wiec „Do zmapowania" to staly stan
                     pracy, a nie lista bledow. -->
                <div class="mb-4 border-b border-gray-200">
                    <nav class="-mb-px flex gap-6">
                        <button
                            v-for="tab in tabs"
                            :key="tab.key"
                            type="button"
                            :class="tabClass(activeTab === tab.key)"
                            @click="activeTab = tab.key"
                        >
                            {{ tab.label }}
                            <span class="ml-1 text-xs text-gray-400">{{ tab.count }}</span>
                        </button>
                    </nav>
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
                </div>

                <!-- Stala wysokosc zamiast "auto": grid scrolluje sie w srodku,
                     wiec naglowek zostaje przyklejony na gorze i przy 615
                     wierszach ciagle widac, ktora kolumna jest ktora.
                     `frameSize` trzyma KOMPLET wierszy w DOM mimo scrolla —
                     bez tego RevoGrid recyklinguje wiersze i naprzemienne tlo
                     (liczone z kolejnosci w DOM) skakaloby przy przewijaniu. -->
                <DataGrid
                    ref="gridRef"
                    v-model="rows"
                    :columns="columns"
                    :filter="filterFn"
                    keyField="id"
                    height="max(360px, calc(100vh - 330px))"
                    :frameSize="rows.length + 100"
                />
            </Card>
        </div>

        <!-- === MAPOWANIE === -->
        <!-- Ta sama tabela `warehouse_code_map`, do ktorej pisze lista M3R, wiec
             cokolwiek zmapuje sie tam, widac tutaj i odwrotnie. Roznica jest
             tylko w kierunku patrzenia: tam wskazuje sie kod zrodlowy do kodu
             PIM, tu kod PIM do wiersza arkusza. -->
        <Modal :open="mapModal.show" externalOpen size="md" alignButtons="right" @toggleOpen="closeMap">
            <template #title> Mapowanie wiersza {{ mapModal.source_code }} </template>

            <template #content>
                <p class="text-sm text-gray-500">
                    Wskaż kod produktu w PIM, którym jest ten wiersz arkusza. Jeden
                    kod z arkusza wskazuje zawsze na jeden kod PIM; kilka różnych
                    kodów arkusza może wskazywać na ten sam produkt — ilości się
                    wtedy sumują.
                </p>

                <div class="mt-4">
                    <TextInput
                        v-model="mapModal.input"
                        name="map_product_code"
                        label="Kod w PIM"
                        placeholder="np. 26.174"
                        inputClass="bg-white"
                        clearable
                        @keyup.enter="saveMap"
                    />
                    <p class="mt-1 text-sm text-gray-500">
                        Kod musi istnieć w PIM — inaczej zapis zostanie odrzucony.
                    </p>
                </div>

                <div
                    v-if="mapModal.current"
                    class="mt-4 flex items-center justify-between rounded border border-gray-200 px-3 py-2"
                >
                    <span class="text-sm text-gray-500">
                        Obecnie przypisane: <code class="text-gray-900">{{ mapModal.current }}</code>
                    </span>
                    <button
                        type="button"
                        class="text-sm text-gray-400 hover:text-red-600"
                        :disabled="mapModal.busy"
                        @click="removeMap"
                    >
                        Odepnij
                    </button>
                </div>
            </template>

            <template #buttons>
                <Button :loading="mapModal.busy" @click="saveMap">Zapisz</Button>
                <Button variant="outline" color="gray" @click="closeMap">Zamknij</Button>
            </template>
        </Modal>
    </PageContent>
</template>

<script setup lang="ts">
import { computed, reactive, ref } from "vue";
import { Link } from "@inertiajs/vue3";
import axios from "axios";
import { useToast } from "@brackets/vue-toastification";
import {
    Button,
    Card,
    DataGrid,
    Modal,
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
    /** Kod PIM wskazany ręcznie. NULL nie znaczy „brak pary" — patrz `in_pim`. */
    mapped_to: string | null;
}

interface Props {
    sheet: string;
    importedAt: string | null;
    rows: SheetRow[];
    /** Klucz źródła w `warehouse_code_map` — dla tego ekranu zawsze „sheet". */
    source: string;
}

const props = withDefaults(defineProps<Props>(), {
    rows: () => [],
    importedAt: null,
    source: "sheet",
});

const toast = useToast();

// Kopia lokalna — DataGrid pracuje na v-model, a props Inertii sa zamrozone.
const rows = ref<SheetRow[]>([...props.rows]);
const gridRef = ref<any>(null);

const search = ref({ code: "", place: "", filter: "all" });

// „Bez pary" nie ma tu wpisu — od tego sa zakladki, a dwa miejsca na te sama
// decyzje potrafia sie ustawic sprzecznie i pokazac pusta liste bez powodu.
const filterOptions = [
    { value: "all", label: "Wszystkie" },
    { value: "zero", label: "Stan 0" },
    { value: "notes", label: "Z uwagami" },
    { value: "multi", label: "W kilku miejscach" },
];

type TabKey = "mapped" | "unmapped";
const activeTab = ref<TabKey>("mapped");

function tabClass(active: boolean): string {
    return [
        "border-b-2 px-1 py-3 text-sm font-medium whitespace-nowrap",
        active
            ? "border-primary-500 text-primary-600"
            : "border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700",
    ].join(" ");
}

const PAIRS = [1, 2, 3, 4, 5, 6] as const;

const placesOf = (row: SheetRow): string[] =>
    PAIRS.map((i) => (row as any)["place_" + i]).filter(Boolean).map((p: string) => String(p).toLowerCase());

const totalPieces = computed(() => props.rows.reduce((sum, r) => sum + (r.quantity_total || 0), 0));
const zeroCount = computed(() => props.rows.filter((r) => r.quantity_total === 0).length);
// Liczone tym samym predykatem, ktorym filtruje grid — inaczej licznik potrafi
// pokazac co innego niz widac na ekranie.
const visibleCount = computed(() => rows.value.filter(filterFn).length);

/** Bez pary = ani kodu 1:1 w PIM, ani ręcznego mapowania. Liczone z żywych
 *  wierszy, nie z propsów, żeby licznik spadał od razu po zmapowaniu. */
const isUnmatched = (row: SheetRow): boolean => !row.in_pim && !row.mapped_to;
const unmatchedCount = computed(() => rows.value.filter(isUnmatched).length);

const tabs = computed(() => [
    {
        key: "mapped" as TabKey,
        label: "Zmapowane",
        count: rows.value.length - unmatchedCount.value,
    },
    { key: "unmapped" as TabKey, label: "Do zmapowania", count: unmatchedCount.value },
]);

/**
 * Pary 5 i 6 nie maja naglowkow w arkuszu i korzysta z nich jeden wiersz
 * (98.041). Pokazujemy je tylko wtedy, gdy w danych faktycznie sa — inaczej
 * dwie puste kolumny rozpychalyby tabele bez powodu.
 */
const visiblePairs = computed(() =>
    PAIRS.filter((i) => i <= 4 || props.rows.some((r) => (r as any)["place_" + i] || (r as any)["qty_" + i] !== null))
);

function filterFn(row: SheetRow): boolean {
    // Zakladka jest nadrzedna: decyduje, na ktorym kubelku w ogole pracujemy.
    if ((activeTab.value === "unmapped") !== isUnmatched(row)) return false;

    const code = search.value.code.trim().toLowerCase();
    if (code && !String(row.product_code).toLowerCase().includes(code)) return false;

    const place = search.value.place.trim().toLowerCase();
    if (place && !placesOf(row).some((p) => p.includes(place))) return false;

    switch (search.value.filter) {
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

// === MAPOWANIE ===
const mapModal = reactive({
    show: false,
    source_code: "",
    current: "" as string | null,
    input: "",
    busy: false,
});

function openMap(row: SheetRow): void {
    mapModal.show = true;
    mapModal.source_code = row.product_code;
    mapModal.current = row.mapped_to;
    // Kod zgodny 1:1 podpowiadamy jako punkt wyjscia — najczesciej mapowanie
    // sluzy do wskazania czegos INNEGO, wiec pole zostaje edytowalne.
    mapModal.input = row.mapped_to ?? (row.in_pim ? row.product_code : "");
}

function closeMap(): void {
    mapModal.show = false;
}

/** RevoGrid nie sledzi mutacji pol wiersza — podmiana zrodla wymusza przerysowanie. */
function applyMapping(sourceCode: string, productCode: string | null): void {
    const row = rows.value.find((r) => r.product_code === sourceCode);
    if (row) row.mapped_to = productCode;

    const grid = gridRef.value;
    if (grid?.getSource) grid.setSource([...grid.getSource()]);
}

async function saveMap(): Promise<void> {
    const productCode = mapModal.input.trim();

    if (!productCode) {
        toast.error("Podaj kod w PIM.");

        return;
    }

    mapModal.busy = true;

    try {
        await axios.post(route("crafter.production.warehouse.map.store"), {
            product_code: productCode,
            source: props.source,
            source_code: mapModal.source_code,
        });

        applyMapping(mapModal.source_code, productCode);
        mapModal.current = productCode;
        toast.success(`Zmapowano ${mapModal.source_code} → ${productCode}`);
        closeMap();
    } catch (error: any) {
        // 422 leci albo z walidacji (kodu nie ma w PIM), albo gdy ten kod
        // zrodlowy jest juz przypiety gdzie indziej — serwer mowi gdzie.
        const message =
            error?.response?.data?.message ??
            error?.response?.data?.errors?.product_code?.[0] ??
            "Nie udało się zapisać mapowania.";
        toast.error(message);
    } finally {
        mapModal.busy = false;
    }
}

async function removeMap(): Promise<void> {
    if (!mapModal.current) return;

    mapModal.busy = true;

    try {
        await axios.delete(route("crafter.production.warehouse.map.destroy"), {
            data: {
                product_code: mapModal.current,
                source: props.source,
                source_code: mapModal.source_code,
            },
        });

        applyMapping(mapModal.source_code, null);
        mapModal.current = null;
        mapModal.input = "";
        toast.success("Odpięto mapowanie.");
    } catch {
        toast.error("Nie udało się odpiąć mapowania.");
    } finally {
        mapModal.busy = false;
    }
}

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
    {
        // Ten sam chip co na liscie M3R, tylko odwrocony: tam pokazuje kody
        // zrodlowe przypiete do produktu, tu produkt przypiety do wiersza.
        prop: "mapped_to",
        name: "Mapowanie",
        readonly: true,
        size: 170,
        sortable: true,
        cellTemplate: (h: any, p: any) => {
            const row = p.model as SheetRow;
            const open = (e: any) => {
                e.stopPropagation();
                openMap(row);
            };

            if (row?.mapped_to) {
                return h(
                    "button",
                    {
                        class: "revo-map-chip revo-map-set",
                        title: `Zmapowane na ${row.mapped_to} — kliknij, żeby zmienić`,
                        onClick: open,
                    },
                    row.mapped_to
                );
            }

            // Kod zgadza sie 1:1 z PIM — mapowanie jest zbedne, ale zostawiamy
            // wejscie, bo czasem trzeba wskazac świadomie co innego.
            if (row?.in_pim) {
                return h(
                    "button",
                    {
                        class: "revo-map-chip revo-map-empty",
                        title: "Kod zgodny z PIM — mapowanie niepotrzebne",
                        onClick: open,
                    },
                    "1:1"
                );
            }

            return h(
                "button",
                {
                    class: "revo-map-chip revo-map-empty",
                    style: { color: "#b45309", borderColor: "#fcd34d" },
                    title: "Brak pary w PIM — wskaż kod ręcznie",
                    onClick: open,
                },
                "mapuj +"
            );
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
</script>

<style scoped>
/* Te same chipy co na liscie M3R — style sa tam scoped, wiec musza byc i tu. */
:deep(.revo-map-chip) {
    flex: none;
    padding: 0 8px;
    border-radius: 9px;
    font-size: 11px;
    font-weight: 600;
    line-height: 18px;
    cursor: pointer;
}

:deep(.revo-map-set) {
    border: 1px solid #bbf7d0;
    background: #f0fdf4;
    color: #15803d;
}

:deep(.revo-map-empty) {
    border: 1px dashed #d1d5db;
    background: transparent;
    color: #9ca3af;
}

:deep(.revo-map-empty:hover) {
    border-color: #9ca3af;
    color: #4b5563;
}

:deep(.revo-code-cell) {
    display: flex;
    align-items: center;
    gap: 6px;
    height: 100%;
}

/* === WYGLAD ARKUSZA ===
   Ten ekran ma sie czytac jak Excel, z ktorego pochodzi: szary pasek naglowka,
   ramki miedzy kolumnami i naprzemienne tlo wierszy. Przy szesciu parach
   Miejsce/il. obok siebie sama siatka robi za orientacje - bez pionowych linii
   oko gubi, ktora ilosc nalezy do ktorego miejsca. */
:deep(revogr-header .rgHeaderCell) {
    background: #d9d9d9;
    border-right: 1px solid #a6a6a6;
    border-bottom: 1px solid #a6a6a6;
    color: #1f2937;
    font-weight: 600;
}

:deep(revogr-data .rgCell) {
    border-right: 1px solid #d4d4d4;
    border-bottom: 1px solid #e8e8e8;
}

/* Zebra po wierszu, nie po komorce - wiersze siedza w DOM w kolejnosci zrodla,
   bo grid stoi na height="auto" i renderuje komplet bez wirtualizacji. */
:deep(revogr-data .rgRow:nth-of-type(odd) .rgCell) {
    background: #ffffff;
}

:deep(revogr-data .rgRow:nth-of-type(even) .rgCell) {
    background: #f2f2f2;
}
</style>
