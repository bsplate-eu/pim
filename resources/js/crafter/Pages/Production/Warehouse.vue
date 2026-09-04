<template>
    <PageHeader
        sticky
        title="Magazyn M3R"
        subtitle="Kody i ilości — Subiekt GT przez ARGO Bridge oraz arkusz Google"
    />

    <PageContent>
        <div class="w-full">
            <Card>
                <!-- Kody, ktore maja pare w PIM, i wiersze ze zrodel, ktore pary nie
                     maja. Kolizje kodow miedzy arkuszem a Subiektem sa pewne, wiec
                     „do zmapowania" to staly kubelek roboczy, a nie lista bledow. -->
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

                <!-- === ZMAPOWANE === -->
                <template v-if="activeTab === 'mapped'">
                    <!-- Dopoki zadne zrodlo nie plynie, obie kolumny ilosci sa puste —
                         bez tego paska czytalyby sie jak „zero sztuk na stanie". -->
                    <div v-if="!anySourceData" class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm">
                        <div class="font-medium text-amber-900">
                            Ilości jeszcze nie są zaciągane
                        </div>
                        <div class="mt-1 space-y-1 text-amber-800">
                            <div>
                                Lista kodów jest kompletna i gotowa na podpięcie dwóch źródeł:
                                <strong>Stan M3R</strong> — realny stan ze wskazanego magazynu
                                Subiekta GT przez ARGO Bridge; <strong>Tabela</strong> — ilości
                                z arkusza Google, w którym gospodarka magazynowa prowadzona jest
                                ręcznie. Mapowanie kodów można ustawiać już teraz.
                            </div>
                            <div>
                                Kreska w kolumnie znaczy „brak odczytu", a nie „zero sztuk".
                            </div>
                        </div>
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

                    <div class="mb-3 text-xs text-gray-500">
                        Widocznych: <strong>{{ visibleCount }}</strong>
                        / Wszystkich kodów: <strong>{{ totalCount }}</strong>
                        <span class="ml-4">
                            Zmapowanych z Subiekta: <strong>{{ mappedCount("gt") }}</strong>
                        </span>
                        <span class="ml-4">
                            Zmapowanych z Tabeli: <strong>{{ mappedCount("sheet") }}</strong>
                        </span>
                    </div>

                    <DataGrid
                        ref="gridRef"
                        v-model="rows"
                        :columns="columns"
                        :filter="filterFn"
                        keyField="product_code"
                        height="auto"
                    />
                </template>

                <!-- === DO ZMAPOWANIA === -->
                <template v-else-if="activeTab === 'unmapped'">
                    <div v-if="!unmapped.length" class="py-10 text-center">
                        <LinkSlashIcon class="mx-auto h-10 w-10 text-gray-300" />
                        <h3 class="mt-3 text-base font-medium text-gray-900">
                            Nic nie czeka na zmapowanie
                        </h3>
                        <p v-if="anySourceData" class="mx-auto mt-2 max-w-lg text-sm text-gray-500">
                            Każdy kod z ostatniej paczki znalazł swój odpowiednik w PIM —
                            albo sam, bo kody są identyczne, albo przez ręczne przypisanie.
                        </p>
                        <p v-else class="mx-auto mt-2 max-w-lg text-sm text-gray-500">
                            Trafią tu wiersze z arkusza i z Subiekta GT, których kod nie
                            ma pary w PIM. Pusto, bo żadne ze źródeł jeszcze nie zostało
                            zaciągnięte — nie dlatego, że wszystko się zgadza.
                        </p>
                    </div>

                    <template v-else>
                        <!-- Pasek masowy — pojawia sie dopiero gdy cos zaznaczone,
                             tak samo jak w Wykluczeniach w Produkcji. -->
                        <div
                            v-if="selected.size"
                            class="mb-3 flex flex-wrap items-center gap-3 rounded-md border border-indigo-200 bg-indigo-50 px-4 py-2"
                        >
                            <span class="text-sm text-indigo-900">
                                Zaznaczonych: <strong>{{ selected.size }}</strong>
                            </span>
                            <div class="ml-auto flex items-center gap-2">
                                <Button size="sm" :loading="bulkBusy" @click="bulkExclude(true)">
                                    Wyklucz zaznaczone
                                </Button>
                                <Button size="sm" color="gray" variant="ghost" @click="clearSelection">
                                    Wyczyść
                                </Button>
                            </div>
                        </div>

                        <div class="mb-3 flex items-center gap-4 text-xs text-gray-500">
                            <span>Wierszy do zmapowania: <strong>{{ unmapped.length }}</strong></span>
                            <button type="button" class="text-primary-600 hover:underline" @click="selectAll(unmapped)">
                                Zaznacz wszystkie
                            </button>
                        </div>

                        <DataGrid
                            ref="unmappedGridRef"
                            v-model="unmapped"
                            :columns="unmappedColumns"
                            keyField="key"
                            height="auto"
                        />
                    </template>
                </template>

                <!-- === WYKLUCZONE === -->
                <template v-else-if="activeTab === 'excluded'">
                    <div v-if="!excluded.length" class="py-10 text-center">
                        <ArchiveBoxXMarkIcon class="mx-auto h-10 w-10 text-gray-300" />
                        <h3 class="mt-3 text-base font-medium text-gray-900">Nic nie wykluczono</h3>
                        <p class="mx-auto mt-2 max-w-lg text-sm text-gray-500">
                            Magazyn trzyma nie tylko osłony — simmeringi, klocki, wykładzina.
                            Zaznacz je w „Do zmapowania" i odłóż tutaj, żeby przestały
                            zaśmiecać listę roboczą. Nic przy tym nie znika z Subiekta.
                        </p>
                    </div>

                    <template v-else>
                        <div
                            v-if="selected.size"
                            class="mb-3 flex flex-wrap items-center gap-3 rounded-md border border-indigo-200 bg-indigo-50 px-4 py-2"
                        >
                            <span class="text-sm text-indigo-900">
                                Zaznaczonych: <strong>{{ selected.size }}</strong>
                            </span>
                            <div class="ml-auto flex items-center gap-2">
                                <Button size="sm" color="gray" variant="outline" :loading="bulkBusy" @click="bulkExclude(false)">
                                    Przywróć zaznaczone
                                </Button>
                                <Button size="sm" color="gray" variant="ghost" @click="clearSelection">
                                    Wyczyść
                                </Button>
                            </div>
                        </div>

                        <div class="mb-3 flex items-center gap-4 text-xs text-gray-500">
                            <span>Wykluczonych: <strong>{{ excluded.length }}</strong></span>
                            <button type="button" class="text-primary-600 hover:underline" @click="selectAll(excluded)">
                                Zaznacz wszystkie
                            </button>
                        </div>

                        <DataGrid
                            ref="excludedGridRef"
                            v-model="excluded"
                            :columns="excludedColumns"
                            keyField="key"
                            height="auto"
                        />
                    </template>
                </template>
            </Card>
        </div>

        <!-- Ramka po najechaniu na chip — poza karta i na fixed, bo komorki
             RevoGrida maja overflow: hidden i popover w srodku bylby uciety.
             Ten sam wzorzec co na liscie kodow produkcji. -->
        <div
            v-if="hoverBox.show"
            class="fixed z-50 max-h-80 max-w-lg overflow-auto rounded-md border border-gray-200 bg-white px-3 py-2 shadow-lg"
            :style="{ left: hoverBox.x + 'px', top: hoverBox.y + 'px' }"
        >
            <div class="mb-1 text-xs font-semibold text-gray-500">{{ hoverBox.title }}</div>
            <div v-for="(line, i) in hoverBox.lines" :key="i" class="py-0.5 text-sm text-gray-900">
                {{ line }}
            </div>
            <div v-if="hoverBox.note" class="mt-1 border-t border-gray-100 pt-1 text-xs text-gray-400">
                {{ hoverBox.note }}
            </div>
        </div>

        <!-- === MAPOWANIE RECZNE === -->
        <Modal :open="mapModal.show" externalOpen size="md" alignButtons="right" @toggleOpen="closeMap">
            <template #title>
                Mapowanie: {{ mapModal.product_code }} ↔ {{ sourceLabel(mapModal.source) }}
            </template>

            <template #content>
                <p class="text-sm text-gray-500">
                    Kod z tej strony może mieć kilka odpowiedników w źródle — ilości się
                    wtedy sumują. Jeden kod źródłowy trafia zawsze do jednego kodu PIM.
                </p>

                <div v-if="mapModal.codes.length" class="mt-4 space-y-2">
                    <div
                        v-for="entry in mapModal.codes"
                        :key="entry.code"
                        class="flex items-center justify-between rounded border border-gray-200 px-3 py-2"
                    >
                        <span class="flex items-center gap-2">
                            <code class="text-sm text-gray-900">{{ entry.code }}</code>
                            <span v-if="entry.auto" class="text-xs text-gray-400">
                                dopasowane po kodzie
                            </span>
                        </span>
                        <!-- Automat nie ma czego odpinac: dopasowanie wynika z tego, ze
                             kody sa identyczne. Zniknie samo, gdy zniknie po tamtej stronie. -->
                        <span v-if="entry.auto" class="text-sm text-gray-300">—</span>
                        <button
                            v-else
                            type="button"
                            class="text-sm text-gray-400 hover:text-red-600"
                            :disabled="mapModal.busy"
                            @click="removeMap(entry.code)"
                        >
                            Odepnij
                        </button>
                    </div>
                </div>
                <div v-else class="mt-4 rounded border border-dashed border-gray-300 px-3 py-4 text-center text-sm text-gray-400">
                    Nic jeszcze nie przypisane
                </div>

                <div class="mt-4 flex items-end gap-2">
                    <div class="flex-1">
                        <TextInput
                            v-model="mapModal.input"
                            name="map_source_code"
                            :label="`Kod w źródle (${sourceLabel(mapModal.source)})`"
                            placeholder="wpisz kod dokładnie tak, jak jest w źródle"
                            @keyup.enter="addMap"
                        />
                    </div>
                    <Button class="mb-1" :loading="mapModal.busy" @click="addMap">Dopnij</Button>
                </div>
            </template>

            <template #buttons>
                <Button variant="outline" color="gray" @click="closeMap">Zamknij</Button>
            </template>
        </Modal>
    </PageContent>
