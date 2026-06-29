<template>
    <PageContent>
        <div class="w-full">
            <Card>
                <div class="space-y-4">
                    <TextInput
                        v-model="form.name"
                        name="name"
                        :label="$t('crafter', 'Name')"
                    />

                    <TextInput
                        v-model="form.currency"
                        name="currency"
                        :label="$t('crafter', 'Currency')"
                    />
                </div>

                <div v-if="pricelist?.id" class="mt-6">
                    <div class="mb-3 flex items-center justify-between gap-3">
                        <div class="text-xs text-gray-500">
                            Zaznaczonych: <strong>{{ selectionCount }}</strong>
                            / Widocznych: <strong>{{ visibleCount }}</strong>
                            / Wszystkich: <strong>{{ totalCount }}</strong>
                        </div>
                        <Modal size="lg">
                            <template #trigger="{ setIsOpen }">
                                <Button @click="onOpenBulk(setIsOpen)">
                                    {{ selectionCount > 0 ? `Operacje masowe · ${selectionCount} zazn.` : "Operacje masowe" }}
                                </Button>
                            </template>
                            <template #title>Operacje masowe</template>
                            <template #content>
                                <div class="space-y-6 mt-4 text-left">
                                    <section class="border rounded-md p-4 bg-blue-50/40">
                                        <h4 class="font-semibold text-gray-900 mb-1">
                                            Zakres operacji — na czym działać
                                        </h4>
                                        <p class="text-xs text-gray-500 mb-3">
                                            Operacje poniżej (poza przeliczaniem waluty)
                                            działają na wybranym zbiorze. Zaznacz produkty
                                            w gridzie (checkboxy), aby użyć „Zaznaczone".
                                        </p>
                                        <div class="flex flex-col gap-2 text-sm">
                                            <label class="inline-flex items-center gap-2 cursor-pointer">
                                                <input
                                                    type="radio"
                                                    value="selected"
                                                    v-model="scopeMode"
                                                    :disabled="countSelected === 0"
                                                />
                                                <span :class="countSelected === 0 ? 'text-gray-400' : ''">
                                                    Zaznaczone
                                                    <strong>({{ countSelected }})</strong>
                                                </span>
                                            </label>
                                            <label class="inline-flex items-center gap-2 cursor-pointer">
                                                <input
                                                    type="radio"
                                                    value="filtered"
                                                    v-model="scopeMode"
                                                />
                                                <span>
                                                    Widoczne po filtrze
                                                    <strong>({{ countFiltered }})</strong>
                                                </span>
                                            </label>
                                            <label class="inline-flex items-center gap-2 cursor-pointer">
                                                <input
                                                    type="radio"
                                                    value="all"
                                                    v-model="scopeMode"
                                                />
                                                <span>
                                                    Wszystkie
                                                    <strong>({{ countAll }})</strong>
                                                </span>
                                            </label>
                                        </div>
                                    </section>

                                    <section class="border rounded-md p-4 bg-gray-50">
                                        <h4 class="font-semibold text-gray-900 mb-1">
                                            Zakres: źródło produktów
                                        </h4>
                                        <p class="text-xs text-gray-500 mb-3">
                                            Filtr globalny — operacje masowe zadziałają
                                            tylko na produktach z wybranego źródła.
                                        </p>
                                        <SelectInput
                                            v-model="bulkSourceId"
                                            name="bulk_source"
                                            label="Źródło"
                                            :options="sourceOptions"
                                        />
                                    </section>

                                    <section class="border rounded-md p-4 bg-red-50/40">
                                        <h4 class="font-semibold text-gray-900 mb-1">
                                            Wylicz ceny z ceny zakupu
                                        </h4>
                                        <p class="text-xs text-gray-500 mb-3">
                                            Cena netto aut. =
                                            <strong>Cena zak EUR × mnożnik</strong>.
                                            Waluta inna niż EUR → wynik przeliczany
                                            z EUR kursem NBP. Zakres:
                                            <strong>{{ scopeLabel }}</strong>
                                            ({{ scopeCount }} produktów). Produkty
                                            bez ceny zakupu są pomijane.
                                        </p>
                                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 items-end">
                                            <SelectInput
                                                v-model="pricingCurrency"
                                                name="pricing_currency"
                                                label="Waluta docelowa"
                                                :options="['EUR', 'PLN', 'CZK']"
                                            />
                                            <SelectInput
                                                v-model="form.price_formula_mode"
                                                name="pricing_mode"
                                                label="Tryb"
                                                :options="[
                                                    { value: 'multiply', label: '× mnożnik / wzór' },
                                                    { value: 'percent', label: '% procent' },
                                                ]"
                                            />
                                            <TextInput
                                                v-model="form.price_formula"
                                                name="pricing_value"
                                                :label="form.price_formula_mode === 'percent' ? 'Mnożnik %' : 'Mnożnik / wzór'"
                                                :placeholder="form.price_formula_mode === 'percent' ? 'np. 250 (= ×2,5)' : 'np. 2,5 lub 2,5 * 1,2'"
                                            />
                                        </div>
                                        <div class="mt-3 flex items-center gap-2">
                                            <Button
                                                @click="applyPricing"
                                                :loading="loadingRates"
                                            >
                                                Zastosuj
                                            </Button>
                                            <span class="text-xs text-gray-500">
                                                Wzór i waluta zapiszą się przy
                                                <strong>Save</strong>.
                                            </span>
                                        </div>
                                        <p v-if="pricingInfo" class="mt-3 text-xs text-gray-600">
                                            {{ pricingInfo }}
                                        </p>
                                    </section>

                                    <section class="border rounded-md p-4">
                                        <h4 class="font-semibold text-gray-900 mb-1">
                                            Zmień cenę o procent
                                        </h4>
                                        <p class="text-xs text-gray-500 mb-3">
                                            Zakres: <strong>{{ scopeLabel }}</strong>
                                            ({{ scopeCount }} produktów).
                                            Zmienia kolumnę <strong>Cena netto aut.</strong>
                                            Wartość ujemna obniża.
                                        </p>
                                        <div class="flex items-end gap-2">
                                            <div class="flex-1">
                                                <TextInput
                                                    v-model="bulkPercent"
                                                    name="bulk_percent"
                                                    type="number"
                                                    step="0.1"
                                                    label="Zmiana w %"
                                                    placeholder="np. 10 lub -5"
                                                />
                                            </div>
                                            <Button @click="applyPercent">Zastosuj</Button>
                                        </div>
                                    </section>

                                    <section class="border rounded-md p-4">
                                        <h4 class="font-semibold text-gray-900 mb-1">
                                            Przelicz na walutę
                                        </h4>
                                        <p class="text-xs text-gray-500 mb-3">
                                            Kurs średni NBP z dnia poprzedniego. Operacja
                                            przelicza <strong>Cena netto aut.</strong> dla
                                            wszystkich {{ totalCount }} produktów ORAZ zmienia
                                            walutę cennika — wymaga zakresu
                                            <strong>Wszystkie źródła</strong>. Aktualna waluta:
                                            <strong>{{ form.currency || "?" }}</strong>.
                                        </p>
                                        <div class="flex items-end gap-2">
                                            <div class="flex-1">
                                                <SelectInput
                                                    v-model="bulkTargetCurrency"
                                                    name="bulk_currency"
                                                    label="Waluta docelowa"
                                                    :options="['EUR', 'PLN', 'CZK']"
                                                />
                                            </div>
                                            <Button
                                                @click="applyCurrencyConversion"
                                                :loading="loadingRates"
                                            >
                                                Przelicz
                                            </Button>
                                        </div>
                                        <p v-if="conversionInfo" class="mt-3 text-xs text-gray-600">
                                            {{ conversionInfo }}
                                        </p>
                                    </section>

                                    <section class="border rounded-md p-4 bg-green-50/40">
                                        <h4 class="font-semibold text-gray-900 mb-1">
                                            Przepisz na cenę właściwą
                                        </h4>
                                        <p class="text-xs text-gray-500 mb-3">
                                            Kopiuje <strong>Cena netto aut.</strong> →
                                            <strong>Cena sprzedaży netto</strong> w zakresie:
                                            <strong>{{ scopeLabel }}</strong>
                                            ({{ scopeCount }} produktów). Nadpisuje cenę
                                            właściwą wyliczoną wartością.
                                        </p>
                                        <Button @click="applyCopyToPrice">
                                            Przepisz auto → cena właściwa
                                        </Button>
                                    </section>

                                    <p class="text-xs text-gray-400">
                                        Po operacji kliknij <strong>Save</strong> w nagłówku.
                                    </p>
                                </div>
                            </template>
                            <template #buttons="{ setIsOpen }">
                                <Button @click="setIsOpen(false)">Zamknij</Button>
                            </template>
                        </Modal>
                    </div>

                    <div class="mb-3 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
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
                        <TextInput
                            v-model="search.priceFrom"
                            name="price_from"
                            type="number"
                            step="0.01"
                            label="Cena od"
                            placeholder="0"
                            clearable
                        />
                        <TextInput
                            v-model="search.priceTo"
                            name="price_to"
                            type="number"
                            step="0.01"
                            label="Cena do"
                            placeholder="9999"
                            clearable
                        />
                    </div>

                    <DataGrid
                        ref="gridRef"
                        v-model="form.rows"
                        :columns="columns"
                        :filter="filterFn"
                        keyField="product_id"
                        selectable
                        height="auto"
                        :refreshAfterEdit="true"
                        @update:selection="onSelectionChange"
                    />
                </div>
            </Card>
        </div>
    </PageContent>
