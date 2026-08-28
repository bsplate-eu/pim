<template>
    <PageHeader
        sticky
        title="Produkcja"
        subtitle="Kody produkcyjne z bazy PIM — jeden kod, jeden wiersz"
    />

    <PageContent>
        <div class="w-full">
            <Card>
                <!-- Rzad 1: filtrowanie po znacznikach Projekt / Team Steel. -->
                <div class="border-b border-gray-200">
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

                <!-- Rzad 2: etapy. Dziala RAZEM z rzedem 1 (przeciecie), nie zamiast. -->
                <div class="mb-4 border-b border-gray-200">
                    <nav class="-mb-px flex gap-6">
                        <button
                            v-for="tab in stageTabs"
                            :key="tab.key"
                            type="button"
                            @click="activeStage = tab.key"
                            :class="stageTabClass(activeStage === tab.key)"
                            :style="activeStage === tab.key && tab.color
                                ? { borderColor: tab.color, color: tab.color }
                                : {}"
                        >
                            <span
                                v-if="tab.color"
                                class="mr-2 inline-block h-2 w-2 rounded-full align-middle"
                                :style="{ background: tab.color }"
                            />
                            {{ tab.label }}
                            <span class="ml-1 text-xs text-gray-400">
                                {{ tab.count }} · {{ formatPercent(tab.percent) }}
                            </span>
                        </button>
                    </nav>
                </div>

                <!-- Podzial na etapy liczony po CALYM zbiorze kodow: wszystkie = 100%. -->
                <div class="mb-4">
                    <div class="flex h-2 w-full overflow-hidden rounded bg-gray-100">
                        <div
                            v-for="part in stageBreakdown"
                            :key="part.key"
                            :style="{ width: part.percent + '%', background: part.color }"
                            :title="`${part.label}: ${part.count} kodów (${formatPercent(part.percent)})`"
                        />
                    </div>
                    <div class="mt-2 flex flex-wrap items-center gap-x-5 gap-y-1 text-xs text-gray-600">
                        <span v-for="part in stageBreakdown" :key="part.key" class="whitespace-nowrap">
                            <span
                                class="mr-1 inline-block h-2 w-2 rounded-full align-middle"
                                :style="{ background: part.color }"
                            />
                            {{ part.label }}
                            <strong class="ml-1 text-gray-900">{{ part.count }}</strong>
                            <span class="text-gray-400"> · {{ formatPercent(part.percent) }}</span>
                        </span>
                        <span class="whitespace-nowrap text-gray-400">
                            bez etapu
                            <strong class="text-gray-600">{{ noStage.count }}</strong>
                            · {{ formatPercent(noStage.percent) }}
                        </span>
                    </div>
                </div>

                <div class="mb-4 flex flex-wrap items-baseline gap-x-6 gap-y-1">
                    <div class="text-sm">
                        <span class="text-gray-500">Podsumowanie ilości sprzedanych:</span>
                        <strong class="ml-1 text-base text-gray-900">
                            {{ formatQty(visibleSales) }} szt.
                        </strong>
                        <!-- Przy aktywnej zakladce/filtrze pokazujemy tez calosc, zeby nie
                             dalo sie wziac sumy wycinka za sume wszystkiego. -->
                        <span v-if="isNarrowed" class="ml-1 text-xs text-gray-400">
                            z {{ formatQty(totalSales) }} szt. na wszystkich kodach
                        </span>
                    </div>
                    <div class="text-xs text-gray-500">
                        Widocznych: <strong>{{ visibleCount }}</strong>
                        / Wszystkich kodów: <strong>{{ totalCount }}</strong>
                    </div>
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
    sales_12m: number;
    project: boolean;
    team_steel: boolean;
    etap_1: boolean;
    etap_2: boolean;
    etap_3: boolean;
    gotowe: boolean;
    bez_wspornikow: boolean;
    projekty_gotowe: boolean;
}

// Znaczniki produkcyjne — jedna definicja napedza kolumne, zapis i zakladke.
// Kolejny znacznik = wpis tutaj, w FLAGS w kontrolerze i kolumna w migracji.
type FlagKey =
    | "project"
    | "team_steel"
    | "etap_1"
    | "etap_2"
    | "etap_3"
    | "gotowe"
    | "bez_wspornikow"
    | "projekty_gotowe";

type StageKey = "etap_1" | "etap_2" | "etap_3" | "gotowe";

