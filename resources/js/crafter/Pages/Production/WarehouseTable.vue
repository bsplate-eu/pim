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
                        <span class="mt-1 block text-gray-500">
                            Miejsca, ilości i uwagi poprawisz wprost w tabeli — dwuklik
                            w komórkę, zapis od razu. To korekta na wierzchu importu:
                            kolejny arkusz ją nadpisze.
                        </span>
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

                <div class="mb-3 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <TextInput
                        v-model="search.code"
                        name="search_code"
                        label="Kod"
                        placeholder="np. 00.004"
                        clearable
                    />
                    <!-- Szukanie po aucie, nie po symbolu. Kod trzeba znac na
                         pamiec, nazwe sie widzi — a jeden kod pasuje zwykle do
                         kilkunastu aut, wiec lista nazw nie miesci sie w zadnej
                         kolumnie i jedzie na front schowana. -->
                    <TextInput
                        v-model="search.car"
                        name="search_car"
                        label="Auto"
                        placeholder="np. audi a4"
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

                <!-- Pasek masowy — ten sam wzorzec co na liscie M3R i w Produkcji. -->
                <div
                    v-if="selected.size"
                    class="mb-3 flex flex-wrap items-center gap-3 rounded-md border border-indigo-200 bg-indigo-50 px-4 py-2"
                >
                    <span class="text-sm text-indigo-900">
                        Zaznaczonych: <strong>{{ selected.size }}</strong>
                    </span>
                    <div class="ml-auto flex items-center gap-2">
                        <Button size="sm" :loading="bulkBusy" @click="excludeSelected">
                            Wyklucz zaznaczone
                        </Button>
                        <Button size="sm" color="gray" variant="ghost" @click="clearSelection">
                            Wyczyść
                        </Button>
                    </div>
                </div>

                <div class="mb-3 text-xs text-gray-500">
                    Widocznych: <strong>{{ visibleCount }}</strong>
                    / Kodów w arkuszu: <strong>{{ rows.length }}</strong>
                    <span v-if="excludedCount" class="ml-4">
                        Wykluczonych: <strong>{{ excludedCount }}</strong>
                        <Link
                            :href="route('crafter.production.warehouse')"
                            class="ml-1 text-primary-600 hover:underline"
                        >
                            przywróć w M3R →
                        </Link>
                    </span>
                    <span class="ml-4">Sztuk w arkuszu: <strong>{{ totalPieces }}</strong></span>
                    <span class="ml-4">
                        Zarezerwowane: <strong class="text-blue-700">{{ totalReserved }}</strong>
                    </span>
                    <span class="ml-4">
                        Dostępne: <strong>{{ totalPieces - totalReserved }}</strong>
                    </span>
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
                    :refreshAfterEdit="true"
                    @update:modelValue="onRowsChange"
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

        <!-- === REZERWACJE === -->
        <!-- Rezerwacja nie rusza stanu. Stan mowi, ile fizycznie lezy na polce,
             rezerwacja ile z tego jest juz komus obiecane — zlanie tych dwoch
             liczb w jedna konczy sie tym, ze nikt nie wie, czy towaru brakuje,
             czy tylko ktos go trzyma. -->
        <Modal :open="resModal.show" externalOpen size="md" alignButtons="right" @toggleOpen="closeRes">
            <template #title> Rezerwacje pozycji {{ resModal.source_code }} </template>

            <template #content>
                <p class="text-sm text-gray-500">
                    Rezerwacja odkłada sztuki dla konkretnej osoby. Stan w tabeli
                    zostaje bez zmian — pokazuje, ile leży na półce, nie ile jest wolne.
                </p>

                <div v-if="resModal.list.length" class="mt-4 space-y-2">
                    <div
                        v-for="item in resModal.list"
                        :key="item.id"
                        class="flex items-center justify-between rounded border border-gray-200 px-3 py-2"
                    >
                        <span class="text-sm text-gray-900">
                            {{ item.user_name ?? "nieznany" }}
                            <strong class="ml-1">{{ item.quantity }} szt.</strong>
                            <span v-if="item.note" class="ml-2 text-gray-400">{{ item.note }}</span>
                            <span
                                v-if="item.created_by_name && item.created_by_name !== item.user_name"
                                class="ml-2 text-gray-400"
                            >
                                dodał: {{ item.created_by_name }}
                            </span>
                        </span>
                        <button
                            type="button"
                            class="text-sm text-gray-400 hover:text-red-600"
                            :disabled="resModal.busy"
                            @click="releaseRes(item.id)"
                        >
                            Zwolnij
                        </button>
                    </div>
                </div>
                <div
                    v-else
                    class="mt-4 rounded border border-dashed border-gray-300 px-3 py-4 text-center text-sm text-gray-400"
                >
                    Nic nie jest zarezerwowane
                </div>

                <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <SelectInput
                        v-model="resModal.forUser"
                        name="reservation_for"
                        label="Rezerwujący"
                        :options="people"
                    />
                    <TextInput
                        v-model="resModal.quantity"
                        name="reservation_quantity"
                        label="Ilość"
                        type="number"
                        inputClass="bg-white"
                    />
                </div>

                <div class="mt-3 flex items-end gap-2">
                    <div class="flex-1">
                        <TextInput
                            v-model="resModal.note"
                            name="reservation_note"
                            label="Uwaga (opcjonalnie)"
                            placeholder="np. zamówienie 12345"
                            inputClass="bg-white"
                            @keyup.enter="saveRes"
                        />
                    </div>
                    <Button class="mb-1" :loading="resModal.busy" @click="saveRes">Zarezerwuj</Button>
                </div>

                <p class="mt-2 text-sm text-gray-500">
                    Jedną pozycję może trzymać kilka osób naraz — każda dostaje własny
                    wpis. Rezerwację wolno założyć komuś innemu; w dzienniku i tak
                    zostaje, kto ją wpisał.
                </p>
            </template>

            <template #buttons>
                <Button variant="outline" color="gray" @click="closeRes">Zamknij</Button>
            </template>
        </Modal>
    </PageContent>