</template>

<script setup lang="ts">
import { computed, reactive, ref, watch } from "vue";
import axios from "axios";
import { useToast } from "@brackets/vue-toastification";
import { ArchiveBoxXMarkIcon, LinkSlashIcon } from "@heroicons/vue/24/outline";
import { router } from "@inertiajs/vue3";
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
 * Kod zrodlowy przypiety do kodu PIM. `auto` = dopasowal sie sam, bo kody sa
 * identyczne — takiego nie da sie odpiac, bo nie ma czego usunac.
 */
interface MapEntry {
    code: string;
    auto: boolean;
}

interface WarehouseRow {
    product_id: number;
    product_code: string;
    name: string;
    material: string;
    variants: number;
    variant_names: string[];
    // Kody zrodlowe przypiete do tego kodu PIM — po jednej liscie na zrodlo.
    map_gt?: MapEntry[];
    map_sheet?: MapEntry[];
    stock?: number | null;
    sheet_qty?: number | null;
}

/**
 * Wiersz ze zrodla, ktory nie ma pary w PIM. Kod jest tu SUROWY — taki, jaki
 * przyszedl z arkusza albo z Subiekta — bo wlasnie o to, ze nie pasuje do
 * zadnego `product_code`, w tej zakladce chodzi.
 */
interface UnmappedRow {
    key: string;
    source_code: string;
    name: string | null;
    source: string;
    quantity: number | null;
    reason: string;
}