</template>

<script setup lang="ts">
import { reactive, ref, computed } from "vue";
import axios from "axios";
import { useToast } from "@brackets/vue-toastification";
import {
    Card,
    TextInput,
    PageContent,
    DataGrid,
    Button,
    Modal,
    SelectInput,
} from "crafter/Components";
import { InertiaForm } from "crafter/types";
import type { Pricelist, PricelistForm, SourceOption } from "./types";

interface Props {
    form: InertiaForm<PricelistForm>;
    pricelist: Pricelist;
    sources?: SourceOption[];
    submit: void;
}

const props = defineProps<Props>();
const toast = useToast();

const numericCompare = (prop: string, a: any, b: any): number => {
    const av = parseFloat(a?.[prop] ?? 0) || 0;
    const bv = parseFloat(b?.[prop] ?? 0) || 0;
    return av - bv;
};

// Marza handlowa = zysk / cena sprzedazy * 100 (nie narzut). Cena zakupu z cennika bazowego (EUR).
const toNum = (v: any): number => parseFloat(v ?? 0) || 0;

// Cena eksportowa (do Zysk/Marża): ręczna (manual_price) jeśli > 0, inaczej cena właściwa (price).
const effPrice = (m: any): number => {
    const manual = toNum(m?.manual_price);
    return manual > 0 ? manual : toNum(m?.price);
};