// Linia etapow — elementy wykluczaja sie wzajemnie (pilnuje tego takze kontroler).
// Kolor jedzie stad do naglowka kolumny, tla komorki, zakladki i paska podzialu.
// Zielen zostaje wylacznie dla „Gotowe" — Etap 3 jest niebieski, wiec konca
// procesu nie da sie pomylic z etapem posrednim.
const STAGES: Array<{ key: StageKey; label: string; color: string; background: string }> = [
    { key: "etap_1", label: "Etap 1", color: "#dc2626", background: "#fee2e2" },
    { key: "etap_2", label: "Etap 2", color: "#ea580c", background: "#ffedd5" },
    { key: "etap_3", label: "Etap 3", color: "#2563eb", background: "#dbeafe" },
    { key: "gotowe", label: "Gotowe", color: "#16a34a", background: "#dcfce7" },
];

const STAGE_KEYS: StageKey[] = STAGES.map((s) => s.key);
const isStage = (flag: FlagKey): flag is StageKey => (STAGE_KEYS as string[]).includes(flag);

interface Props {
    rows: ProductionRow[];
}

const props = defineProps<Props>();
const toast = useToast();

// Kopia lokalna — DataGrid pracuje na v-model (props Inertii sa zamrozone).
const rows = ref<ProductionRow[]>([...props.rows]);
const gridRef = ref<any>(null);

// RevoGrid nie sledzi mutacji pol wiersza — podmiana referencji zrodla wymusza
// przerysowanie (i przelicza liczniki zakladek, procenty oraz filtr).
function refreshGrid(): void {
    const grid = gridRef.value;
    if (grid) grid.setSource([...grid.getSource()]);
}

// === ZNACZNIKI PRODUKCYJNE ===
// Zapis idzie od razu po kliknieciu (bez przycisku Save). Wiersz przestawiamy
// optymistycznie, a przy bledzie cofamy — inaczej grid pokazywalby stan,
// ktorego nie ma w bazie.
async function setFlag(code: string, flag: FlagKey, value: boolean): Promise<void> {
    const source: ProductionRow[] = gridRef.value?.getSource?.() ?? [];
    const row = source.find((r) => r.product_code === code);
    if (!row) return;

    // Cofamy caly komplet, nie samo klikniete pole — zaznaczenie etapu zdejmuje
    // dwa pozostale, wiec przy bledzie trzeba przywrocic wszystkie trzy.
    const snapshot: Partial<Record<FlagKey, boolean>> = { [flag]: row[flag] };

    row[flag] = value;

    if (value && isStage(flag)) {
        STAGE_KEYS.filter((k) => k !== flag).forEach((other) => {
            snapshot[other] = row[other];
            row[other] = false;
        });
    }

    refreshGrid();

    try {
        await axios.post(route("crafter.production.flag"), {
            product_code: code,
            flag,
            value,
        });
    } catch (e) {
        Object.entries(snapshot).forEach(([key, was]) => {
            (row as any)[key] = was;
        });
        refreshGrid();
        toast.error(`Nie udalo sie zapisac znacznika dla ${code}`);
    }
}

/** Kolumna z checkboxem Tak/Nie. Etapy dostaja swoj kolor w naglowku i w tle komorki. */
function flagColumn(flag: FlagKey, name: string, size: number) {
    const stage = STAGES.find((s) => s.key === flag);

    return {
        prop: flag,
        name,
        readonly: true,
        size,
        sortable: true,
        columnTemplate: stage
            ? (h: any) => h("span", { style: { color: stage.color, fontWeight: "600" } }, name)
            : undefined,
        cellTemplate: (h: any, p: any) => {
            const code = String(p.model?.product_code ?? "");
            const checked = !!p.model?.[flag];
            const accent = stage?.color ?? "#15803d";

            return h(
                "label",
                {
                    class: "revo-flag-cell",
                    style: checked && stage ? { background: stage.background } : {},
                },
                [
                    h("input", {
                        type: "checkbox",
                        checked,
                        onClick: (e: any) => e.stopPropagation(),
                        onChange: (e: any) =>
                            setFlag(code, flag, !!(e.target as HTMLInputElement).checked),
                    }),
                    h(
                        "span",
                        {
                            style: {
                                marginLeft: "6px",
                                color: checked ? accent : "#9ca3af",
                                fontWeight: checked ? "600" : "400",
                            },
                        },
                        checked ? "Tak" : "Nie"
                    ),
                ]
            );
        },
    };
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
        // Wezsza niz wczesniej — przy siedmiu znacznikach tabela nie miesci sie
        // na ekranie, a najdluzsze nazwy i tak siedza w ~380 px.
        size: 420,
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
    flagColumn("project", "Projekt", 120),
    flagColumn("team_steel", "Team Steel", 140),
    flagColumn("etap_1", "Etap 1", 110),
    flagColumn("etap_2", "Etap 2", 110),
    flagColumn("etap_3", "Etap 3", 110),
    flagColumn("gotowe", "Gotowe", 120),
    flagColumn("bez_wspornikow", "Bez wsporników", 160),
    flagColumn("projekty_gotowe", "Projekty gotowe", 165),
];