</template>

<script setup lang="ts">
import { computed, reactive, ref, watch } from "vue";
import { Link, router } from "@inertiajs/vue3";
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
    /** Kod PIM, na który wiersz wchodzi: ręcznie wskazany albo dopasowany po kodzie. */
    mapped_to: string | null;
    /** true = dopasowane automatem po kodzie, nie ma czego odpinać. */
    mapped_auto: boolean;
    /** Do czego wiersz wróci po odpięciu ręcznego przypisania. */
    auto_to: string | null;
    /** Żywe rezerwacje tej pozycji — stan mówi ile leży, rezerwacja ile obiecane. */
    /** Auta, do których pasuje kod. Nie ma ich na ekranie — są dla wyszukiwarki. */
    names: string[];
    reservations: Reservation[];
    reserved: number;
}

interface Reservation {
    id: number;
    user_name: string | null;
    quantity: number;
    note: string | null;
    /** Kto wpisał rezerwację, jeśli to ktoś inny niż rezerwujący. */
    created_by_name: string | null;
    /** „Maciej Zając 1" — gotowa etykieta, ta sama co w aplikacji mobilnej. */
    label: string;
}

interface Props {
    sheet: string;
    importedAt: string | null;
    rows: SheetRow[];
    /** Klucz źródła w `warehouse_code_map` — dla tego ekranu zawsze „sheet". */
    source: string;
    /** Pola, które wolno poprawić ręcznie. Białą listę trzyma serwer. */
    editable: string[];
    /** Osoby, na które wolno zapisać rezerwację. */
    people: { value: number; label: string }[];
    /** Id zalogowanego — domyślny rezerwujący. */
    me: number | null;
    /** Ile wierszy arkusza jest odlozonych na bok — przywraca sie je w M3R. */
    excludedCount?: number;
}

const props = withDefaults(defineProps<Props>(), {
    rows: () => [],
    importedAt: null,
    source: "sheet",
    editable: () => [],
    people: () => [],
    me: null,
    excludedCount: 0,
});

const toast = useToast();

// Kopia lokalna — DataGrid pracuje na v-model, a props Inertii sa zamrozone.
const rows = ref<SheetRow[]>([...props.rows]);
const gridRef = ref<any>(null);

/**
 * Po przeladowaniu propsow (router.reload) kopia lokalna i grid musza pojsc
 * za serwerem. Bez tego ekran zostaje na starych danych i pokazuje stan,
 * ktorego w bazie juz nie ma — dokladnie tak wygladalo „nadpisywanie"
 * rezerwacji, ktore w bazie nigdy sie nie wydarzylo.
 */
watch(
    () => props.rows,
    (next) => {
        rows.value = [...next];
        takeSnapshot(next);
        gridRef.value?.setSource?.([...next]);
    }
);

const search = ref({ code: "", place: "", car: "", filter: "all" });

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

