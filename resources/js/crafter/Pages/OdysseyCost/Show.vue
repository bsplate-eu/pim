<template>
    <PageHeader :title="month.label">
        <Button :leftIcon="ArrowPathIcon" :loading="refreshing" @click.prevent="refresh">
            Odśwież
        </Button>
        <Button color="gray" variant="outline" :leftIcon="TrashIcon" @click.prevent="deleteOpen = true">
            Usuń miesiąc
        </Button>
    </PageHeader>

    <PageContent fluid>
        <!-- Tabela zamówień -->
        <div class="bg-white rounded-lg shadow overflow-x-auto">
            <table class="min-w-full text-sm border-collapse">
                <thead>
                    <tr class="bg-gray-50 text-gray-600 text-xs uppercase tracking-wide border-b border-gray-200">
                        <th class="px-3 py-2 text-left w-12">Lp.</th>
                        <th class="px-3 py-2 text-left">Nr zamówienia</th>
                        <th class="px-3 py-2 text-left">Sprzedane pozycje</th>
                        <th class="px-3 py-2 text-left">Nr wysyłki</th>
                        <th class="px-3 py-2 text-right">Towar</th>
                        <th class="px-3 py-2 text-right">Wysyłka</th>
                        <th class="px-3 py-2 text-right">Razem</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="(row, idx) in rows"
                        :key="row.id"
                        class="border-b hover:bg-gray-50"
                        :class="{ 'bg-yellow-50': idx % 2 === 0 }"
                    >
                        <td class="px-3 py-1 text-gray-500">{{ idx + 1 }}</td>
                        <td class="px-3 py-1">
                            <a
                                :href="route('crafter.connect.orders.show', row.order_id)"
                                class="text-blue-600 hover:underline font-mono"
                            >
                                {{ row.baselinker_order_id ?? '—' }}
                            </a>
                        </td>
                        <td class="px-3 py-1 text-xs text-gray-600">{{ row.items_label }}</td>
                        <td class="px-3 py-1 text-xs font-mono text-gray-700">{{ row.tracking_number || '—' }}</td>
                        <td class="px-2 py-1 text-right">
                            <input
                                v-model.number="row.cost_goods"
                                @change="saveEntry(row, 'cost_goods')"
                                type="number" step="0.01" min="0"
                                class="w-24 bg-yellow-100 px-1 py-0.5 text-right focus:outline-none focus:ring-1 focus:ring-blue-400 rounded"
                            />
                        </td>
                        <td class="px-2 py-1 text-right">
                            <input
                                v-model.number="row.cost_shipping"
                                @change="saveEntry(row, 'cost_shipping')"
                                type="number" step="0.01" min="0"
                                class="w-24 bg-yellow-100 px-1 py-0.5 text-right focus:outline-none focus:ring-1 focus:ring-blue-400 rounded"
                            />
                        </td>
                        <td class="px-3 py-1 text-right font-medium">{{ fmt(rowTotal(row)) }}</td>
                    </tr>

                    <tr v-if="rows.length === 0">
                        <td colspan="7" class="px-3 py-6 text-center text-sm text-gray-400">
                            Brak zamówień Odyssey w tym miesiącu.
                        </td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr class="bg-gray-50 text-gray-700 font-semibold border-t border-gray-200">
                        <td class="px-3 py-2" colspan="4">SUMA</td>
                        <td class="px-3 py-2 text-right">{{ fmt(totalGoods) }}</td>
                        <td class="px-3 py-2 text-right">{{ fmt(totalShipping) }}</td>
                        <td class="px-3 py-2 text-right">{{ fmt(totalSum) }}</td>
                    </tr>
                    <tr class="bg-gray-100 text-gray-900 font-bold border-t border-gray-300">
                        <td class="px-3 py-2" colspan="4">PODSUMOWANIE (po wpłatach)</td>
                        <td class="px-3 py-2 text-right text-red-700">- {{ fmt(remainingGoods) }}</td>
                        <td class="px-3 py-2 text-right text-red-700">- {{ fmt(remainingShipping) }}</td>
                        <td class="px-3 py-2 text-right"
                            :class="balanceDue > 0 ? 'text-red-700' : 'text-green-700'">
                            {{ balanceDue > 0 ? '-' : '' }} {{ fmt(Math.abs(balanceDue)) }}
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <!-- Wpłaty -->
        <div class="mt-6 bg-white rounded-lg shadow">
            <div class="px-4 py-3 border-b border-gray-200 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-gray-700">Wpłaty</h3>
                <span class="text-sm text-gray-600">
                    Suma wpłat: <strong>{{ fmt(totalPaid) }}</strong>
                </span>
            </div>
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="bg-gray-50 text-gray-600 text-xs uppercase tracking-wide border-b">
                        <th class="px-3 py-2 text-left">Data</th>
                        <th class="px-3 py-2 text-right">Kwota</th>
                        <th class="px-3 py-2 text-left">Nr FV</th>
                        <th class="px-3 py-2 w-8"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="p in paymentsList" :key="p.id" class="border-b hover:bg-gray-50">
                        <td class="px-2 py-1">
                            <input
                                v-model="p.paid_at"
                                @change="savePayment(p, 'paid_at')"
                                type="date"
                                class="w-36 bg-transparent px-1 py-0.5 text-sm focus:outline-none focus:ring-1 focus:ring-blue-400 rounded"
                            />
                        </td>
                        <td class="px-2 py-1 text-right">
                            <input
                                v-model.number="p.amount"
                                @change="savePayment(p, 'amount')"
                                type="number" step="0.01" min="0"
                                class="w-28 bg-green-100 px-1 py-0.5 text-right focus:outline-none focus:ring-1 focus:ring-blue-400 rounded"
                            />
                        </td>
                        <td class="px-2 py-1">
                            <input
                                v-model="p.invoice_number"
                                @change="savePayment(p, 'invoice_number')"
                                placeholder="nr FV"
                                class="w-40 bg-transparent px-1 py-0.5 text-sm focus:outline-none focus:ring-1 focus:ring-blue-400 rounded"
                            />
                        </td>
                        <td class="px-2 py-1">
                            <button @click="removePayment(p)" class="text-red-500 hover:text-red-700 p-1" title="Usuń">
                                <TrashIcon class="w-4 h-4" />
                            </button>
                        </td>
                    </tr>
                    <tr v-if="paymentsList.length === 0">
                        <td colspan="4" class="px-3 py-4 text-center text-sm text-gray-400">
                            Brak wpłat.
                        </td>
                    </tr>
                </tbody>
            </table>

            <button
                type="button"
                @click="addPayment"
                class="w-full text-left px-3 py-2 text-sm text-gray-500 hover:text-blue-600 hover:bg-blue-50 border-t border-gray-200 transition"
            >
                + dodaj wpłatę
            </button>
        </div>

        <div class="mt-4 flex gap-2">
            <Button color="gray" variant="outline" @click.prevent="back">← Wróć do listy</Button>
        </div>
    </PageContent>

    <Modal :open="deleteOpen" externalOpen type="danger" @toggleOpen="deleteOpen = false">
        <template #title>Usuń miesiąc</template>
        <template #content>
            Czy na pewno usunąć miesiąc <strong>{{ month.label }}</strong> wraz ze wszystkimi pozycjami i wpłatami?
        </template>
        <template #buttons="{ setIsOpen }">
            <Button color="red" :leftIcon="TrashIcon" @click.prevent="confirmDelete">Usuń</Button>
            <Button color="gray" variant="outline"
                    @click.prevent="() => { setIsOpen(false); deleteOpen = false; }">Anuluj</Button>
        </template>
    </Modal>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { TrashIcon, ArrowPathIcon } from '@heroicons/vue/24/outline';