/** Wiersz odlozony na bok — ten sam ksztalt co „do zmapowania", bez powodu. */
interface ExcludedRow {
    key: string;
    source: string;
    source_label: string;
    source_code: string;
    name: string | null;
    quantity: number | null;
}

interface Props {
    rows: WarehouseRow[];
    unmapped?: UnmappedRow[];
    excluded?: ExcludedRow[];
    sources: Record<string, string>;
    // Czy z danego zrodla przyszla juz jakakolwiek migawka. Bez tego nie da sie
    // odroznic „nic nie przyszlo" od „przyszlo i sa zera".
    has_stock?: Record<string, boolean>;
}

const props = withDefaults(defineProps<Props>(), {
    unmapped: () => [],
    excluded: () => [],
    has_stock: () => ({}),
});

const anySourceData = computed<boolean>(() => Object.values(props.has_stock ?? {}).some(Boolean));
const toast = useToast();

// Kopie lokalne — DataGrid pracuje na v-model, a props Inertii sa zamrozone.
const rows = ref<WarehouseRow[]>([...props.rows]);
const unmapped = ref<UnmappedRow[]>([...props.unmapped]);
const excluded = ref<ExcludedRow[]>([...props.excluded]);
const gridRef = ref<any>(null);
const unmappedGridRef = ref<any>(null);
const excludedGridRef = ref<any>(null);