const columns = [
    { prop: "product_code", name: "Kod", readonly: true, size: 150, sortable: true },
    { prop: "name", name: "Nazwa", readonly: true, size: 340, sortable: true },
    {
        prop: "price",
        name: "Cena sprzedaży netto",
        size: 180,
        sortable: true,
        cellCompare: numericCompare,
    },
    {
        // Cena netto "automatyczna" — wynik Operacji masowych. Edytowalna (można dotknąć
        // pojedynczą komórkę przed przepisaniem). Cena właściwa (price) zostaje nietknięta.
        prop: "auto_price",
        name: "Cena netto aut.",
        size: 160,
        sortable: true,
        cellCompare: numericCompare,
    },
    {
        // Cena ręczna — twardy override. Gdy > 0 to ONA jest ceną eksportową (nadpisuje price
        // wszędzie: sync, BaseLinker, Prestashop/Selly, CSV). Pusta/0 = eksport bierze "Cena sprzedaży netto".
        prop: "manual_price",
        name: "Cena ręczna",
        size: 150,
        sortable: true,
        cellCompare: numericCompare,
    },
    {
        prop: "purchase_price",
        name: "Cena zak EUR",
        readonly: true,
        size: 130,
        sortable: true,
        cellCompare: numericCompare,
    },
    {
        prop: "profit",
        name: "Zysk",
        readonly: true,
        sortable: false,
        size: 120,
        cellTemplate: (h: any, p: any) => {
            const profit = effPrice(p.model) - toNum(p.model?.purchase_price);
            return h(
                "span",
                profit < 0 ? { style: { color: "#b91c1c" } } : {},
                profit.toFixed(2)
            );
        },
    },
    {
        prop: "margin",
        name: "Marża",
        readonly: true,
        sortable: false,
        size: 110,
        cellTemplate: (h: any, p: any) => {
            const sale = effPrice(p.model);
            if (sale <= 0) return h("span", { style: { color: "#9ca3af" } }, "—");
            const margin = ((sale - toNum(p.model?.purchase_price)) / sale) * 100;
            return h(
                "span",
                margin < 0 ? { style: { color: "#b91c1c" } } : {},
                margin.toFixed(1) + "%"
            );
        },
    },
];

// === WYSZUKIWARKA ===
const search = reactive({
    code: "",
    name: "",
    priceFrom: "" as string | number,
    priceTo: "" as string | number,
});

