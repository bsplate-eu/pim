<template>
    <PageHeader :title="month.label">
        <Button :leftIcon="PlusIcon" @click.prevent="addItem">
            Dodaj pozycję
        </Button>
        <Button color="gray" variant="outline" :leftIcon="TrashIcon" @click.prevent="deleteOpen = true">
            Usuń miesiąc
        </Button>
    </PageHeader>

    <PageContent fluid>
        <div class="bg-white rounded-lg shadow overflow-x-auto">
            <table class="min-w-full text-sm border-collapse">
                <thead>
                    <tr class="bg-gray-50 text-gray-600 text-xs uppercase tracking-wide border-b border-gray-200">
                        <th class="px-3 py-2 text-left">Koszty</th>
                        <th class="px-3 py-2 text-right">Do zapłaty</th>
                        <th class="px-3 py-2 text-left">Status</th>
                        <th class="px-3 py-2 text-left">Rodzaj</th>
                        <th class="px-3 py-2 text-left">Typ</th>
                        <th class="px-3 py-2 text-left">Do kiedy</th>
                        <th class="px-3 py-2 text-center">Dni</th>
                        <th class="px-3 py-2 text-left">Waluta</th>
                        <th class="px-3 py-2 text-center">Rozliczenie</th>
                        <th class="px-3 py-2 w-8"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="(row, idx) in rows"
                        :key="row.id"
                        class="border-b hover:bg-gray-50"
                        :class="{ 'bg-yellow-50': idx % 2 === 0 }"
                    >
                        <td class="px-2 py-1">
                            <input
                                v-model="row.name"
                                @change="save(row, 'name')"
                                :list="'cost-names-' + month.id"
                                placeholder="Nazwa..."
                                class="w-full bg-transparent px-1 py-0.5 text-sm focus:outline-none focus:bg-white focus:ring-1 focus:ring-blue-400 rounded"
                            />
                        </td>
                        <td class="px-2 py-1 text-right">
                            <input
                                v-model.number="row.amount"
                                @change="save(row, 'amount')"
                                type="number"
                                step="0.01"
                                min="0"
                                class="w-24 bg-yellow-100 px-1 py-0.5 text-right focus:outline-none focus:ring-1 focus:ring-blue-400 rounded"
                            />
                        </td>
                        <td class="px-2 py-1">
                            <select
                                v-model="row.status"
                                @change="save(row, 'status')"
                                :class="pillClass(colorOf(settings.statuses, row.status))"
                            >
                                <option v-for="s in settings.statuses" :key="s.name" :value="s.name">
                                    {{ s.name }}
                                </option>
                            </select>
                        </td>
                        <td class="px-2 py-1">
                            <select
                                v-model="row.category"
                                @change="save(row, 'category')"
                                :class="pillClass(colorOf(settings.categories, row.category))"
                            >
                                <option :value="null">—</option>
                                <option v-for="c in settings.categories" :key="c.name" :value="c.name">
                                    {{ c.name }}
                                </option>
                            </select>
                        </td>
                        <td class="px-2 py-1">
                            <select
                                v-model="row.type"
                                @change="save(row, 'type')"
                                :class="pillClass(colorOf(settings.types, row.type))"
                            >
                                <option :value="null">—</option>
                                <option v-for="t in settings.types" :key="t.name" :value="t.name">
                                    {{ t.name }}
                                </option>
                            </select>
                        </td>
                        <td class="px-2 py-1">
                            <input
                                v-model="row.due_date"
                                @change="save(row, 'due_date')"
                                type="date"
                                class="w-36 bg-transparent px-1 py-0.5 text-sm focus:outline-none focus:ring-1 focus:ring-blue-400 rounded"
                            />
                        </td>
                        <td class="px-2 py-1 text-center">
                            <span
                                v-if="daysInfo(row)"
                                :class="daysPillClass(row)"
                                class="inline-block text-xs font-semibold px-2 py-0.5 rounded"
                            >
                                {{ daysInfo(row) }}
                            </span>
                            <span v-else class="text-gray-300 text-xs">—</span>
                        </td>
                        <td class="px-2 py-1">
                            <select
                                v-model="row.currency"
                                @change="save(row, 'currency')"
                                class="bg-transparent text-sm focus:outline-none"
                            >
                                <option v-for="c in settings.currencies" :key="c" :value="c">{{ c }}</option>
                            </select>
                        </td>
                        <td class="px-2 py-1 text-center">
                            <span
                                class="inline-block text-xs font-semibold px-2 py-0.5 rounded"
                                :class="isReconciled(row)
                                    ? 'bg-green-100 text-green-800'
                                    : 'bg-red-100 text-red-800'"
                            >
                                {{ isReconciled(row) ? 'Rozliczone' : 'Nie rozliczone' }}
                            </span>
                        </td>
                        <td class="px-2 py-1">
                            <button
                                @click="removeItem(row)"
                                class="text-red-500 hover:text-red-700 p-1"
                                title="Usuń"
                            >
                                <TrashIcon class="w-4 h-4" />
                            </button>
                        </td>
                    </tr>

                    <tr v-if="rows.length === 0">
                        <td colspan="10" class="px-3 py-6 text-center text-sm text-gray-400">
                            Brak pozycji. Kliknij „Dodaj pozycję”.
                        </td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr class="bg-gray-50 text-gray-700 font-semibold border-t border-gray-200">
                        <td class="px-3 py-2">SUMA</td>
                        <td class="px-3 py-2 text-right">{{ formatAmount(totalAmount) }}</td>
                        <td class="px-3 py-2" colspan="5"></td>
                        <td class="px-3 py-2 text-right">DO ZAPŁATY</td>
                        <td class="px-3 py-2 text-right">{{ formatAmount(totalUnpaid) }}</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>

            <button
                type="button"
                @click="addItem"
                class="w-full text-left px-3 py-2 text-sm text-gray-500 hover:text-blue-600 hover:bg-blue-50 border-t border-gray-200 transition"
            >
                + dodaj koszt
            </button>
        </div>

        <datalist :id="'cost-names-' + month.id">
            <option v-for="n in settings.cost_names" :key="n" :value="n" />
        </datalist>

        <div class="mt-4 flex gap-2">
            <Button color="gray" variant="outline" @click.prevent="back">
                ← Wróć do listy
            </Button>
        </div>
    </PageContent>

    <Modal :open="deleteOpen" externalOpen type="danger" @toggleOpen="deleteOpen = false">
        <template #title>Usuń miesiąc</template>
        <template #content>
            Czy na pewno usunąć miesiąc <strong>{{ month.label }}</strong> wraz ze wszystkimi pozycjami?
        </template>
        <template #buttons="{ setIsOpen }">
            <Button color="red" :leftIcon="TrashIcon" @click.prevent="confirmDelete">Usuń</Button>
            <Button color="gray" variant="outline"
                    @click.prevent="() => { setIsOpen(false); deleteOpen = false; }">
                Anuluj
            </Button>
        </template>
    </Modal>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { PlusIcon, TrashIcon } from '@heroicons/vue/24/outline';
