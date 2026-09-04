<template>
    <PageHeader
        sticky
        title="Magazyn"
        subtitle="Stany magazynowe kodów — źródłem będzie Subiekt GT"
    />

    <PageContent>
        <div class="w-full">
            <Card>
                <!-- Magazyny z lewej, funkcje z prawej strony paska. Drugi magazyn
                     dokłada się przed „Ustawieniami", a nie na koniec listy. -->
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
                            <span v-if="tab.count !== null" class="ml-1 text-xs text-gray-400">
                                {{ tab.count }}
                            </span>
                        </button>
                    </nav>
                </div>

                <!-- === MAGAZYN M3R === -->
                <template v-if="activeTab === 'm3r'">
                    <!-- Dopoki zadne zrodlo nie plynie, obie kolumny ilosci sa puste —
                         bez tego paska czytalyby sie jak „zero sztuk na stanie". -->
                    <div class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm">
                        <div class="font-medium text-amber-900">
                            Ilości jeszcze nie są zaciągane
                        </div>
                        <div class="mt-1 space-y-1 text-amber-800">
                            <div>
                                Lista kodów jest kompletna i gotowa na podpięcie dwóch źródeł:
                                <strong>Stan M3R</strong> — realny stan ze wskazanego magazynu
                                Subiekta GT przez ARGO Bridge; <strong>Tabela</strong> — ilości
                                z arkusza Google, w którym gospodarka magazynowa prowadzona jest
                                ręcznie.
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

                <!-- === USTAWIENIA === -->
                <div v-else-if="activeTab === 'settings'" class="py-10 text-center">
                    <Cog6ToothIcon class="mx-auto h-10 w-10 text-gray-300" />
                    <h3 class="mt-3 text-base font-medium text-gray-900">Ustawienia magazynu</h3>
                    <p class="mx-auto mt-2 max-w-md text-sm text-gray-500">
                        Jeszcze pusto. Tu wskażemy, który magazyn Subiekta GT jest
                        czytany jako M3R, jak często leci odczyt i co robić z kodami,
                        których w Subiekcie nie ma.
                    </p>
                </div>

                <!-- === LOGI === -->
                <div v-else class="py-10 text-center">
                    <ClipboardDocumentListIcon class="mx-auto h-10 w-10 text-gray-300" />
                    <h3 class="mt-3 text-base font-medium text-gray-900">Logi odczytów</h3>
                    <p class="mx-auto mt-2 max-w-md text-sm text-gray-500">
                        Jeszcze pusto. Tu wyląduje dziennik pobrań stanu z Subiekta GT
                        przez ARGO Bridge — kiedy, ile kodów, co się nie zgodziło.
                    </p>
                </div>
            </Card>
        </div>

        <!-- Ramka po najechaniu na „+N" — poza karta i na fixed, bo komorki
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
        </div>
    </PageContent>
</template>

<script setup lang="ts">
import { computed, reactive, ref } from "vue";
import { ClipboardDocumentListIcon, Cog6ToothIcon } from "@heroicons/vue/24/outline";
import {
    Card,
    DataGrid,
    PageContent,
    PageHeader,
    SelectInput,
    TextInput,
} from "crafter/Components";

interface WarehouseRow {
    product_id: number;
    product_code: string;
    name: string;
    material: string;
    variants: number;
    variant_names: string[];
}

interface Props {
    rows: WarehouseRow[];
}

const props = defineProps<Props>();

// Kopia lokalna — DataGrid pracuje na v-model, a props Inertii sa zamrozone.
const rows = ref<WarehouseRow[]>([...props.rows]);
const gridRef = ref<any>(null);

// === ZAKLADKI ===
// Kazdy magazyn = jedna zakladka; kolejny to jeden wpis w `tabs` plus kolumna
// stanu po stronie serwera. Ustawienia i Logi stoja na koncu paska.
type TabKey = "m3r" | "settings" | "logs";
const activeTab = ref<TabKey>("m3r");

const tabs = computed(() => [
    { key: "m3r" as TabKey, label: "Magazyn M3R", count: rows.value.length as number | null },
    { key: "settings" as TabKey, label: "Ustawienia", count: null },
    { key: "logs" as TabKey, label: "Logi", count: null },
]);

function tabClass(active: boolean): string {
    return [
        "border-b-2 px-1 py-3 text-sm font-medium whitespace-nowrap",
        active
            ? "border-primary-500 text-primary-600"
            : "border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700",
    ].join(" ");
}

// === RAMKA PO NAJECHANIU NA „+N" ===
const hoverBox = ref<{ show: boolean; x: number; y: number; title: string; lines: string[] }>({
    show: false,
    x: 0,
    y: 0,
    title: "",
    lines: [],
});

function showBox(event: MouseEvent, title: string, lines: string[]): void {
    const rect = (event.currentTarget as HTMLElement)?.getBoundingClientRect();
    if (!rect) return;

    hoverBox.value = { show: true, x: rect.left, y: rect.bottom + 6, title, lines };
}

function hideBox(): void {
    hoverBox.value = { ...hoverBox.value, show: false };
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
    { prop: "product_code", name: "Kod", readonly: true, size: 170, sortable: true },
    {
        prop: "name",
        name: "Nazwa",
        readonly: true,
        size: 480,
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
</style>