const filterFn = computed<((row: any) => boolean) | null>(() => {
    const code = String(search.code ?? "").trim().toLowerCase();
    const name = String(search.name ?? "").trim().toLowerCase();
    const from = parseFloat(String(search.priceFrom ?? ""));
    const to = parseFloat(String(search.priceTo ?? ""));
    const hasFrom = !isNaN(from);
    const hasTo = !isNaN(to);

    if (!code && !name && !hasFrom && !hasTo) return null;

    return (row: any) => {
        if (code && !String(row.product_code ?? "").toLowerCase().includes(code)) {
            return false;
        }
        if (name && !String(row.name ?? "").toLowerCase().includes(name)) {
            return false;
        }
        if (hasFrom || hasTo) {
            const price = parseFloat(String(row.price ?? 0)) || 0;
            if (hasFrom && price < from) return false;
            if (hasTo && price > to) return false;
        }
        return true;
    };
});

// === SELEKCJA + LICZNIKI ===
const gridRef = ref<any>(null);
const selectionCount = ref<number>(0);

function onSelectionChange(ids: any[]): void {
    selectionCount.value = ids.length;
}

const visibleCount = computed<number>(() => {
    const src = gridRef.value?.getSource?.() ?? [];
    const f = filterFn.value;
    return f ? src.filter(f).length : src.length;
});
const totalCount = computed<number>(() => gridRef.value?.getSource?.()?.length ?? 0);

// === FILTR ŹRÓDŁA (dla operacji masowych) ===
// "all" -> wszystkie źródła; inaczej id źródła z props.sources.
const bulkSourceId = ref<string | number>("all");

const sourceOptions = computed<Array<{ value: string | number; label: string }>>(() => {
    const list = (props.sources ?? []).map((s) => ({
        value: s.id,
        label: s.name,
    }));
    return [{ value: "all", label: "Wszystkie źródła" }, ...list];
});

const selectedSourceId = computed<number | null>(() => {
    const v = bulkSourceId.value;
    if (v === "all" || v === "" || v === null || v === undefined) return null;
    const n = Number(v);
    return Number.isFinite(n) ? n : null;
});

const selectedSourceName = computed<string | null>(() => {
    const id = selectedSourceId.value;
    if (id === null) return null;
    return (props.sources ?? []).find((s) => Number(s.id) === id)?.name ?? null;
});

function matchesSource(row: any): boolean {
    const id = selectedSourceId.value;
    if (id === null) return true;
    return Number(row?.source_id) === id;
}

// === ZAKRES OPERACJI MASOWYCH ===
// Jawnie wybierany w modalu (radio). Przy otwarciu ustawiany inteligentnie:
// zaznaczone > filtr > wszystkie. Użytkownik może nadpisać.
type ScopeMode = "selected" | "filtered" | "all";
const scopeMode = ref<ScopeMode>("all");

// Wiersze bazowe dla trybu (PRZED przecięciem ze źródłem).
function baseRowsForMode(mode: ScopeMode): any[] {
    const grid = gridRef.value;
    if (!grid) return [];
    const current: any[] = grid.getSource?.() ?? [];
    if (mode === "selected") {
        const sel = new Set(grid.getSelection?.() ?? []);
        return current.filter((r) => sel.has(r.product_id));
    }
    if (mode === "filtered") {
        const f = filterFn.value;
        return f ? current.filter(f) : current;
    }
    return current;
}

// Liczniki per tryb (∩ źródło) — pokazywane przy radio w modalu.
const countSelected = computed<number>(() => {
    void selectionCount.value;
    void bulkSourceId.value;
    return baseRowsForMode("selected").filter(matchesSource).length;
});
const countFiltered = computed<number>(() => {
    void filterFn.value;
    void bulkSourceId.value;
    return baseRowsForMode("filtered").filter(matchesSource).length;
});
const countAll = computed<number>(() => {
    void bulkSourceId.value;
    return baseRowsForMode("all").filter(matchesSource).length;
});

const scopeLabel = computed<string>(() => {
    const base =
        scopeMode.value === "selected"
            ? "zaznaczone"
            : scopeMode.value === "filtered"
              ? "widoczne (po filtrze)"
              : "wszystkie";
    return selectedSourceName.value
        ? `${base} ∩ źródło: ${selectedSourceName.value}`
        : base;
});

const scopeCount = computed<number>(() => {
    // Reactive deps
    void selectionCount.value;
    void filterFn.value;
    void bulkSourceId.value;
    void scopeMode.value;
    return baseRowsForMode(scopeMode.value).filter(matchesSource).length;
});

