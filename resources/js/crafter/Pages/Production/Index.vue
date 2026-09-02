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

                <!-- Rzad 2: etapy ze slownika. Dziala RAZEM z rzedem 1 (przeciecie). -->
                <div class="mb-4 border-b border-gray-200">
                    <nav class="-mb-px flex flex-wrap gap-x-6">
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
                            <span class="ml-1 text-xs text-gray-400">{{ tab.count }}</span>
                        </button>
                    </nav>
                </div>

                <!-- Pasek etapow i podsumowanie po lewej, barometr po prawej —
                     jeden rzad, zeby nie rozpychac karty w pionie. -->
                <div class="mb-4 flex items-center gap-6">
                    <div class="min-w-0 flex-1">
                        <!-- Proporcja etapow na oko. Bez liczb — te sa juz przy zakladkach,
                             a legenda pod paskiem powtarzalaby je slowo w slowo. -->
                        <div class="flex h-2 w-full overflow-hidden rounded bg-gray-100">
                            <div
                                v-for="part in stageBreakdown"
                                :key="part.id"
                                :style="{ width: part.share + '%', background: part.color }"
                                :title="`${part.label}: ${part.count} kodów`"
                            />
                        </div>

                        <div class="mt-3 flex flex-wrap items-baseline gap-x-6 gap-y-1">
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
                    </div>

                    <!-- Barometr liczy z CALEGO zbioru, nie z widocznego po filtrze — ma mowic
                         ile calosci jest zrobione, niezaleznie od tego czego akurat szukasz. -->
                    <ProgressGauge class="flex-none" :done="doneCount" :total="totalCount" />
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
import ProgressGauge from "./ProgressGauge.vue";

interface Stage {
    id: number;
    name: string;
    color: string;
}

interface ProductionRow {
    product_id: number;
    product_code: string;
    name: string;
    material: string;
    variants: number;
    sales_12m: number;
    stage_id: number | null;
    project: boolean;
    team_steel: boolean;
    bez_wspornikow: boolean;
    projekty_gotowe: boolean;
}

// Znaczniki reczne — etapow tu nie ma, te wynikaja ze sprzedazy i przelicza je
// serwer. Kolejny znacznik = wpis tutaj, w FLAGS w kontrolerze i kolumna w migracji.
type FlagKey = "project" | "team_steel" | "bez_wspornikow" | "projekty_gotowe";

interface Props {
    rows: ProductionRow[];
    stages: Stage[];
}

const props = defineProps<Props>();
const toast = useToast();

// Kopia lokalna — DataGrid pracuje na v-model (props Inertii sa zamrozone).
const rows = ref<ProductionRow[]>([...props.rows]);
const gridRef = ref<any>(null);

const stageById = computed<Map<number, Stage>>(
    () => new Map(props.stages.map((s) => [s.id, s]))
);

// RevoGrid nie sledzi mutacji pol wiersza — podmiana referencji zrodla wymusza
// przerysowanie (i przelicza liczniki zakladek, procenty oraz filtr).
function refreshGrid(): void {
    const grid = gridRef.value;
    if (grid) grid.setSource([...grid.getSource()]);
}

// === ZNACZNIKI RECZNE ===
// Zapis idzie od razu po kliknieciu (bez przycisku Save). Wiersz przestawiamy
// optymistycznie, a przy bledzie cofamy — inaczej grid pokazywalby stan,
// ktorego nie ma w bazie.
async function setFlag(code: string, flag: FlagKey, value: boolean): Promise<void> {
    const source: ProductionRow[] = gridRef.value?.getSource?.() ?? [];
    const row = source.find((r) => r.product_code === code);
    if (!row) return;

    const previous = row[flag];
    row[flag] = value;
    refreshGrid();

    try {
        await axios.post(route("crafter.production.flag"), {
            product_code: code,
            flag,
            value,
        });
    } catch (e) {
        row[flag] = previous;
        refreshGrid();
        toast.error(`Nie udalo sie zapisac znacznika dla ${code}`);
    }
}