// Skroty na chipy w kolumnie Mapowanie — pelna nazwa zrodla nie miesci sie
// w komorce, a i tak widac ja po najechaniu.
const SHORT: Record<string, string> = { gt: "GT", sheet: "TAB" };

const sourceKeys = computed<string[]>(() => Object.keys(props.sources));
const sourceLabel = (key: string): string => props.sources[key] ?? key;

// === ZAKLADKI: STAN ZMAPOWANIA ===
// „Zmapowane" to kody PIM — jedyna lista, na ktorej ilosc da sie do czegokolwiek
// przypiac. „Do zmapowania" to wiersze ze zrodel bez pary: kod z arkusza albo z
// Subiekta, ktorego w PIM nie ma, albo ktory pasuje do wiecej niz jednego.
// „Wykluczone" to trzeci kubelek: wiersze odlozone recznie, bo nigdy nie beda
// mialy pary w PIM (simmeringi, klocki, wykladzina). Nie licza sie nigdzie.
type TabKey = "mapped" | "unmapped" | "excluded";
const activeTab = ref<TabKey>("mapped");

const tabs = computed(() => [
    { key: "mapped" as TabKey, label: "Zmapowane", count: rows.value.length },
    { key: "unmapped" as TabKey, label: "Do zmapowania", count: unmapped.value.length },
    { key: "excluded" as TabKey, label: "Wykluczone", count: excluded.value.length },
]);

// Zaznaczenie zerujemy przy zmianie zakladki — inaczej „Przywroc zaznaczone"
// dzialaloby na wierszach, ktorych juz nie widac.
watch(activeTab, () => clearSelection());

function tabClass(active: boolean): string {
    return [
        "border-b-2 px-1 py-3 text-sm font-medium whitespace-nowrap",
        active
            ? "border-primary-500 text-primary-600"
            : "border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700",
    ].join(" ");
}

// === RAMKA PO NAJECHANIU ===
const hoverBox = ref<{
    show: boolean;
    x: number;
    y: number;
    title: string;
    lines: string[];
    note: string;
}>({ show: false, x: 0, y: 0, title: "", lines: [], note: "" });

function showBox(event: MouseEvent, title: string, lines: string[], note = ""): void {
    const rect = (event.currentTarget as HTMLElement)?.getBoundingClientRect();
    if (!rect) return;

    hoverBox.value = { show: true, x: rect.left, y: rect.bottom + 6, title, lines, note };
}

function hideBox(): void {
    hoverBox.value = { ...hoverBox.value, show: false };
}

// RevoGrid nie sledzi mutacji pol wiersza — podmiana referencji zrodla wymusza
// przerysowanie po zapisaniu mapowania.
function refreshGrid(): void {
    const grid = gridRef.value;
    if (grid) grid.setSource([...grid.getSource()]);
}

function rowByCode(code: string): any {
    const source: any[] = gridRef.value?.getSource?.() ?? rows.value;
    return source.find((r) => r.product_code === code);
}

function mappedCodes(row: any, source: string): MapEntry[] {
    const value = row?.["map_" + source];
    return Array.isArray(value) ? value : [];
}

const mappedCount = (source: string): number =>
    (gridRef.value?.getSource?.() ?? rows.value).filter(
        (r: any) => mappedCodes(r, source).length > 0
    ).length;

// === MAPOWANIE RECZNE ===
const mapModal = reactive<{
    show: boolean;
    product_code: string;
    source: string;
    codes: MapEntry[];
    input: string;
    busy: boolean;
}>({ show: false, product_code: "", source: "gt", codes: [], input: "", busy: false });