// === OPERACJE MASOWE ===
const bulkPercent = ref<string | number>("");
const bulkTargetCurrency = ref<string>("EUR");
const loadingRates = ref(false);
const conversionInfo = ref<string | null>(null);

// === WYLICZANIE CEN Z CENY ZAKUPU (mnoznik + waluta) ===
const pricingCurrency = ref<string>(
    String(props.form.currency ?? "EUR").toUpperCase() || "EUR"
);
const pricingInfo = ref<string | null>(null);

function round2(n: number): number {
    return Math.round(n * 100) / 100;
}

function round4(n: number): number {
    return Math.round(n * 10000) / 10000;
}

// Wyciaga mnoznik z pola wzoru. Tryb 'percent': pierwsza liczba/100 (250 -> 2,5).
// Tryb 'multiply': iloczyn wszystkich liczb (np. "2,5 * 1,2" -> 3).
function parseMultiplier(): number | null {
    const raw = String(props.form.price_formula ?? "");
    const nums = raw.match(/\d+(?:[.,]\d+)?/g);
    if (!nums || !nums.length) return null;
    const factors = nums
        .map((n) => parseFloat(n.replace(",", ".")))
        .filter((n) => !isNaN(n));
    if (!factors.length) return null;
    if (props.form.price_formula_mode === "percent") {
        return factors[0] / 100;
    }
    return factors.reduce((a, b) => a * b, 1);
}

async function applyPricing(): Promise<void> {
    const grid = gridRef.value;
    if (!grid) return;

    const mult = parseMultiplier();
    if (mult === null || mult <= 0) {
        toast.error("Podaj poprawny mnożnik (np. 2,5) lub procent (np. 250)");
        return;
    }

    const target = String(pricingCurrency.value ?? "").toUpperCase();
    if (!target) {
        toast.error("Wybierz walutę docelową");
        return;
    }

    const ids = scopedIds();
    if (ids.size === 0) {
        toast.error("Pusty zakres operacji");
        return;
    }

    // Kurs EUR -> waluta (PLN jako most, jak w przeliczaniu walut). EUR -> brak przeliczenia.
    let fx = 1;
    let rateDate: string | null = null;
    if (target !== "EUR") {
        loadingRates.value = true;
        try {
            const codes = Array.from(new Set(["EUR", target, "PLN"])).join(",");
            const { data } = await axios.get(route("crafter.exchange-rates.nbp"), {
                params: { codes },
            });
            const rates: Record<string, { rate: number | null; date: string | null } | null> =
                data?.rates ?? {};
            const rEur = rates["EUR"]?.rate;
            const rTarget = rates[target]?.rate;
            if (!rEur || !rTarget) {
                toast.error(`Brak kursu NBP dla ${!rEur ? "EUR" : target}`);
                return;
            }
            fx = rEur / rTarget;
            rateDate = rates[target]?.date || rates["EUR"]?.date || null;
        } catch (e) {
            toast.error("Błąd pobierania kursu NBP");
            return;
        } finally {
            loadingRates.value = false;
        }
    }

    // cena sprzedazy = cena zak EUR * mnoznik * kurs. Pomijamy produkty bez ceny zakupu.
    const current: any[] = grid.getSource();
    let updated = 0;
    let skipped = 0;
    const next = current.map((row) => {
        if (!ids.has(row.product_id)) return row;
        const buy = parseFloat(String(row.purchase_price ?? 0)) || 0;
        if (buy <= 0) {
            skipped++;
            return row;
        }
        updated++;
        return { ...row, auto_price: round2(buy * mult * fx) };
    });
    grid.setSource(next);
    props.form.currency = target;

    const fxNote = target !== "EUR" ? ` × kurs ${round4(fx)} (NBP ${rateDate})` : "";
    pricingInfo.value =
        `Wyliczono ${updated} cen = Cena zak EUR × ${round2(mult)}${fxNote}. Waluta: ${target}.` +
        (skipped ? ` Pominięto ${skipped} bez ceny zakupu.` : "");
    toast.success(`Wyliczono ${updated} → Cena netto aut. (${target})`);
}

function onOpenBulk(setIsOpen: (v: boolean) => void): void {
    conversionInfo.value = null;
    // Inteligentny default zakresu: zaznaczone > filtr > wszystkie. Użytkownik może zmienić w modalu.
    if (selectionCount.value > 0) scopeMode.value = "selected";
    else if (filterFn.value) scopeMode.value = "filtered";
    else scopeMode.value = "all";
    setIsOpen(true);
}