import { useToast } from '@brackets/vue-toastification';
import { PageHeader, PageContent, Button, Modal } from 'crafter/Components';

interface Entry {
    id: number;
    order_id: number;
    baselinker_order_id: number | string | null;
    order_date: string | null;
    items_label: string;
    tracking_number: string | null;
    cost_goods: number;
    cost_shipping: number;
    total: number;
}

interface Payment {
    id: number;
    paid_at: string | null;
    amount: number;
    invoice_number: string | null;
}

const props = defineProps<{
    month: { id: number; label: string; year: number; month: number; notes: string | null };
    entries: Entry[];
    payments: Payment[];
}>();

const toast = useToast();
const rows = ref<Entry[]>(props.entries.map(e => ({ ...e, cost_goods: Number(e.cost_goods), cost_shipping: Number(e.cost_shipping) })));
const paymentsList = ref<Payment[]>(props.payments.map(p => ({ ...p, amount: Number(p.amount) })));
const deleteOpen = ref(false);
const refreshing = ref(false);

function refresh() {
    refreshing.value = true;
    router.post(route('crafter.odyssey-cost.refresh', props.month.id), {}, {
        preserveScroll: true,
        onSuccess: () => toast.success('Zamówienia odświeżone.'),
        onError: () => toast.error('Nie udało się odświeżyć.'),
        onFinish: () => { refreshing.value = false; },
    });
}

