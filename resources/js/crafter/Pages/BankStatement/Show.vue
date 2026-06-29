<template>
    <PageHeader :title="month.label">
        <Button color="gray" variant="outline" :leftIcon="TrashIcon" @click.prevent="deleteOpen = true">
            Usuń wyciąg
        </Button>
    </PageHeader>

    <PageContent fluid>
        <div class="bg-white rounded-lg shadow overflow-x-auto">
            <table class="min-w-full text-sm border-collapse">
                <thead>
                    <tr class="bg-gray-50 text-gray-600 text-xs uppercase tracking-wide border-b border-gray-200">
                        <th class="px-3 py-2 text-left">Data</th>
                        <th class="px-3 py-2 text-left">Opis</th>
                        <th class="px-3 py-2 text-left">Kontrahent</th>
                        <th class="px-3 py-2 text-right">Kwota</th>
                        <th class="px-3 py-2 text-center">Ważne</th>
                        <th class="px-3 py-2 text-center">Grupa</th>
                        <th class="px-3 py-2 text-center">Status</th>
                        <th class="px-3 py-2 w-40"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in rows"
                        :key="row.id"
                        class="border-b"
                        :class="rowClass(row)"
                    >
                        <td class="px-2 py-1 whitespace-nowrap">{{ formatDate(row.booking_date) }}</td>
                        <td class="px-2 py-1 text-xs">{{ row.description }}</td>
                        <td class="px-2 py-1 text-xs">{{ row.counterparty || '—' }}</td>
                        <td class="px-2 py-1 text-right font-mono whitespace-nowrap"
                            :class="Number(row.amount) < 0 ? 'text-red-700' : 'text-green-700'">
                            {{ formatAmount(row.amount) }}
                        </td>
                        <td class="px-2 py-1 text-center">
                            <input
                                type="checkbox"
                                :checked="row.is_important"
                                @change="toggleImportant(row, ($event.target as HTMLInputElement).checked)"
                            />
                        </td>
                        <td class="px-2 py-1 text-center">
                            <select
                                :value="row.settlement_group ?? ''"
                                @change="changeGroup(row, ($event.target as HTMLSelectElement).value)"
                                class="text-xs rounded border-gray-300"
                            >
                                <option value="">—</option>
                                <option value="koszt">Koszt</option>
                                <option value="kasa">Kasa</option>
                            </select>
                        </td>
                        <td class="px-2 py-1 text-center">
                            <span
                                class="inline-block text-xs font-semibold px-2 py-0.5 rounded"
                                :class="row.matched_id
                                    ? 'bg-green-100 text-green-800'
                                    : 'bg-red-100 text-red-800'"
                            >
                                {{ row.matched_id ? 'Rozliczone' : 'Nie rozliczone' }}
                            </span>
                        </td>
                        <td class="px-2 py-1 text-right whitespace-nowrap">
                            <button
                                v-if="Number(row.amount) < 0 && row.is_important && !row.matched_id"
                                @click="openMatch(row)"
                                class="text-xs text-blue-600 hover:underline"
                            >
                                Powiąż z kosztem
                            </button>
                            <button
                                v-else-if="row.matched_id"
                                @click="unmatch(row)"
                                class="text-xs text-gray-500 hover:text-red-600"
                            >
                                Odepnij
                            </button>
                        </td>
                    </tr>

                    <tr v-if="rows.length === 0">
                        <td colspan="8" class="px-3 py-6 text-center text-sm text-gray-400">
                            Brak pozycji.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            <Button color="gray" variant="outline" @click.prevent="back">
                ← Wróć do listy
            </Button>
        </div>
    </PageContent>

    <!-- Modal match -->
    <Modal :open="matchOpen" externalOpen size="lg" @toggleOpen="matchOpen = false">
        <template #title>Powiąż z pozycją kosztu</template>
        <template #content>
            <div v-if="matchRow" class="text-left space-y-4">
                <div class="bg-gray-50 rounded p-3 text-sm">
                    <div><strong>{{ formatDate(matchRow.booking_date) }}</strong> · {{ matchRow.counterparty || matchRow.description }}</div>
                    <div class="text-red-700 font-mono">{{ formatAmount(matchRow.amount) }}</div>
                </div>

                <input
                    v-model="matchQuery"
                    placeholder="Szukaj po nazwie..."
                    class="w-full border border-gray-300 rounded px-2 py-1.5 text-sm"
                />

                <div class="max-h-96 overflow-y-auto border rounded">
                    <div v-if="filteredCandidates.length === 0" class="p-4 text-sm text-gray-400 text-center">
                        Brak pasujących pozycji kosztów.
                    </div>
                    <div
                        v-for="c in filteredCandidates"
                        :key="c.id"
                        @click="doMatch(c.id)"
                        class="flex items-center justify-between p-2 border-b hover:bg-blue-50 cursor-pointer text-sm"
                    >
                        <div>
                            <div class="font-medium">{{ c.name }}</div>
                            <div class="text-xs text-gray-500">
                                {{ c.monthLabel }} · {{ c.category || '—' }} · {{ c.due_date || 'bez daty' }}
                            </div>
                        </div>
                        <div class="font-mono text-sm">{{ formatAmount(c.amount) }}</div>
                    </div>
                </div>
            </div>
        </template>
        <template #buttons="{ setIsOpen }">
            <Button color="gray" variant="outline"
                    @click.prevent="() => { setIsOpen(false); matchOpen = false; }">
                Anuluj
            </Button>
        </template>
    </Modal>

    <Modal :open="deleteOpen" externalOpen type="danger" @toggleOpen="deleteOpen = false">
        <template #title>Usuń wyciąg</template>
        <template #content>
            Czy na pewno usunąć wyciąg <strong>{{ month.label }}</strong> ze wszystkimi pozycjami?
            Powiązania z kosztami zostaną zerwane.
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
import { TrashIcon } from '@heroicons/vue/24/outline';
import { useToast } from '@brackets/vue-toastification';
import {
    PageHeader, PageContent,
    Button, Modal,
} from 'crafter/Components';