// Zwraca Set product_id dla WYBRANEGO trybu zakresu ∩ źródło.
function scopedIds(): Set<any> {
    return new Set(
        baseRowsForMode(scopeMode.value)
            .filter(matchesSource)
            .map((r) => r.product_id)
    );
}

function applyPercent(): void {
    const pct = parseFloat(String(bulkPercent.value ?? ""));
    if (isNaN(pct) || pct === 0) {
        toast.error("Podaj wartość procentową różną od zera");
        return;
    }
    const grid = gridRef.value;
    if (!grid) return;

    const ids = scopedIds();
    if (ids.size === 0) {
        toast.error("Pusty zakres operacji");
        return;
    }

    const factor = 1 + pct / 100;
    const current: any[] = grid.getSource();
    const updated = current.map((row) => {
        if (!ids.has(row.product_id)) return row;
        const newPrice = round2((parseFloat(String(row.auto_price ?? 0)) || 0) * factor);
        return { ...row, auto_price: newPrice };
    });
    grid.setSource(updated);
    toast.success(`Cena netto aut.: ${ids.size} × (${pct > 0 ? "+" : ""}${pct}%)`);
}

async function applyCurrencyConversion(): Promise<void> {
    const grid = gridRef.value;
    if (!grid) return;

    const target = String(bulkTargetCurrency.value ?? "").toUpperCase();
    const source = String(props.form.currency ?? "").toUpperCase();

    if (!target) {
        toast.error("Wybierz walutę docelową");
        return;
    }
    if (!source) {
        toast.error("Cennik nie ma ustawionej waluty źródłowej");
        return;
    }
    if (target === source) {
        toast.error("Waluta docelowa jest taka sama jak źródłowa");
        return;
    }
    // Konwersja waluty zmienia walutę całego cennika - nie ma sensu przy filtrze źródła.
    if (selectedSourceId.value !== null) {
        toast.error(
            "Przeliczanie waluty wymaga zakresu 'Wszystkie źródła' (zmienia walutę cennika)"
        );
        return;
    }

    loadingRates.value = true;
    try {
        const codes = Array.from(new Set([source, target, "PLN"])).join(",");
        const { data } = await axios.get(route("crafter.exchange-rates.nbp"), {
            params: { codes },
        });
        const rates: Record<string, { rate: number | null; date: string | null } | null> =
            data?.rates ?? {};

        const rSource = rates[source]?.rate;
        const rTarget = rates[target]?.rate;
        if (!rSource || !rTarget) {
            toast.error(`Brak kursu NBP dla ${!rSource ? source : target}`);
            return;
        }

        // PLN jako most: price_PLN = price_source * rSource; price_target = price_PLN / rTarget
        const current: any[] = grid.getSource();
        const updated = current.map((row) => {
            const p = parseFloat(String(row.auto_price ?? 0)) || 0;
            const newPrice = round2((p * rSource) / rTarget);
            return { ...row, auto_price: newPrice };
        });
        grid.setSource(updated);
        props.form.currency = target;

        const date = rates[target]?.date || rates[source]?.date;
        const direct = (rSource / rTarget).toFixed(4);
        conversionInfo.value = `Kurs NBP z ${date}: 1 ${source} = ${direct} ${target}. Zaktualizowano ${current.length} produktów. Cennik przełączony na ${target}.`;
        toast.success(`Przeliczono na ${target}`);
    } catch (e) {
        toast.error("Błąd pobierania kursu NBP");
    } finally {
        loadingRates.value = false;
    }
}

// Przepisuje "Cena netto aut." -> "Cena sprzedazy netto" (cena wlasciwa) w zakresie.
// Jedyne miejsce, gdzie operacja masowa CELOWO nadpisuje cene wlasciwa — po akceptacji uzytkownika.
function applyCopyToPrice(): void {
    const grid = gridRef.value;
    if (!grid) return;

    const ids = scopedIds();
    if (ids.size === 0) {
        toast.error("Pusty zakres operacji");
        return;
    }

    const current: any[] = grid.getSource();
    let updated = 0;
    const next = current.map((row) => {
        if (!ids.has(row.product_id)) return row;
        updated++;
        return { ...row, price: round2(parseFloat(String(row.auto_price ?? 0)) || 0) };
    });
    grid.setSource(next);
    toast.success(`Przepisano ${updated}: Cena netto aut. → Cena sprzedaży netto`);
}
</script>