/** Kolumna z checkboxem Tak/Nie dla znacznika ustawianego recznie. */
function flagColumn(flag: FlagKey, name: string, size: number) {
    return {
        prop: flag,
        name,
        readonly: true,
        size,
        sortable: true,
        cellTemplate: (h: any, p: any) => {
            const code = String(p.model?.product_code ?? "");
            const checked = !!p.model?.[flag];

            return h("label", { class: "revo-flag-cell" }, [
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
                            color: checked ? "#15803d" : "#9ca3af",
                            fontWeight: checked ? "600" : "400",
                        },
                    },
                    checked ? "Tak" : "Nie"
                ),
            ]);
        },
    };
}

const columns = computed(() => [
    {
        // Liczba porzadkowa liczona z pozycji w AKTUALNYM widoku — po posortowaniu
        // po sprzedazy Lp 1 to najlepiej schodzacy kod, po filtrze numeracja leci od nowa.
        // Przed numerem kolorowy pasek etapu: etap widac katem oka przy przewijaniu,
        // bez czytania kolumny.
        prop: "__lp__",
        name: "Lp",
        readonly: true,
        sortable: false,
        size: 80,
        cellTemplate: (h: any, p: any) => {
            const index = Array.isArray(p.data) ? p.data.indexOf(p.model) : -1;
            const lp = (index >= 0 ? index : Number(p.rowIndex) || 0) + 1;
            const color = isDone(p.model)
                ? DONE_COLOR
                : stageById.value.get(Number(p.model?.stage_id))?.color ?? "transparent";

            return h("span", { class: "revo-lp-cell" }, [
                h("span", { class: "revo-lp-stripe", style: { background: color } }),
                h("span", { style: { color: "#9ca3af" } }, String(lp)),
            ]);
        },
    },
    { prop: "product_code", name: "Kod", readonly: true, size: 160, sortable: true },
    {
        prop: "name",
        name: "Nazwa",
        readonly: true,
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
        // Sprzedaz z raportu Subiekta. Zero wyszarzone, zeby wzrokiem od razu
        // bylo widac, co sie w ogole nie sprzedawalo.
        prop: "sales_12m",
        name: "Sprzedaż 12 mc",
        readonly: true,
        size: 150,
        sortable: true,
        cellCompare: (prop: string, a: any, b: any): number =>
            (Number(a?.[prop]) || 0) - (Number(b?.[prop]) || 0),
        cellTemplate: (h: any, p: any) => {
            const value = Number(p.model?.sales_12m) || 0;
            return h("span", value === 0 ? { style: { color: "#9ca3af" } } : {}, String(value));
        },
    },
    {
        // Etap wynika ze sprzedazy i przedzialow z Ustawien — stad tylko do odczytu.
        // Jedna kolumna zamiast kolumny-na-etap, bo etapow moze byc dowolnie duzo.
        prop: "stage_id",
        name: "Etap",
        readonly: true,
        size: 150,
        sortable: true,
        // Sortujemy po TYM, co widac: „Gotowe" na koniec, bez etapu na poczatek.
        // Inaczej wiersze wypchniete do Gotowe rozsypalyby sie po swoich starych etapach.
        cellCompare: (prop: string, a: any, b: any): number => {
            const key = (row: any): number =>
                isDone(row) ? Number.MAX_SAFE_INTEGER : (Number(row?.stage_id) || -1);
            return key(a) - key(b);
        },
        cellTemplate: (h: any, p: any) => {
            // Reczne oznaczenie przebija etap ze sprzedazy — kod pokazuje sie
            // jako „Gotowe", a nie jako Etap N.
            const done = isDone(p.model);
            const stage = done
                ? { name: "Gotowe", color: DONE_COLOR }
                : stageById.value.get(Number(p.model?.stage_id));

            if (!stage) {
                return h("span", { style: { color: "#d1d5db" } }, "—");
            }

            return h(
                "span",
                {
                    class: "revo-stage-badge",
                    style: { color: stage.color, background: stage.color + "1f" },
                },
                stage.name
            );
        },
    },
    flagColumn("project", "Projekt", 120),
    flagColumn("team_steel", "Team Steel", 140),
    flagColumn("bez_wspornikow", "Bez wsporników", 160),
    flagColumn("projekty_gotowe", "Projekty gotowe", 165),
]);

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