interface Item {
    id: number;
    booking_date: string;
    description: string | null;
    counterparty: string | null;
    amount: number | string;
    direction: 'in' | 'out';
    is_important: boolean;
    settlement_group: string | null;
    matched_type: string | null;
    matched_id: number | null;
}

interface CostItem {
    id: number;
    cost_planner_month_id: number;
    name: string | null;
    amount: number | string;
    due_date: string | null;
    status: string | null;
    category: string | null;
}

interface CostMonth {
    id: number;
    label: string;
    items: CostItem[];
}

const props = defineProps<{
    month: { id: number; label: string; bank: string };
    items: Item[];
    costMonths: CostMonth[];
    takenCostIds: number[];
}>();

const toast = useToast();
const rows = ref<Item[]>(props.items.map(normalize));
const deleteOpen = ref(false);
const matchOpen = ref(false);
const matchRow = ref<Item | null>(null);
const matchQuery = ref('');

function normalize(i: Item): Item {
    return {
        ...i,
        amount: Number(i.amount ?? 0),
        booking_date: i.booking_date ? String(i.booking_date).substring(0, 10) : '',
    };
}

const candidates = computed(() => {
    const taken = new Set(props.takenCostIds);
    const list: (CostItem & { monthLabel: string })[] = [];
    for (const m of props.costMonths) {
        for (const c of m.items) {
            if (taken.has(c.id)) continue;
            list.push({ ...c, monthLabel: m.label });
        }
    }
    return list;
});

const filteredCandidates = computed(() => {
    if (!matchRow.value) return [];
    const absAmount = Math.abs(Number(matchRow.value.amount));
    const q = matchQuery.value.trim().toLowerCase();

    // Sortowanie: najpierw pasujące po kwocie, potem reszta.
    return candidates.value
        .filter(c => {
            if (q && !(c.name ?? '').toLowerCase().includes(q)) return false;
            return true;
        })
        .sort((a, b) => {
            const diffA = Math.abs(Number(a.amount) - absAmount);
            const diffB = Math.abs(Number(b.amount) - absAmount);
            return diffA - diffB;
        })
        .slice(0, 100);
});

function rowClass(row: Item): string {
    if (!row.is_important) return 'bg-gray-50 text-gray-400';
    if (row.matched_id) return 'bg-green-50';
    return '';
}

function formatDate(d: string): string {
    if (!d) return '';
    const [y, m, day] = d.split('-');
    return `${day}.${m}.${y}`;
}

function formatAmount(v: number | string): string {
    const n = Number(v ?? 0);
    return new Intl.NumberFormat('pl-PL', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n) + ' zł';
}

async function toggleImportant(row: Item, val: boolean) {
    try {
        await axios.patch(route('crafter.bank-statements.items.update', row.id), { is_important: val });
        row.is_important = val;
    } catch {
        toast.error('Błąd zapisu.');
    }
}

async function changeGroup(row: Item, val: string) {
    try {
        await axios.patch(route('crafter.bank-statements.items.update', row.id), {
            settlement_group: val || null,
        });
        row.settlement_group = val || null;
    } catch {
        toast.error('Błąd zapisu.');
    }
}

function openMatch(row: Item) {
    matchRow.value = row;
    matchQuery.value = '';
    matchOpen.value = true;
}

async function doMatch(costId: number) {
    if (!matchRow.value) return;
    try {
        const { data } = await axios.post(
            route('crafter.bank-statements.items.match', matchRow.value.id),
            { cost_planner_item_id: costId }
        );
        const r = rows.value.find(x => x.id === matchRow.value!.id);
        if (r) {
            r.matched_id = data.item.matched_id;
            r.matched_type = data.item.matched_type;
            r.settlement_group = data.item.settlement_group;
        }
        matchOpen.value = false;
        toast.success('Powiązano.');
    } catch (e: any) {
        toast.error(e?.response?.data?.error ?? 'Nie udało się powiązać.');
    }
}

async function unmatch(row: Item) {
    try {
        await axios.delete(route('crafter.bank-statements.items.unmatch', row.id));
        row.matched_id = null;
        row.matched_type = null;
    } catch {
        toast.error('Błąd.');
    }
}

function back() {
    router.visit(route('crafter.bank-statements.index'));
}

function confirmDelete() {
    router.delete(route('crafter.bank-statements.destroy', props.month.id), {
        onSuccess: () => toast.success('Wyciąg usunięty.'),
    });
}
</script>