// === ZAKLADKI: RZAD 1 (znaczniki) ===
type TabKey = "all" | "project" | "noproject" | "team_steel";
const activeTab = ref<TabKey>("all");

function matchesTab(row: any, tab: TabKey): boolean {
    if (tab === "project") return !!row?.project;
    if (tab === "noproject") return !row?.project;
    if (tab === "team_steel") return !!row?.team_steel;
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
const allRows = computed<any[]>(() => gridRef.value?.getSource?.() ?? []);

const tabs = computed(() => {
    const source = allRows.value;
    const withProject = source.filter((r) => r.project).length;
    const withTeamSteel = source.filter((r) => r.team_steel).length;
    return [
        { key: "all" as TabKey, label: "Wszystkie", count: source.length },
        { key: "project" as TabKey, label: "Projekt", count: withProject },
        { key: "noproject" as TabKey, label: "Bez projektu", count: source.length - withProject },
        { key: "team_steel" as TabKey, label: "Team Steel", count: withTeamSteel },
    ];
});

// === ZAKLADKI: RZAD 2 (etapy) ===
type StageTabKey = "all" | StageKey | "none";
const activeStage = ref<StageTabKey>("all");

function matchesStage(row: any, tab: StageTabKey): boolean {
    if (tab === "all") return true;
    if (tab === "none") return !STAGE_KEYS.some((k) => row?.[k]);
    return !!row?.[tab];
}

function stageTabClass(active: boolean): string {
    return [
        "border-b-2 px-1 py-2 text-sm font-medium whitespace-nowrap",
        active
            ? "border-gray-700 text-gray-900"
            : "border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700",
    ].join(" ");
}

// === PODZIAL NA ETAPY ===
// Baza to WSZYSTKIE kody (nie widoczne po filtrze) — „wszystkie oslony = 100%".
// Etapy wykluczaja sie, wiec suma trzech + „bez etapu" zawsze daje 100%.
const stageBreakdown = computed(() => {
    const source = allRows.value;
    const total = source.length || 1;

    return STAGES.map((stage) => {
        const count = source.filter((r) => r[stage.key]).length;
        return {
            key: stage.key,
            label: stage.label,
            color: stage.color,
            count,
            percent: (count / total) * 100,
        };
    });
});

const noStage = computed(() => {
    const source = allRows.value;
    const total = source.length || 1;
    const count = source.filter((r) => !STAGE_KEYS.some((k) => r[k])).length;
    return { count, percent: (count / total) * 100 };
});

const stageTabs = computed(() => {
    const source = allRows.value;
    return [
        { key: "all" as StageTabKey, label: "Wszystkie etapy", color: "", count: source.length, percent: 100 },
        ...stageBreakdown.value.map((part) => ({
            key: part.key as StageTabKey,
            label: part.label,
            color: part.color,
            count: part.count,
            percent: part.percent,
        })),
        { key: "none" as StageTabKey, label: "Bez etapu", color: "#9ca3af", count: noStage.value.count, percent: noStage.value.percent },
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
    const stage = activeStage.value;

    if (!code && !name && material === "all" && tab === "all" && stage === "all") return null;

    return (row: any) => {
        // Oba rzedy zakladek dzialaja razem: „Projekt" + „Etap 2" = kody z obu.
        if (!matchesTab(row, tab) || !matchesStage(row, stage)) {
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

// === LICZNIKI I PODSUMOWANIE ===
// Wszystko liczone z zrodla gridu, nie z props.rows — po kliknieciu znacznika
// grid podmienia referencje zrodla, wiec te wartosci przeliczaja sie same.
const visibleRows = computed<any[]>(() => {
    const filter = filterFn.value;
    return filter ? allRows.value.filter(filter) : allRows.value;
});

const visibleCount = computed<number>(() => visibleRows.value.length);
const totalCount = computed<number>(() => allRows.value.length);

const sumSales = (list: any[]): number =>
    list.reduce((sum, row) => sum + (Number(row?.sales_12m) || 0), 0);

const visibleSales = computed<number>(() => sumSales(visibleRows.value));
const totalSales = computed<number>(() => sumSales(allRows.value));

// Widok zawezony = zakladka inna niz „Wszystkie" albo cokolwiek w filtrach.
const isNarrowed = computed<boolean>(() => filterFn.value !== null);

const qtyFormatter = new Intl.NumberFormat("pl-PL");
const formatQty = (value: number): string => qtyFormatter.format(value);

const percentFormatter = new Intl.NumberFormat("pl-PL", {
    minimumFractionDigits: 1,
    maximumFractionDigits: 1,
});
const formatPercent = (value: number): string => percentFormatter.format(value) + "%";
</script>

<style scoped>
:deep(.revo-flag-cell) {
    display: flex;
    align-items: center;
    height: 100%;
    width: 100%;
    cursor: pointer;
}
</style>