/* Rezerwacje licza sie z ZYWYCH wierszy, nie z propsow — licznik ma spadac
   od razu po odlozeniu sztuk, a nie dopiero po przeladowaniu ekranu. */
const totalReserved = computed(() =>
    rows.value.reduce((sum, row) => sum + Number(row.reserved || 0), 0)
);

/** Ile z pozycji zostaje po odjeciu rezerwacji — „ile moge wziac". */
const availableOf = (row: SheetRow): number =>
    Number(row?.quantity_total || 0) - Number(row?.reserved || 0);
// Liczone tym samym predykatem, ktorym filtruje grid — inaczej licznik potrafi
// pokazac co innego niz widac na ekranie.
const visibleCount = computed(() => rows.value.filter(filterFn).length);

/** Bez pary = ani kodu 1:1 w PIM, ani ręcznego mapowania. Liczone z żywych
 *  wierszy, nie z propsów, żeby licznik spadał od razu po zmapowaniu. */
const isUnmatched = (row: SheetRow): boolean => !row.mapped_to;
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

    // Wszystkie slowa frazy musza wystapic w tej samej nazwie, ale kolejnosc
    // nie ma znaczenia — „a4 audi" ma znalezc to samo co „audi a4".
    const words = search.value.car.trim().toLowerCase().split(/\s+/).filter(Boolean);
    if (words.length) {
        const names = Array.isArray(row.names) ? row.names : [];
        const hit = names.some((name) => {
            const haystack = name.toLowerCase();
            return words.every((word) => haystack.includes(word));
        });
        if (!hit) return false;
    }

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

const isEditable = (prop: string): boolean => props.editable.includes(prop);