function openMap(productCode: string, source: string): void {
    hideBox();
    mapModal.show = true;
    mapModal.product_code = productCode;
    mapModal.source = source;
    mapModal.codes = [...mappedCodes(rowByCode(productCode), source)];
    mapModal.input = "";
}

function closeMap(): void {
    mapModal.show = false;
}

/** Odpowiedz serwera niesie komplet przypisan kodu — wpisujemy ja w wiersz. */
function applyPayload(payload: any): void {
    const row = rowByCode(payload.product_code);
    if (row) {
        sourceKeys.value.forEach((key) => {
            row["map_" + key] = payload["map_" + key] ?? [];
        });
        refreshGrid();
    }

    mapModal.codes = payload["map_" + mapModal.source] ?? [];
}

async function addMap(): Promise<void> {
    const code = mapModal.input.trim();
    if (!code || mapModal.busy) return;

    mapModal.busy = true;
    try {
        const { data } = await axios.post(route("crafter.production.warehouse.map.store"), {
            product_code: mapModal.product_code,
            source: mapModal.source,
            source_code: code,
        });
        applyPayload(data);
        mapModal.input = "";
        toast.success(`Dopięto ${code}`);
    } catch (e: any) {
        // 422 z kontrolera niesie konkretny powod — np. ze kod wisi juz na innym produkcie.
        toast.error(e?.response?.data?.message ?? "Nie udało się dopiąć kodu");
    } finally {
        mapModal.busy = false;
    }
}

async function removeMap(code: string): Promise<void> {
    if (mapModal.busy) return;

    mapModal.busy = true;
    try {
        const { data } = await axios.delete(route("crafter.production.warehouse.map.destroy"), {
            data: {
                product_code: mapModal.product_code,
                source: mapModal.source,
                source_code: code,
            },
        });
        applyPayload(data);
        toast.success(`Odpięto ${code}`);
    } catch (e: any) {
        toast.error(e?.response?.data?.message ?? "Nie udało się odpiąć kodu");
    } finally {
        mapModal.busy = false;
    }
}

/**
 * Kolumna z ilością. Zadne ze zrodel jeszcze nie plynie, wiec kazde pole jest
 * puste — kreska zamiast zera, zeby „nie wiem" nie udawalo „nic nie ma".
 * Po podpieciu zrodla wystarczy, ze serwer zacznie oddawac `prop` w wierszu.
 */
function qtyColumn(prop: string, name: string) {
    return {
        prop,
        name,
        readonly: true,
        size: 140,
        sortable: true,
        cellCompare: (key: string, a: any, b: any): number =>
            (Number(a?.[key]) || 0) - (Number(b?.[key]) || 0),
        cellTemplate: (h: any, p: any) => {
            const value = p.model?.[prop];
            if (value === null || value === undefined || value === "") {
                return h("span", { style: { color: "#d1d5db" } }, "—");
            }
            return h("span", {}, String(value));
        },
    };
}

