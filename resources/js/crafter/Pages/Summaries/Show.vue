<template>
    <PageHeader :title="`Zestawienie — ${month.label}`">
        <Button color="gray" variant="outline" :leftIcon="ArrowPathIcon" @click.prevent="refresh">
            Odśwież
        </Button>
        <Button :leftIcon="ArrowDownTrayIcon" @click.prevent="exportXls">
            Eksport
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
                        <th class="px-3 py-2 text-left w-12">#</th>
                        <th class="px-3 py-2 text-left">Data zamówienia</th>
                        <th class="px-3 py-2 text-left">Data utworzenia</th>
                        <th class="px-3 py-2 text-left">Źródło</th>
                        <th class="px-3 py-2 text-left">Numer FV</th>
                        <th class="px-3 py-2 text-left">Imię i nazwisko</th>
                        <th class="px-3 py-2 text-right">Kwota brutto</th>
                        <th class="px-3 py-2 text-left">Typ</th>
                        <th class="px-3 py-2 text-left">Rodzaj dokumentu</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="(row, idx) in rows"
                        :key="row.id"
                        class="border-b hover:bg-gray-50"
                    >
                        <td class="px-3 py-2 text-gray-400">{{ idx + 1 }}</td>
                        <td class="px-3 py-2">{{ formatDate(row.order_date) }}</td>
                        <td class="px-3 py-2">{{ formatDate(row.issue_date) }}</td>
                        <td class="px-3 py-2">{{ row.source }}</td>
                        <td class="px-3 py-2 font-mono">{{ row.nr_full }}</td>
                        <td class="px-3 py-2">{{ row.customer_name || '—' }}</td>
                        <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ formatMoney(row.total_brutto, row.currency) }}</td>
                        <td class="px-3 py-2">
                            <span
                                class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium uppercase tracking-wide"
                                :class="row.type === 'correction'
                                    ? 'bg-orange-100 text-orange-700'
                                    : 'bg-blue-100 text-blue-700'"
                            >
                                {{ row.type === 'correction' ? 'Korekta' : 'Faktura' }}
                            </span>
                        </td>
                        <td class="px-3 py-2">{{ row.doc_type_de }}</td>
                    </tr>

                    <tr v-if="rows.length === 0">
                        <td colspan="9" class="px-3 py-6 text-center text-sm text-gray-400">
                            Brak faktur/korekt dla tego miesiąca (źródła: ebay / BSP DE).
                        </td>
                    </tr>
                </tbody>
                <tfoot v-if="rows.length > 0">
                    <tr class="bg-gray-50 text-gray-700 font-semibold border-t border-gray-200">
                        <td class="px-3 py-2" colspan="6">RAZEM</td>
                        <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ formatMoney(totalBrutto, currency) }}</td>
                        <td class="px-3 py-2" colspan="2">{{ rows.length }} poz.</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div class="mt-4 flex gap-2">
            <Button color="gray" variant="outline" @click.prevent="back">
                ← Wróć do listy
            </Button>
        </div>
    </PageContent>

    <Modal :open="deleteOpen" externalOpen type="danger" @toggleOpen="deleteOpen = false">
        <template #title>Usuń miesiąc</template>
        <template #content>
            Czy na pewno usunąć miesiąc <strong>{{ month.label }}</strong>? Pozycje (faktury) nie są usuwane — znikają tylko z tego zestawienia.
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
import { TrashIcon, ArrowPathIcon, ArrowDownTrayIcon } from '@heroicons/vue/24/outline';
import { useToast } from '@brackets/vue-toastification';
import {
    PageHeader, PageContent,
    Button, Modal,
} from 'crafter/Components';

interface Row {
    id: number;
    type: 'invoice' | 'correction';
    doc_type_de: string;
    nr: number;
    nr_full: string;
    customer_name: string | null;
    total_brutto: number;
    currency: string | null;
    issue_date: string | null;
    order_date: string | null;
    source: string;
    year: number;
    month: number;
}

const props = defineProps<{
    month: { id: number; label: string; year: number; month: number };
    rows: Row[];
}>();

const toast = useToast();
const deleteOpen = ref(false);

const totalBrutto = computed(() => props.rows.reduce((sum, r) => sum + (Number(r.total_brutto) || 0), 0));
const currency = computed(() => props.rows.find(r => r.currency)?.currency ?? null);

function formatDate(d: string | null): string {
    if (!d) return '—';
    return new Date(d + 'T00:00:00').toLocaleDateString('pl-PL');
}

function formatMoney(value: number | null, curr: string | null): string {
    const n = Number(value) || 0;
    const amount = n.toLocaleString('pl-PL', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    return curr ? `${amount} ${curr}` : amount;
}

function refresh() {
    router.reload({ only: ['rows'] });
}

function exportXls() {
    window.location.href = route('crafter.cost-planner.summaries.export', props.month.id);
}

function back() {
    router.visit(route('crafter.cost-planner.summaries.index'));
}

function confirmDelete() {
    router.delete(route('crafter.cost-planner.summaries.destroy', props.month.id), {
        onSuccess: () => toast.success('Miesiąc usunięty.'),
    });
}
</script>