// === ZAKLADKI: RZAD 2 (etapy ze slownika + „Gotowe") ===
// „Gotowe" nie jest etapem ze slownika — nie wynika ze sprzedazy, tylko z tego,
// ze kod ma juz Projekt albo Team Steel. Ale WYPYCHA z etapu: kod oznaczony
// recznie znika z Etapu N i pokazuje sie w Gotowe. Dzieki temu kubelki sa
// rozlaczne i procenty dalej sumuja sie do 100%.
//
// Wypchniecie liczy sie na froncie, a nie kasuje `stage_id` w bazie: zdjecie
// Team Steel ma natychmiast przywrocic etap ze sprzedazy, bez biegania do
// „Przelicz etapy".
const DONE_COLOR = "#16a34a";

type StageTabKey = "all" | "none" | "done" | number;
const activeStage = ref<StageTabKey>("all");

const isDone = (row: any): boolean => !!row?.project || !!row?.team_steel;

/** Etap widoczny na ekranie: „gotowe" przebija etap ze sprzedazy. */
const shownStageId = (row: any): number | null =>
    isDone(row) ? null : (row?.stage_id ?? null);

function matchesStage(row: any, tab: StageTabKey): boolean {
    if (tab === "all") return true;
    if (tab === "done") return isDone(row);
    if (tab === "none") return !isDone(row) && shownStageId(row) === null;
    return shownStageId(row) === Number(tab);
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
// Etap jest jeden na kod, wiec suma etapow + „bez etapu" zawsze daje 100%.
// `share` to wylacznie szerokosc segmentu paska — nigdzie nie pokazujemy go
// jako liczby. Procenty przy etapach zdjete: maja byc rozwiazane inaczej.
const stageBreakdown = computed(() => {
    const source = allRows.value;
    const total = source.length || 1;

    const stages = props.stages.map((stage) => {
        const count = source.filter((r) => shownStageId(r) === stage.id).length;
        return {
            id: stage.id as number | string,
            label: stage.name,
            color: stage.color,
            count,
            share: (count / total) * 100,
        };
    });

    // „Gotowe" na koncu paska — wypchniete z etapow, wiec nie dubluje zadnego segmentu.
    const done = doneCount.value;
    stages.push({
        id: "done",
        label: "Gotowe",
        color: DONE_COLOR,
        count: done,
        share: (done / total) * 100,
    });

    return stages;
});

const noStage = computed(() => {
    const source = allRows.value;
    const count = source.filter((r) => !isDone(r) && shownStageId(r) === null).length;
    return { count };
});

const doneCount = computed<number>(() => allRows.value.filter(isDone).length);

const stageTabs = computed(() => {
    const source = allRows.value;

    // stageBreakdown niesie juz „Gotowe" na koncu — zakladki lecą z tego samego
    // zrodla co pasek, zeby liczby nie mialy prawa sie rozjechac.
    const tabs = [
        { key: "all" as StageTabKey, label: "Wszystkie etapy", color: "", count: source.length },
        ...stageBreakdown.value.map((part) => ({
            key: part.id as StageTabKey,
            label: part.label,
            color: part.color,
            count: part.count,
        })),
    ];

    // „Bez etapu" pokazujemy tylko wtedy, gdy naprawde cos zostalo poza etapami.
    // Przy przedzialach pokrywajacych caly zakres byloby to wieczne zero.
    if (noStage.value.count > 0) {
        tabs.push({
            key: "none" as StageTabKey,
            label: "Bez etapu",
            color: "#9ca3af",
            count: noStage.value.count,
        });
    }

    return tabs;
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
</script>

<style scoped>
:deep(.revo-flag-cell) {
    display: flex;
    align-items: center;
    height: 100%;
    width: 100%;
    cursor: pointer;
}

:deep(.revo-lp-cell) {
    display: flex;
    align-items: center;
    gap: 8px;
    height: 100%;
}

:deep(.revo-lp-stripe) {
    display: inline-block;
    width: 3px;
    height: 16px;
    border-radius: 2px;
    flex: none;
}

:deep(.revo-stage-badge) {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 12px;
    font-weight: 600;
    line-height: 18px;
}
</style>