const textColumn = (prop: string, name: string, size: number) => ({
    prop,
    name,
    readonly: !isEditable(prop),
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
    readonly: !isEditable(prop),
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

// === RECZNE POPRAWKI KOMOREK ===
// Grid oddaje po edycji cala tablice, nie „ta komorka sie zmienila", wiec
// trzymamy migawke ostatniego ZAPISANEGO stanu i liczymy roznice. Przy okazji
// zalatwia to wklejanie zakresu: jedno zdarzenie, jedna paczka do serwera.
const snapshot = new Map<number, Record<string, any>>();

function takeSnapshot(list: SheetRow[]): void {
    snapshot.clear();

    for (const row of list) {
        const cells: Record<string, any> = {};
        for (const key of props.editable) cells[key] = (row as any)[key];
        snapshot.set(row.id, cells);
    }
}

takeSnapshot(props.rows);

/** RevoGrid oddaje z edytora stringi, a z bazy przychodza liczby i nulle —
 *  bez tej normalizacji samo wejscie w komorke wygladaloby jak zmiana. */
const sameCell = (a: any, b: any): boolean =>
    (a === null || a === undefined ? "" : String(a).trim()) ===
    (b === null || b === undefined ? "" : String(b).trim());

function refreshGrid(): void {
    const grid = gridRef.value;
    if (grid?.getSource) grid.setSource([...grid.getSource()]);
}

async function onRowsChange(next: SheetRow[]): Promise<void> {
    const changes: { id: number; field: string; value: any }[] = [];

    for (const row of next) {
        const before = snapshot.get(row.id);
        if (!before) continue;

        for (const key of props.editable) {
            const value = (row as any)[key];
            if (!sameCell(before[key], value)) {
                changes.push({ id: row.id, field: key, value: value === "" ? null : value });
            }
        }
    }

    if (!changes.length) return;

    try {
        const { data } = await axios.post(route("crafter.production.warehouse.table.cells"), {
            changes,
        });

        // „Razem" liczy serwer — podmieniamy je z odpowiedzi, zamiast sumowac
        // drugi raz po swojemu.
        for (const updated of data?.rows ?? []) {
            const row = rows.value.find((r) => r.id === updated.id);
            if (row) row.quantity_total = updated.quantity_total;
        }

        takeSnapshot(rows.value);
        refreshGrid();
        toast.success(changes.length === 1 ? "Zapisano." : `Zapisano ${changes.length} komórek.`);
    } catch (error: any) {
        // Nieudany zapis cofamy do ostatniego stanu z bazy — ekran nie moze
        // pokazywac wartosci, ktorej na serwerze nie ma.
        for (const row of rows.value) {
            const before = snapshot.get(row.id);
            if (before) Object.assign(row, before);
        }

        refreshGrid();
        toast.error(error?.response?.data?.message ?? "Nie udało się zapisać zmiany.");
    }
}

// === REZERWACJE ===
const resModal = reactive({
    show: false,
    source_code: "",
    list: [] as Reservation[],
    quantity: "1",
    note: "",
    forUser: null as number | null,
    busy: false,
});

function openRes(row: SheetRow): void {
    resModal.show = true;
    resModal.source_code = row.product_code;
    resModal.list = [...(row.reservations ?? [])];
    resModal.quantity = "1";
    resModal.note = "";
    // Domyślnie rezerwuje się na siebie — wybranie kogoś innego ma być
    // świadomą decyzją, a nie skutkiem tego, że pole zostało po poprzedniku.
    resModal.forUser = props.me;
}

function closeRes(): void {
    resModal.show = false;
}

/**
 * Dociagniecie wierszy z serwera. Lokalna podmiana wiersza wystarcza w 99%
 * przypadkow, ale to serwer wie, ile rezerwacji naprawde wisi na kodzie —
 * po kazdej zmianie pytamy go wprost, zamiast ufac, ze grid sie przerysowal.
 */
function syncFromServer(): void {
    router.reload({ only: ["rows"], preserveScroll: true, preserveState: true });
}

/** Odpowiedz serwera niesie komplet rezerwacji kodu — wpisujemy ja w wiersz. */
function applyReservations(sourceCode: string, list: Reservation[]): void {
    const row = rows.value.find((r) => r.product_code === sourceCode);

    if (row) {
        row.reservations = list;
        row.reserved = list.reduce((sum, item) => sum + Number(item.quantity || 0), 0);
    }

    resModal.list = list;
    refreshGrid();
}

async function saveRes(): Promise<void> {
    const quantity = Number(String(resModal.quantity).replace(",", "."));

    if (!Number.isFinite(quantity) || quantity < 1) {
        toast.error("Ilość musi być liczbą większą od zera.");

        return;
    }

    resModal.busy = true;

    try {
        const { data } = await axios.post(route("crafter.production.warehouse.reservation.store"), {
            source_code: resModal.source_code,
            quantity: Math.round(quantity),
            note: resModal.note || null,
            for_user_id: resModal.forUser,
            area: "tabela",
        });

        applyReservations(resModal.source_code, data?.reservations ?? []);
        resModal.quantity = "1";
        resModal.note = "";
        toast.success("Zarezerwowano.");
        syncFromServer();
    } catch (error: any) {
        toast.error(error?.response?.data?.message ?? "Nie udało się zarezerwować.");
    } finally {
        resModal.busy = false;
    }
}

async function releaseRes(id: number): Promise<void> {
    resModal.busy = true;

    try {
        const { data } = await axios.delete(route("crafter.production.warehouse.reservation.release"), {
            data: { id, area: "tabela" },
        });

        applyReservations(resModal.source_code, data?.reservations ?? []);
        toast.success("Zwolniono rezerwację.");
        syncFromServer();
    } catch {
        toast.error("Nie udało się zwolnić rezerwacji.");
    } finally {
        resModal.busy = false;
    }
}

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
    // „Odepnij" dotyczy tylko przypisania recznego — automatu nie ma czego odpiac.
    mapModal.current = row.mapped_auto ? null : row.mapped_to;
    // Aktualne dopasowanie podpowiadamy jako punkt wyjscia; pole zostaje
    // edytowalne, bo mapowanie sluzy zwykle wskazaniu czegos INNEGO.
    mapModal.input = row.mapped_to ?? "";
}

function closeMap(): void {
    mapModal.show = false;
}

/** RevoGrid nie sledzi mutacji pol wiersza — podmiana zrodla wymusza przerysowanie. */
function applyMapping(sourceCode: string, productCode: string | null): void {
    const row = rows.value.find((r) => r.product_code === sourceCode);

    if (row) {
        if (productCode !== null) {
            row.mapped_to = productCode;
            row.mapped_auto = false;
        } else {
            // Po odpieciu wiersz wraca pod automat, jesli ten ma co dopasowac —
            // inaczej ekran pokazywalby „do zmapowania" tam, gdzie kod i tak
            // zgadza sie z PIM.
            row.mapped_to = row.auto_to;
            row.mapped_auto = row.auto_to !== null;
        }
    }

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
            area: "tabela",
        });

        applyMapping(mapModal.source_code, productCode);
        mapModal.current = productCode;
        syncFromServer();
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
                area: "tabela",
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

