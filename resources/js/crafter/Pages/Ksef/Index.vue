<template>
    <PageHeader :title="`KSeF — ${companyLabel}`">
        <Button v-if="tab === 'faktury'" :leftIcon="ArrowDownTrayIcon" @click.prevent="importOpen = true">
            Import faktur
        </Button>
    </PageHeader>

    <PageContent fluid>
        <!-- Zakładki -->
        <div class="mb-5 border-b border-gray-200">
            <nav class="-mb-px flex gap-6">
                <button
                    type="button"
                    @click="tab = 'faktury'"
                    :class="tab === 'faktury'
                        ? 'border-b-2 border-primary-500 px-1 py-3 text-sm font-medium text-primary-600'
                        : 'border-b-2 border-transparent px-1 py-3 text-sm font-medium text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                >
                    Faktury
                </button>
                <button
                    type="button"
                    @click="tab = 'ustawienia'"
                    :class="tab === 'ustawienia'
                        ? 'border-b-2 border-primary-500 px-1 py-3 text-sm font-medium text-primary-600'
                        : 'border-b-2 border-transparent px-1 py-3 text-sm font-medium text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                >
                    Ustawienia
                </button>
            </nav>
        </div>

        <!-- ════════ TAB: FAKTURY ════════ -->
        <div v-show="tab === 'faktury'">
            <!-- Podsumowanie -->
            <div class="mb-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-gray-600">
                <span><span class="font-semibold text-gray-900">{{ liveSummary.count }}</span> poz.</span>
                <span>·</span>
                <span>razem <span class="font-semibold text-gray-900">{{ formatAmount(liveSummary.sum) }}</span></span>
                <span>·</span>
                <span class="text-red-600">do zapłaty {{ formatAmount(liveSummary.sum_unpaid) }}</span>
            </div>

            <!-- Filtry -->
            <div class="mb-4 flex flex-wrap items-end gap-3">
                <div>
                    <label class="block text-xs font-medium uppercase tracking-wide text-gray-500 mb-1">Miesiąc</label>
                    <select v-model="local.month" @change="reload" class="block rounded-md border-gray-300 text-sm focus:border-primary-500 focus:ring-primary-500">
                        <option value="all">Wszystkie</option>
                        <option v-for="(m, i) in MONTHS" :key="i" :value="i + 1">{{ m }}</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-medium uppercase tracking-wide text-gray-500 mb-1">Kwartał</label>
                    <select v-model="local.quarter" @change="reload" class="block rounded-md border-gray-300 text-sm focus:border-primary-500 focus:ring-primary-500">
                        <option value="all">Wszystkie</option>
                        <option v-for="q in 4" :key="q" :value="q">Q{{ q }}</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium uppercase tracking-wide text-gray-500 mb-1">Rok</label>
                    <select v-model="local.year" @change="reload" class="block rounded-md border-gray-300 text-sm focus:border-primary-500 focus:ring-primary-500">
                        <option value="all">Wszystkie</option>
                        <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
                    </select>
                </div>

                <!-- Status: przełączane przyciski -->
                <div>
                    <label class="block text-xs font-medium uppercase tracking-wide text-gray-500 mb-1">Status</label>
                    <div class="inline-flex rounded-lg bg-gray-100 p-1">
                        <button
                            v-for="opt in STATUS_FILTERS"
                            :key="opt.key"
                            type="button"
                            @click="setStatus(opt.key)"
                            :class="[
                                'rounded-md px-3 py-1.5 text-sm font-medium transition-colors',
                                local.status === opt.key ? 'bg-white text-primary-700 shadow-sm' : 'text-gray-500 hover:text-gray-700',
                            ]"
                        >
                            {{ opt.label }}
                        </button>
                    </div>
                </div>

                <!-- X — wyczyść filtrowanie -->
                <button
                    v-if="hasActiveFilters"
                    type="button"
                    @click="clearFilters"
                    class="inline-flex items-center gap-1 rounded-md border border-gray-300 px-3 py-2 text-sm text-gray-600 hover:bg-gray-50"
                    title="Wyczyść filtrowanie"
                >
                    <XMarkIcon class="w-4 h-4" /> Wyczyść
                </button>
            </div>

            <!-- Tabela FV -->
            <div class="bg-white rounded-lg shadow overflow-x-auto">
                <table class="min-w-full text-sm border-collapse">
                    <thead>
                        <tr class="bg-gray-50 text-gray-600 text-xs uppercase tracking-wide border-b border-gray-200">
                            <th class="px-3 py-2 text-left">Data</th>
                            <th class="px-3 py-2 text-left">Kategoria</th>
                            <th class="px-3 py-2 text-left">Nr FV</th>
                            <th class="px-3 py-2 text-left">Kontrahent</th>
                            <th class="px-3 py-2 text-left">Pozycja FV</th>
                            <th class="px-3 py-2 text-left">Termin</th>
                            <th class="px-3 py-2 text-center">Dni</th>
                            <th class="px-3 py-2 text-right">Kwota</th>
                            <th class="px-3 py-2 text-center">Opłacone</th>
                            <th class="px-3 py-2 text-center w-16">PDF</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in rows" :key="row.id" class="border-b hover:bg-gray-50">
                            <td class="px-3 py-1.5 whitespace-nowrap">{{ formatDate(row.issue_date) }}</td>

                            <td class="px-2 py-1">
                                <input
                                    v-model="row.category"
                                    @change="saveCategory(row)"
                                    :list="'ksef-cats-' + company"
                                    placeholder="—"
                                    class="w-32 bg-transparent px-1 py-0.5 text-sm focus:outline-none focus:bg-white focus:ring-1 focus:ring-blue-400 rounded"
                                />
                            </td>

                            <td class="px-3 py-1.5 whitespace-nowrap font-medium text-gray-800">{{ row.number }}</td>
                            <td class="px-3 py-1.5 max-w-[16rem] truncate" :title="row.contractor || ''">{{ row.contractor }}</td>

                            <td class="px-3 py-1.5">
                                <div class="group relative max-w-[18rem]">
                                    <span class="block truncate text-gray-700">{{ row.items_text || '—' }}</span>
                                    <div
                                        v-if="row.items_text"
                                        class="pointer-events-none absolute left-0 top-full z-20 mt-1 hidden w-80 rounded-md border border-gray-200 bg-white p-3 text-xs text-gray-700 shadow-lg group-hover:block"
                                    >
                                        {{ row.items_text }}
                                    </div>
                                </div>
                            </td>

                            <td class="px-3 py-1.5 whitespace-nowrap">{{ formatDate(row.due_date) }}</td>

                            <td class="px-2 py-1.5 text-center">
                                <span v-if="row.status === 'paid'" class="inline-block text-xs font-semibold px-2 py-0.5 rounded bg-green-100 text-green-800">
                                    Zapłacone
                                </span>
                                <span v-else-if="daysInfo(row)" :class="daysPillClass(row)" class="inline-block text-xs font-semibold px-2 py-0.5 rounded">
                                    {{ daysInfo(row) }}
                                </span>
                                <span v-else class="text-gray-300 text-xs">—</span>
                            </td>

                            <td class="px-3 py-1.5 text-right whitespace-nowrap font-medium">
                                {{ formatAmount(row.amount) }} <span class="text-xs text-gray-400">{{ row.currency }}</span>
                            </td>

                            <!-- Opłacone (zaznaczenie) -->
                            <td class="px-3 py-1.5 text-center">
                                <input
                                    type="checkbox"
                                    :checked="row.status === 'paid'"
                                    @change="togglePaid(row, ($event.target as HTMLInputElement).checked)"
                                    class="h-4 w-4 rounded border-gray-300 text-primary-600 focus:ring-primary-500 cursor-pointer"
                                    title="Oznacz jako opłaconą / nieopłaconą"
                                />
                            </td>

                            <td class="px-2 py-1.5 text-center">
                                <a
                                    :href="route('crafter.ksef.invoices.pdf', row.id)"
                                    target="_blank"
                                    rel="noopener"
                                    class="inline-flex items-center justify-center rounded p-1 text-red-600 hover:bg-red-50"
                                    title="Otwórz PDF"
                                >
                                    <DocumentTextIcon class="w-5 h-5" />
                                </a>
                            </td>
                        </tr>

                        <tr v-if="rows.length === 0">
                            <td colspan="10" class="px-3 py-10 text-center text-sm text-gray-400">
                                Brak faktur dla wybranych filtrów. Kliknij „Import faktur", aby zaciągnąć FV z KSeF.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <datalist :id="'ksef-cats-' + company">
                <option v-for="c in cats" :key="c.id" :value="c.name" />
            </datalist>
        </div>

        <!-- ════════ TAB: USTAWIENIA ════════ -->
        <div v-show="tab === 'ustawienia'" class="max-w-2xl">
            <Card>
                <CardHeader>
                    <h2 class="text-lg font-semibold">Kategorie</h2>
                    <p class="text-sm text-gray-500">Kategorie używane przy fakturach firmy {{ companyLabel }} (edytowalne na liście FV).</p>
                </CardHeader>
                <CardContent>
                    <div class="space-y-2">
                        <div v-for="c in cats" :key="c.id" class="flex items-center gap-2">
                            <input
                                v-model="c.name"
                                @change="renameCategory(c)"
                                class="flex-1 rounded-md border-gray-300 text-sm focus:border-primary-500 focus:ring-primary-500"
                            />
                            <button
                                type="button"
                                @click="removeCategory(c)"
                                class="rounded p-1.5 text-red-500 hover:bg-red-50 hover:text-red-700"
                                title="Usuń kategorię"
                            >
                                <TrashIcon class="w-4 h-4" />
                            </button>
                        </div>
                        <p v-if="cats.length === 0" class="text-sm text-gray-400 py-2">Brak kategorii. Dodaj pierwszą poniżej.</p>
                    </div>

                    <div class="mt-4 flex items-center gap-2 border-t border-gray-100 pt-4">
                        <input
                            v-model="newCategory"
                            @keyup.enter="addCategory"
                            placeholder="Nazwa nowej kategorii…"
                            class="flex-1 rounded-md border-gray-300 text-sm focus:border-primary-500 focus:ring-primary-500"
                        />
                        <Button :leftIcon="PlusIcon" :disabled="!newCategory.trim()" @click.prevent="addCategory">
                            Dodaj
                        </Button>
                    </div>
                </CardContent>
            </Card>
        </div>
    </PageContent>

    <!-- Modal: Import faktur -->
    <Modal :open="importOpen" externalOpen @toggleOpen="importOpen = false">
        <template #title>Import faktur z KSeF — {{ companyLabel }}</template>
        <template #content>
            <div class="space-y-4">
                <p class="rounded-md bg-blue-50 border border-blue-200 px-3 py-2 text-xs text-blue-800">
                    Zaciąga REALNE faktury z KSeF (po dacie wystawienia). Zakres &gt; 3 mies. dzielony jest
                    automatycznie. Status „opłacone" i kategorie prowadzisz u siebie — ponowny import ich nie nadpisuje.
                </p>

                <div class="grid grid-cols-3 gap-3">
                    <div>
                        <label class="block text-xs font-medium uppercase tracking-wide text-gray-500 mb-1">Miesiąc</label>
                        <select v-model="importFilter.month" class="block w-full rounded-md border-gray-300 text-sm">
                            <option value="all">Wszystkie</option>
                            <option v-for="(m, i) in MONTHS" :key="i" :value="i + 1">{{ m }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium uppercase tracking-wide text-gray-500 mb-1">Kwartał</label>
                        <select v-model="importFilter.quarter" class="block w-full rounded-md border-gray-300 text-sm">
                            <option value="all">Wszystkie</option>
                            <option v-for="q in 4" :key="q" :value="q">Q{{ q }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium uppercase tracking-wide text-gray-500 mb-1">Rok</label>
                        <select v-model="importFilter.year" class="block w-full rounded-md border-gray-300 text-sm">
                            <option value="all">Wszystkie</option>
                            <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-medium uppercase tracking-wide text-gray-500 mb-1">Pokaż</label>
                    <div class="inline-flex rounded-lg bg-gray-100 p-1">
                        <button
                            v-for="opt in IMPORT_VIEWS"
                            :key="opt.key"
                            type="button"
                            @click="importView = opt.key"
                            :class="[
                                'rounded-md px-3 py-1.5 text-sm font-medium transition-colors',
                                importView === opt.key ? 'bg-white text-primary-700 shadow-sm' : 'text-gray-500 hover:text-gray-700',
                            ]"
                        >
                            {{ opt.label }}
                        </button>
                    </div>
                </div>

                <div class="rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-600">
                    Zaimportowane dotąd: <span class="font-semibold text-gray-900">{{ importMeta.imported }}</span> faktur.
                </div>
            </div>
        </template>
        <template #buttons="{ setIsOpen }">
            <Button :leftIcon="ArrowDownTrayIcon" :loading="importing" @click.prevent="pullAll">
                Zaciągnij wszystko
            </Button>
            <Button color="gray" variant="outline" @click.prevent="() => { setIsOpen(false); importOpen = false; }">
                Zamknij
            </Button>
        </template>
    </Modal>
</template>

<script setup lang="ts">
import { computed, reactive, ref, watch } from "vue";
import { router } from "@inertiajs/vue3";
import axios from "axios";
import { ArrowDownTrayIcon, DocumentTextIcon, XMarkIcon, TrashIcon, PlusIcon } from "@heroicons/vue/24/outline";
import { useToast } from "@brackets/vue-toastification";
import { PageHeader, PageContent, Button, Modal, Card, CardHeader, CardContent } from "crafter/Components";

interface Invoice {
    id: number;
    issue_date: string | null;
    number: string;
    contractor: string | null;
    items_text: string | null;
    category: string | null;
    due_date: string | null;
    amount: number;
    currency: string;
    status: string;
    has_pdf: boolean;
}

interface Category { id: number; name: string; }

interface Props {
    company: string;
    companyLabel: string;
    invoices: Invoice[];
    filters: { year: string | number; month: string | number; quarter: string | number; status: string };
    years: number[];
    categories: Category[];
    summary: { count: number; sum: number; sum_unpaid: number };
    importMeta: { imported: number };
}

const props = defineProps<Props>();
const toast = useToast();

const MONTHS = ["Styczeń", "Luty", "Marzec", "Kwiecień", "Maj", "Czerwiec", "Lipiec", "Sierpień", "Wrzesień", "Październik", "Listopad", "Grudzień"];
const STATUS_FILTERS = [
    { key: "paid", label: "Zapłacone" },
    { key: "unpaid", label: "Niezapłacone" },
    { key: "all", label: "Wszystkie" },
];
const IMPORT_VIEWS = [
    { key: "all", label: "Wszystkie" },
    { key: "imported", label: "Zaimportowane" },
    { key: "not_imported", label: "Niezaimportowane" },
];

const tab = ref<"faktury" | "ustawienia">("faktury");
const rows = ref<Invoice[]>([...props.invoices]);
const cats = ref<Category[]>([...props.categories]);
const newCategory = ref("");

// Re-sync lokalnych kopii, gdy serwer zwróci nowe propsy (np. po zmianie filtra).
watch(() => props.invoices, (v) => { rows.value = v.map((x) => ({ ...x })); });
watch(() => props.categories, (v) => { cats.value = [...v]; });

// Podsumowanie liczone NA ŻYWO z wierszy — reaguje na filtry i na „opłacone".
const liveSummary = computed(() => {
    const list = rows.value;
    const sum = list.reduce((s, r) => s + Number(r.amount || 0), 0);
    const sumUnpaid = list.filter((r) => r.status !== "paid").reduce((s, r) => s + Number(r.amount || 0), 0);
    return { count: list.length, sum, sum_unpaid: sumUnpaid };
});

const local = reactive({
    year: props.filters.year ?? "all",
    month: props.filters.month ?? "all",
    quarter: props.filters.quarter ?? "all",
    status: props.filters.status ?? "all",
});

const hasActiveFilters = computed(() =>
    local.year !== "all" || local.month !== "all" || local.quarter !== "all" || local.status !== "all"
);

const importOpen = ref(false);
const importing = ref(false);
const importView = ref<string>("all");
const importFilter = reactive({ year: "all", month: "all", quarter: "all" });

const pageRoute = props.company === "bsp" ? "crafter.ksef.bsp" : "crafter.ksef.pareto";

function reload() {
    router.get(route(pageRoute), { ...local }, { preserveState: true, preserveScroll: true, replace: true });
}

function setStatus(key: string) {
    local.status = key;
    reload();
}

function clearFilters() {
    local.year = "all";
    local.month = "all";
    local.quarter = "all";
    local.status = "all";
    reload();
}

async function saveCategory(row: Invoice) {
    try {
        await axios.patch(route("crafter.ksef.invoices.category", row.id), { category: row.category || null });
    } catch {
        toast.error("Nie udało się zapisać kategorii.");
    }
}

async function togglePaid(row: Invoice, checked: boolean) {
    const status = checked ? "paid" : "unpaid";
    try {
        await axios.patch(route("crafter.ksef.invoices.status", row.id), { status });
        row.status = status;
        // Jeśli aktywny filtr statusu i wiersz już nie pasuje — usuń z widoku (spójnie z reload).
        if (local.status !== "all" && local.status !== status) {
            rows.value = rows.value.filter((r) => r.id !== row.id);
        }
    } catch {
        toast.error("Nie udało się zmienić statusu.");
    }
}

function pullAll() {
    importing.value = true;
    router.post(route("crafter.ksef.import", props.company), { ...importFilter }, {
        preserveScroll: true,
        onSuccess: () => { importOpen.value = false; },
        onFinish: () => { importing.value = false; },
    });
}

// ── Ustawienia → kategorie ──
async function addCategory() {
    const name = newCategory.value.trim();
    if (!name) return;
    try {
        const { data } = await axios.post(route("crafter.ksef.categories.store", props.company), { name });
        if (!cats.value.some((c) => c.id === data.category.id)) {
            cats.value.push(data.category);
        }
        newCategory.value = "";
    } catch {
        toast.error("Nie udało się dodać kategorii.");
    }
}

async function renameCategory(cat: Category) {
    const name = cat.name.trim();
    if (!name) return;
    try {
        await axios.patch(route("crafter.ksef.categories.update", cat.id), { name });
    } catch {
        toast.error("Nie udało się zmienić nazwy kategorii.");
    }
}

async function removeCategory(cat: Category) {
    if (!confirm(`Usunąć kategorię „${cat.name}"?`)) return;
    try {
        await axios.delete(route("crafter.ksef.categories.destroy", cat.id));
        cats.value = cats.value.filter((c) => c.id !== cat.id);
    } catch {
        toast.error("Nie udało się usunąć kategorii.");
    }
}

// ── formatowanie / dni ──
function formatAmount(n: number): string {
    return new Intl.NumberFormat("pl-PL", { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(n || 0)) + " zł";
}

function formatDate(d: string | null): string {
    if (!d) return "—";
    const [y, m, day] = d.substring(0, 10).split("-");
    return `${day}.${m}.${y}`;
}

function daysRemaining(row: Invoice): number | null {
    if (!row.due_date) return null;
    const due = new Date(row.due_date.substring(0, 10) + "T00:00:00");
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    return Math.round((due.getTime() - today.getTime()) / 86400000);
}

function daysInfo(row: Invoice): string | null {
    const n = daysRemaining(row);
    if (n === null) return null;
    return n > 0 ? `+${n}` : String(n);
}

function daysPillClass(row: Invoice): string {
    if (row.status === "paid") return "bg-gray-100 text-gray-500";
    const n = daysRemaining(row);
    if (n === null) return "bg-gray-100 text-gray-500";
    return n < 0 ? "bg-red-100 text-red-800" : "bg-green-100 text-green-800";
}
</script>