import { useToast } from '@brackets/vue-toastification';
import {
    PageHeader, PageContent,
    Button, Modal,
} from 'crafter/Components';

interface Item {
    id: number;
    name: string | null;
    amount: number | string;
    status: string;
    due_date: string | null;
    category: string | null;
    type: string | null;
    currency: string;
    position: number;
}

interface NamedColor { name: string; color: string; }

interface Settings {
    cost_names: string[];
    statuses: NamedColor[];
    categories: NamedColor[];
    types: NamedColor[];
    currencies: string[];
}

const props = defineProps<{
    month: { id: number; label: string; year: number; month: number };
    items: Item[];
    settings: Settings;
    reconciledIds?: number[];
}>();

function isReconciled(row: Item): boolean {
    return (props.reconciledIds ?? []).includes(row.id);
}

const toast = useToast();
const rows = ref<Item[]>(props.items.map(normalize));
const deleteOpen = ref(false);

function normalize(i: Item): Item {
    return {
        ...i,
        amount: Number(i.amount ?? 0),
        due_date: i.due_date ? String(i.due_date).substring(0, 10) : null,
    };
}

const totalAmount = computed(() =>
    rows.value.reduce((s, r) => s + Number(r.amount || 0), 0)
);

const paidNames = computed(() =>
    props.settings.statuses
        .filter(s => /zap[łl]ac/i.test(s.name))
        .map(s => s.name)
);