/**
 * Ilu rezerwujacych ma najbardziej obciazona pozycja. Z tego liczy sie
 * szerokosc kolumny — przy jednej osobie nie ma po co trzymac pustego pasa,
 * a przy trzech nazwiska nie moga sie chowac za „+2".
 */
const RESERVATION_CHIPS = 3;
const maxReservations = computed(() =>
    rows.value.reduce((max, row) => Math.max(max, row.reservations?.length ?? 0), 0)
);

const reservationSize = computed(() => {
    const shown = Math.min(Math.max(maxReservations.value, 1), RESERVATION_CHIPS);

    // 150 px na pierwsze nazwisko, po 120 na kazde kolejne; powyzej trzech
    // reszta i tak idzie w „+N", wiec kolumna przestaje rosnac.
    return 150 + (shown - 1) * 120 + (maxReservations.value > RESERVATION_CHIPS ? 50 : 0);
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
            // Nazwy aut sa schowane, ale musza byc jakś dostępne — najechanie
            // na kod pokazuje, co pod nim siedzi.
            const names: string[] = Array.isArray(p.model?.names) ? p.model.names : [];
            const title = names.length ? names.join("\n") : undefined;

            if (p.model?.mapped_to) return h("span", { title }, code);

            return h("span", { class: "revo-code-cell", title: title ?? "Brak pary w PIM" }, [
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

            // Reczne przypisanie — zielone, bo ktos podjal decyzje i da sie ja cofnac.
            if (row?.mapped_to && !row.mapped_auto) {
                return h(
                    "button",
                    {
                        class: "revo-map-chip revo-map-set",
                        title: `Przypisane ręcznie do ${row.mapped_to} — kliknij, żeby zmienić`,
                        onClick: open,
                    },
                    row.mapped_to
                );
            }

            // Dopasowanie po kodzie — pokazujemy ten sam kod PIM co lista M3R,
            // tylko szaro: nie ma czego odpinac, bo nie ma wpisu w bazie.
            if (row?.mapped_to) {
                return h(
                    "button",
                    {
                        class: "revo-map-chip revo-map-empty",
                        title: `Dopasowane automatycznie po kodzie do ${row.mapped_to} — kliknij, żeby wskazać inny`,
                        onClick: open,
                    },
                    row.mapped_to
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
    {
        // Rezerwacja stoi OBOK ilosci, nie zamiast niej — „Razem" mowi ile lezy,
        // ta kolumna ile z tego jest juz komus obiecane.
        prop: "reserved",
        name: "Rezerwacja",
        readonly: true,
        size: reservationSize.value,
        sortable: true,
        cellCompare: (key: string, a: any, b: any): number =>
            (Number(a?.[key]) || 0) - (Number(b?.[key]) || 0),
        cellTemplate: (h: any, p: any) => {
            const row = p.model as SheetRow;
            const list: Reservation[] = Array.isArray(row?.reservations) ? row.reservations : [];
            const open = (e: any) => {
                e.stopPropagation();
                openRes(row);
            };

            if (!list.length) {
                return h(
                    "button",
                    {
                        class: "revo-map-chip revo-map-empty",
                        title: "Odłóż sztuki z tej pozycji",
                        onClick: open,
                    },
                    "rezerwuj +"
                );
            }

            // Kazdy rezerwujacy dostaje wlasny chip — kolumna rozszerza sie sama
            // (patrz `reservationSize`). Dopiero czwarta i kolejne osoby chowaja
            // sie za „+N", zeby jeden skrajny wiersz nie rozpychal calej tabeli.
            const title = list.map((item) => item.label).join(", ");
            const shown = list.slice(0, RESERVATION_CHIPS);
            const hidden = list.length - shown.length;

            const chips = shown.map((item) =>
                h(
                    "button",
                    { class: "revo-map-chip revo-res-chip", title, onClick: open },
                    item.label
                )
            );

            if (hidden > 0) {
                chips.push(
                    h(
                        "button",
                        { class: "revo-map-chip revo-res-more", title, onClick: open },
                        `+${hidden}`
                    )
                );
            }

            return h("span", { class: "revo-code-cell revo-res-cell" }, chips);
        },
    },
    {
        // „Razem" zostaje suma Z ARKUSZA — ta kolumna mowi, ile z tego jest
        // jeszcze wolne. Dwie osobne liczby, bo zlanie ich w jedna zabiera
        // odpowiedz na pytanie, czy towaru brakuje, czy tylko ktos go trzyma.
        //
        // Lista M3R celowo tego NIE widzi: tam kolumna „Tabela" pokazuje pelny
        // stan z arkusza, bo rezerwacja nie zdejmuje niczego z polki.
        prop: "__available__",
        name: "Dostępne",
        readonly: true,
        size: 110,
        sortable: true,
        cellCompare: (key: string, a: any, b: any): number => availableOf(a) - availableOf(b),
        cellTemplate: (h: any, p: any) => {
            const row = p.model as SheetRow;
            const value = availableOf(row);
            const reserved = Number(row?.reserved || 0);

            // Ujemne = zarezerwowano wiecej, niz lezy. To nie jest blad ekranu,
            // tylko realny konflikt do rozwiazania — wiec ma krzyczec.
            const style = value < 0
                ? { color: "#b91c1c", fontWeight: "700" }
                : reserved > 0
                    ? { fontWeight: "600", color: "#1d4ed8" }
                    : { color: "#9ca3af" };

            return h(
                "span",
                {
                    style,
                    title: reserved > 0
                        ? `${row.quantity_total} szt. w arkuszu minus ${reserved} zarezerwowane`
                        : "Nic nie jest zarezerwowane",
                },
                String(value)
            );
        },
    },
    textColumn("steel_team", "steel team", 180),
    textColumn("uwagi", "Uwagi", 200),
    textColumn("wymiar", "WYMIAR", 120),
    textColumn("waga", "WAGA", 120),
    // Zaznaczanie na koncu wiersza — arkusz czyta sie od kodu i miejsc,
    // a wykluczanie jest czynnoscia okazjonalna.
    {
        prop: "__sel__",
        name: "Wyklucz",
        readonly: true,
        sortable: false,
        size: 90,
        cellTemplate: (h: any, p: any) => {
            const code = String(p.model?.product_code ?? "");

            return h("label", { class: "revo-sel-cell" }, [
                h("input", {
                    type: "checkbox",
                    checked: selected.value.has(code),
                    onClick: (e: any) => e.stopPropagation(),
                    onChange: (e: any) => toggleSelect(code, !!(e.target as HTMLInputElement).checked),
                }),
            ]);
        },
    },
]);

// === WYKLUCZANIE ===
// Wspolne z lista M3R: to ta sama tabela `warehouse_code_exclusions` i ten sam
// endpoint. Kod odlozony tutaj znika takze z „Do zmapowania" na liscie M3R,
// a przywraca sie go w jednym miejscu — Magazyn M3R → Wykluczone. Jedno miejsce
// powrotu, zeby nie szukac, gdzie co schowano.
const selected = ref<Set<string>>(new Set());
const bulkBusy = ref(false);

function clearSelection(): void {
    selected.value = new Set();
    refreshGridSource();
}

function toggleSelect(code: string, on: boolean): void {
    const next = new Set(selected.value);
    on ? next.add(code) : next.delete(code);
    selected.value = next;
    refreshGridSource();
}

/** RevoGrid nie sledzi mutacji — po zmianie zaznaczenia trzeba przerysowac. */
function refreshGridSource(): void {
    const grid = gridRef.value;
    if (grid?.getSource) grid.setSource([...grid.getSource()]);
}

async function excludeSelected(): Promise<void> {
    if (!selected.value.size || bulkBusy.value) return;

    bulkBusy.value = true;
    try {
        await axios.post(route("crafter.production.warehouse.exclusions.bulk"), {
            excluded: true,
            rows: Array.from(selected.value).map((code) => ({
                source: props.source,
                source_code: code,
            })),
        });

        const count = selected.value.size;
        clearSelection();
        toast.success(`Wykluczono ${count} pozycji`);
        router.reload({ only: ["rows", "excludedCount"] });
    } catch (e: any) {
        toast.error(e?.response?.data?.message ?? "Nie udało się wykluczyć");
    } finally {
        bulkBusy.value = false;
    }
}
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

:deep(.revo-res-cell) {
    gap: 4px;
    overflow: hidden;
}

:deep(.revo-res-more) {
    border: 1px solid #93c5fd;
    background: #dbeafe;
    color: #1e40af;
}

:deep(.revo-res-chip) {
    border: 1px solid #bfdbfe;
    background: #eff6ff;
    color: #1d4ed8;
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
:deep(.revo-sel-cell) {
    display: flex;
    align-items: center;
    justify-content: center;
    height: 100%;
    width: 100%;
    cursor: pointer;
}

</style>
