<template>
    <PageHeader
        sticky
        title="Magazyn — Logi"
        subtitle="Kto, gdzie i co zmienił"
    />

    <PageContent>
        <div class="w-full">
            <Card>
                <!-- Jeden dziennik na caly dzial, filtrowany po ekranie. Osobne
                     logi per zakladka rozjechalyby sie przy pierwszej akcji, ktora
                     dotyka dwoch ekranow naraz — mapowanie zrobione w Tabeli
                     zmienia przeciez liste M3R. -->
                <div class="mb-4 border-b border-gray-200">
                    <nav class="-mb-px flex flex-wrap gap-6">
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
                    <SelectInput
                        v-model="search.user"
                        name="search_user"
                        label="Użytkownik"
                        :options="userOptions"
                    />
                    <TextInput
                        v-model="search.code"
                        name="search_code"
                        label="Kod"
                        placeholder="np. 00.004"
                        clearable
                    />
                    <TextInput
                        v-model="search.text"
                        name="search_text"
                        label="Szukaj w opisie"
                        placeholder="np. rezerwacja, k5g2"
                        clearable
                    />
                </div>

                <div class="mb-3 text-xs text-gray-500">
                    Widocznych: <strong>{{ visibleCount }}</strong>
                    / Wpisów: <strong>{{ rows.length }}</strong>
                    <span class="ml-4 text-gray-400">
                        Dziennik trzyma 2000 ostatnich zdarzeń.
                    </span>
                </div>

                <div v-if="!rows.length" class="py-10 text-center">
                    <ClipboardDocumentListIcon class="mx-auto h-10 w-10 text-gray-300" />
                    <h3 class="mt-3 text-base font-medium text-gray-900">Dziennik jest pusty</h3>
                    <p class="mx-auto mt-2 max-w-lg text-sm text-gray-500">
                        Trafi tu każda zmiana zrobiona w Magazynie: poprawka komórki
                        w Tabeli, mapowanie kodu, rezerwacja, import arkusza i paczka
                        stanów z ARGO Bridge — razem z tym, kto ją zrobił.
                    </p>
                </div>

                <DataGrid
                    v-else
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
    </PageContent>
</template>

<script setup lang="ts">
import { computed, ref } from "vue";
import { ClipboardDocumentListIcon } from "@heroicons/vue/24/outline";
import {
    Card,
    DataGrid,
    PageContent,
    PageHeader,
    SelectInput,
    TextInput,
} from "crafter/Components";

interface LogRow {
    id: number;
    at: string | null;
    user: string;
    area: string;
    area_label: string;
    action: string;
    source_code: string | null;
    product_code: string | null;
    description: string;
}

interface Props {
    logs: LogRow[];
    /** Klucz ekranu => etykieta. Ta sama mapa co po stronie serwera. */
    areas: Record<string, string>;
    users: string[];
}

const props = withDefaults(defineProps<Props>(), {
    logs: () => [],
    areas: () => ({}),
    users: () => [],
});

const rows = ref<LogRow[]>([...props.logs]);
const gridRef = ref<any>(null);

const search = ref({ user: "", code: "", text: "" });

const userOptions = computed(() => [
    { value: "", label: "Wszyscy" },
    ...props.users.map((name) => ({ value: name, label: name })),
]);

// Zakladki liczone z danych, nie z listy ekranow — pusty ekran nie zaśmieca
// paska, a „Wszystkie" zawsze stoi pierwsze.
const activeTab = ref("all");

const tabs = computed(() => {
    const counts: Record<string, number> = {};
    for (const row of props.logs) counts[row.area] = (counts[row.area] ?? 0) + 1;

    return [
        { key: "all", label: "Wszystkie", count: props.logs.length },
        ...Object.keys(props.areas)
            .filter((area) => counts[area])
            .map((area) => ({ key: area, label: props.areas[area], count: counts[area] })),
    ];
});

function tabClass(active: boolean): string {
    return [
        "border-b-2 px-1 py-3 text-sm font-medium whitespace-nowrap",
        active
            ? "border-primary-500 text-primary-600"
            : "border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700",
    ].join(" ");
}

/** Techniczne nazwy akcji na ludzkie — „cell.update" nic nie mówi po tygodniu. */
const ACTIONS: Record<string, string> = {
    "cell.update": "Edycja komórki",
    "map.store": "Mapowanie",
    "map.destroy": "Odpięcie mapowania",
    "reservation.create": "Rezerwacja",
    "reservation.release": "Zwolnienie rezerwacji",
    "sheet.import": "Import arkusza",
    "bridge.stock": "Paczka stanów",
    "bridge.settings": "Ustawienia połączenia",
    "bridge.token": "Nowy token",
};

function filterFn(row: LogRow): boolean {
    if (activeTab.value !== "all" && row.area !== activeTab.value) return false;
    if (search.value.user && row.user !== search.value.user) return false;

    const code = search.value.code.trim().toLowerCase();
    if (code) {
        const haystack = `${row.source_code ?? ""} ${row.product_code ?? ""}`.toLowerCase();
        if (!haystack.includes(code)) return false;
    }

    const text = search.value.text.trim().toLowerCase();
    if (text && !row.description.toLowerCase().includes(text)) return false;

    return true;
}

const visibleCount = computed(() => rows.value.filter(filterFn).length);

const columns = computed(() => [
    {
        prop: "at",
        name: "Kiedy",
        readonly: true,
        size: 140,
        sortable: true,
        cellTemplate: (h: any, p: any) =>
            h("span", { style: { color: "#6b7280" } }, String(p.model?.at ?? "—")),
    },
    { prop: "user", name: "Użytkownik", readonly: true, size: 170, sortable: true },
    {
        prop: "area_label",
        name: "Ekran",
        readonly: true,
        size: 150,
        sortable: true,
        cellTemplate: (h: any, p: any) =>
            h("span", { class: "revo-area-chip" }, String(p.model?.area_label ?? "")),
    },
    {
        prop: "action",
        name: "Akcja",
        readonly: true,
        size: 190,
        sortable: true,
        cellTemplate: (h: any, p: any) => {
            const action = String(p.model?.action ?? "");
            return h("span", {}, ACTIONS[action] ?? action);
        },
    },
    {
        prop: "source_code",
        name: "Kod",
        readonly: true,
        size: 150,
        sortable: true,
        cellTemplate: (h: any, p: any) => {
            const value = p.model?.source_code;
            return value
                ? h("span", {}, String(value))
                : h("span", { style: { color: "#d1d5db" } }, "—");
        },
    },
    { prop: "description", name: "Co się stało", readonly: true, size: 620, sortable: false },
]);
</script>

<style scoped>
/* Ta sama siatka co w Tabeli — dziennik czyta sie tak samo jak arkusz. */
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

:deep(revogr-data .rgRow:nth-of-type(odd) .rgCell) {
    background: #ffffff;
}

:deep(revogr-data .rgRow:nth-of-type(even) .rgCell) {
    background: #f2f2f2;
}

:deep(.revo-area-chip) {
    padding: 0 8px;
    border-radius: 9px;
    border: 1px solid #e5e7eb;
    background: #f9fafb;
    font-size: 11px;
    font-weight: 600;
    line-height: 18px;
    color: #4b5563;
}
</style>