const rowTotal = (r: Entry) => Number(r.cost_goods || 0) + Number(r.cost_shipping || 0);

const totalGoods = computed(() => rows.value.reduce((s, r) => s + Number(r.cost_goods || 0), 0));
const totalShipping = computed(() => rows.value.reduce((s, r) => s + Number(r.cost_shipping || 0), 0));
const totalSum = computed(() => totalGoods.value + totalShipping.value);
const totalPaid = computed(() => paymentsList.value.reduce((s, p) => s + Number(p.amount || 0), 0));
const balanceDue = computed(() => totalSum.value - totalPaid.value);

// Proporcjonalny rozkład wpłat na towar i wysyłkę (do wyświetlenia "co zostało")
const remainingGoods = computed(() => {
    if (totalSum.value <= 0) return 0;
    const share = (totalGoods.value / totalSum.value) * totalPaid.value;
    return Math.max(0, totalGoods.value - share);
});
const remainingShipping = computed(() => {
    if (totalSum.value <= 0) return 0;
    const share = (totalShipping.value / totalSum.value) * totalPaid.value;
    return Math.max(0, totalShipping.value - share);
});

const fmt = (n: number): string =>
    new Intl.NumberFormat('pl-PL', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n) + ' zł';

async function saveEntry(row: Entry, field: 'cost_goods' | 'cost_shipping') {
    try {
        const payload: Record<string, any> = { [field]: Number((row as any)[field] ?? 0) || 0 };
        await axios.patch(route('crafter.odyssey-cost.entries.update', row.id), payload);
    } catch {
        toast.error('Błąd zapisu.');
    }
}

async function addPayment() {
    try {
        const today = new Date().toISOString().substring(0, 10);
        const { data } = await axios.post(
            route('crafter.odyssey-cost.payments.store', props.month.id),
            { paid_at: today, amount: 0, invoice_number: null }
        );
        paymentsList.value.push({ ...data.payment, amount: Number(data.payment.amount) });
    } catch {
        toast.error('Nie udało się dodać wpłaty.');
    }
}

async function savePayment(p: Payment, field: keyof Payment) {
    try {
        const payload: Record<string, any> = { [field]: (p as any)[field] };
        if (field === 'amount') payload[field] = Number(p.amount ?? 0) || 0;
        await axios.patch(route('crafter.odyssey-cost.payments.update', p.id), payload);
    } catch {
        toast.error('Błąd zapisu wpłaty.');
    }
}

async function removePayment(p: Payment) {
    if (!confirm('Usunąć wpłatę?')) return;
    try {
        await axios.delete(route('crafter.odyssey-cost.payments.destroy', p.id));
        paymentsList.value = paymentsList.value.filter(x => x.id !== p.id);
    } catch {
        toast.error('Nie udało się usunąć wpłaty.');
    }
}

function back() {
    router.visit(route('crafter.odyssey-cost.index'));
}

function confirmDelete() {
    router.delete(route('crafter.odyssey-cost.destroy', props.month.id), {
        onSuccess: () => toast.success('Miesiąc usunięty.'),
    });
}
</script>