const totalUnpaid = computed(() =>
    rows.value
        .filter(r => !paidNames.value.includes(r.status))
        .reduce((s, r) => s + Number(r.amount || 0), 0)
);

function colorOf(list: NamedColor[], value: string | null): string | undefined {
    if (!value) return undefined;
    return list.find(x => x.name === value)?.color;
}

function daysRemaining(row: Item): number | null {
    if (!row.due_date) return null;
    const due = new Date(row.due_date + 'T00:00:00');
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const diffMs = due.getTime() - today.getTime();
    return Math.round(diffMs / 86400000);
}

function isPaid(row: Item): boolean {
    return paidNames.value.includes(row.status);
}

function daysInfo(row: Item): string | null {
    const n = daysRemaining(row);
    if (n === null) return null;
    return n > 0 ? `+${n}` : String(n);
}

function daysPillClass(row: Item): string {
    if (isPaid(row)) return 'bg-gray-100 text-gray-500';
    const n = daysRemaining(row);
    if (n === null) return 'bg-gray-100 text-gray-500';
    if (n < 0) return 'bg-red-100 text-red-800';
    return 'bg-green-100 text-green-800';
}

const pillClass = (color?: string): string => {
    const base = 'text-xs font-medium px-2 py-0.5 rounded border-0 focus:outline-none focus:ring-1 focus:ring-blue-400';
    const map: Record<string, string> = {
        green:  'bg-green-100 text-green-800',
        red:    'bg-red-100 text-red-800',
        orange: 'bg-orange-100 text-orange-800',
        blue:   'bg-blue-100 text-blue-800',
        yellow: 'bg-yellow-100 text-yellow-800',
        amber:  'bg-amber-100 text-amber-800',
        purple: 'bg-purple-100 text-purple-800',
        pink:   'bg-pink-100 text-pink-800',
        indigo: 'bg-indigo-100 text-indigo-800',
        cyan:   'bg-cyan-100 text-cyan-800',
        gray:   'bg-gray-100 text-gray-800',
    };
    return `${base} ${map[color ?? 'gray'] ?? map.gray}`;
};

const formatAmount = (n: number): string =>
    new Intl.NumberFormat('pl-PL', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n) + ' zł';

async function addItem() {
    try {
        const defaultStatus = props.settings.statuses.find(s => /do zap/i.test(s.name))?.name
            ?? props.settings.statuses[0]?.name
            ?? 'Do zapłaty';
        const { data } = await axios.post(
            route('crafter.cost-planner.items.store', props.month.id),
            { name: '', amount: 0, status: defaultStatus, currency: props.settings.currencies[0] ?? 'PLN' }
        );
        rows.value.push(normalize(data.item));
    } catch {
        toast.error('Nie udało się dodać pozycji.');
    }
}

async function save(row: Item, field: keyof Item) {
    try {
        const payload: Record<string, any> = { [field]: (row as any)[field] };
        if (field === 'amount') payload[field] = Number(row.amount ?? 0) || 0;
        await axios.patch(route('crafter.cost-planner.items.update', row.id), payload);
    } catch {
        toast.error('Błąd zapisu.');
    }
}

async function removeItem(row: Item) {
    if (!confirm('Usunąć pozycję?')) return;
    try {
        await axios.delete(route('crafter.cost-planner.items.destroy', row.id));
        rows.value = rows.value.filter(r => r.id !== row.id);
    } catch {
        toast.error('Nie udało się usunąć pozycji.');
    }
}

function back() {
    router.visit(route('crafter.cost-planner.index'));
}

function confirmDelete() {
    router.delete(route('crafter.cost-planner.destroy', props.month.id), {
        onSuccess: () => toast.success('Miesiąc usunięty.'),
    });
}
</script>