const columns = computed(() => [
    {
        // Lp z pozycji w AKTUALNYM widoku — po filtrze numeracja leci od nowa.
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
    { prop: "product_code", name: "Kod", readonly: true, size: 150, sortable: true },
    {
        // Mapowanie tuz przy kodzie: po jednym chipie na zrodlo. Zielony = jest
        // para (najechanie pokazuje z czym), przerywany „+" = nic nie przypisane
        // i klikniecie otwiera reczne mapowanie.
        prop: "__map__",
        name: "Mapowanie",
        readonly: true,
        sortable: false,
        size: 170,
        cellTemplate: (h: any, p: any) => {
            const code = String(p.model?.product_code ?? "");

            const chips = sourceKeys.value.map((key) => {
                const codes = mappedCodes(p.model, key);
                const label = sourceLabel(key);
                const short = SHORT[key] ?? label;

                if (!codes.length) {
                    return h(
                        "button",
                        {
                            class: "revo-map-chip revo-map-empty",
                            title: `Dopnij kod z: ${label}`,
                            onClick: (e: any) => {
                                e.stopPropagation();
                                openMap(code, key);
                            },
                        },
                        `${short} +`
                    );
                }

                return h(
                    "button",
                    {
                        class: "revo-map-chip revo-map-set",
                        onMouseenter: (e: any) =>
                            showBox(
                                e,
                                `${label} → ${code}`,
                                codes.map((entry: MapEntry) =>
                                    entry.auto ? `${entry.code} — dopasowane po kodzie` : entry.code
                                ),
                                "Kliknij, żeby zmienić przypisanie."
                            ),
                        onMouseleave: hideBox,
                        onClick: (e: any) => {
                            e.stopPropagation();
                            openMap(code, key);
                        },
                    },
                    codes.length > 1 ? `${short} ×${codes.length}` : short
                );
            });

            return h("span", { class: "revo-code-cell" }, chips);
        },
    },
    {
        prop: "name",
        name: "Nazwa",
        readonly: true,
        size: 420,
        sortable: true,
        // Pod jednym kodem siedzi zwykle kilkanascie aut — nazwa to nazwa pierwszego.
        // „+N" mowi, ze reszta istnieje; najechanie pokazuje ich liste.
        cellTemplate: (h: any, p: any) => {
            const name = String(p.model?.name ?? "");
            const names: string[] = Array.isArray(p.model?.variant_names)
                ? p.model.variant_names
                : [];
            const extra = Number(p.model?.variants ?? 1) - 1;

            if (extra <= 0) return h("span", {}, name);

            return h("span", { class: "revo-code-cell" }, [
                h("span", { class: "revo-name-text" }, name),
                h(
                    "span",
                    {
                        class: "revo-name-chip",
                        onMouseenter: (e: any) =>
                            showBox(e, `Produkty pod tym kodem (${names.length})`, names),
                        onMouseleave: hideBox,
                    },
                    `+${extra}`
                ),
            ]);
        },
    },
    { prop: "material", name: "Materiał", readonly: true, size: 140, sortable: true },
    // Dwie ilosci obok siebie i celowo w tej kolejnosci: najpierw to, co ERP
    // uwaza za stan, zaraz obok to, co ludzie wpisali recznie w arkuszu.
    // Rozjazd ma byc widoczny jednym spojrzeniem, bez przewijania w bok.
    qtyColumn("stock", "Stan M3R"),
    qtyColumn("sheet_qty", "Tabela"),
]);

// Kolumny zakladki „Do zmapowania". Bez nazwy produktu — tych wierszy nie ma
// w PIM, wiec nazwy nie ma skad wziac; jest kod ze zrodla i powod odrzucenia.
// === ZAZNACZANIE I WYKLUCZANIE ===
// Klucz zaznaczenia to `key` wiersza (zrodlo:kod) — ten sam po obu stronach,
// wiec przywracanie dziala tak samo jak wykluczanie.
const selected = ref<Set<string>>(new Set());
const bulkBusy = ref(false);

function clearSelection(): void {
    selected.value = new Set();
}

function toggleRow(key: string, on: boolean): void {
    const next = new Set(selected.value);
    on ? next.add(key) : next.delete(key);
    selected.value = next;
    refreshSideGrids();
}

function selectAll(list: Array<{ key: string }>): void {
    selected.value = new Set(list.map((row) => row.key));
    refreshSideGrids();
}

/** RevoGrid nie sledzi mutacji — po zmianie zaznaczenia trzeba przerysowac. */
function refreshSideGrids(): void {
    [unmappedGridRef.value, excludedGridRef.value].forEach((grid) => {
        if (grid?.getSource) grid.setSource([...grid.getSource()]);
    });
}

/** Kolumna z checkboxem — pierwsza, waska, bez nazwy. */
const selectColumn = {
    prop: "__sel__",
    name: "",
    readonly: true,
    sortable: false,
    size: 46,
    cellTemplate: (h: any, p: any) => {
        const key = String(p.model?.key ?? "");

        return h("label", { class: "revo-sel-cell" }, [
            h("input", {
                type: "checkbox",
                checked: selected.value.has(key),
                onClick: (e: any) => e.stopPropagation(),
                onChange: (e: any) => toggleRow(key, !!(e.target as HTMLInputElement).checked),
            }),
        ]);
    },
};

async function bulkExclude(excludedFlag: boolean): Promise<void> {
    const list = excludedFlag ? unmapped.value : excluded.value;
    const picked = list.filter((row: any) => selected.value.has(row.key));

    if (!picked.length || bulkBusy.value) return;

    bulkBusy.value = true;
    try {
        await axios.post(route("crafter.production.warehouse.exclusions.bulk"), {
            excluded: excludedFlag,
            rows: picked.map((row: any) => ({
                // „Do zmapowania" niesie klucz techniczny w `source_key`,
                // „Wykluczone" wprost w `source` — etykieta jest osobno.
                source: row.source_key ?? row.source,
                source_code: row.source_code,
            })),
        });

        clearSelection();
        toast.success(
            excludedFlag
                ? `Wykluczono ${picked.length} pozycji`
                : `Przywrócono ${picked.length} pozycji`
        );

        // Przeliczenie robi serwer — te same reguly co przy wejsciu na ekran.
        router.reload({ only: ["rows", "unmapped", "excluded", "has_stock"] });
    } catch (e: any) {
        toast.error(e?.response?.data?.message ?? "Nie udało się zapisać zmiany");
    } finally {
        bulkBusy.value = false;
    }
}

// Props Inertii przychodza na nowo po `router.reload` — lokalne kopie musza za nimi nadazyc.
watch(
    () => props.unmapped,
    (value) => (unmapped.value = [...(value ?? [])])
);
watch(
    () => props.excluded,
    (value) => (excluded.value = [...(value ?? [])])
);
watch(
    () => props.rows,
    (value) => (rows.value = [...(value ?? [])])
);

const unmappedColumns = computed(() => [
    selectColumn,
    { prop: "source_code", name: "Kod ze źródła", readonly: true, size: 200, sortable: true },
    // Nazwa PO STRONIE ZRODLA — jedyny trop, po ktorym da sie rozpoznac, czym
    // ten wiersz w ogole jest, skoro w PIM go nie ma.
    { prop: "name", name: "Nazwa w źródle", readonly: true, size: 380, sortable: true },
    { prop: "source", name: "Źródło", readonly: true, size: 140, sortable: true },
    {
        prop: "quantity",
        name: "Ilość",
        readonly: true,
        size: 120,
        sortable: true,
        cellCompare: (key: string, a: any, b: any): number =>
            (Number(a?.[key]) || 0) - (Number(b?.[key]) || 0),
    },
    { prop: "reason", name: "Dlaczego", readonly: true, size: 380, sortable: true },
]);

// Wykluczone: to samo bez kolumny „Dlaczego" — powod jest jeden i wynika z zakladki.
const excludedColumns = computed(() => [
    selectColumn,
    { prop: "source_code", name: "Kod ze źródła", readonly: true, size: 200, sortable: true },
    { prop: "name", name: "Nazwa w źródle", readonly: true, size: 380, sortable: true },
    { prop: "source_label", name: "Źródło", readonly: true, size: 140, sortable: true },
    {
        prop: "quantity",
        name: "Ilość",
        readonly: true,
        size: 120,
        sortable: true,
        cellCompare: (key: string, a: any, b: any): number =>
            (Number(a?.[key]) || 0) - (Number(b?.[key]) || 0),
    },
]);

// === WYSZUKIWARKA ===
const search = reactive({
    code: "",
    name: "",
    material: "all",
});

// Lista materialow budowana z danych — ten ekran nie ma osobnego slownika.
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

const allRows = computed<any[]>(() => gridRef.value?.getSource?.() ?? rows.value);

const visibleCount = computed<number>(() => {
    const filter = filterFn.value;
    return filter ? allRows.value.filter(filter).length : allRows.value.length;
});

const totalCount = computed<number>(() => allRows.value.length);
</script>

<style scoped>
:deep(.revo-code-cell) {
    display: flex;
    align-items: center;
    gap: 6px;
    height: 100%;
}

:deep(.revo-name-text) {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

:deep(.revo-name-chip) {
    flex: none;
    color: #9ca3af;
    font-size: 12px;
    cursor: help;
}

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
:deep(.revo-sel-cell) {
    display: flex;
    align-items: center;
    justify-content: center;
    height: 100%;
    width: 100%;
    cursor: pointer;
}

</style>
