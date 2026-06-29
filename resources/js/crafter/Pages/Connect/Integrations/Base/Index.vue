<template>
    <PageHeader title="Integracje — BaseLinker">
        <Link :href="route('crafter.connect.integrations.base.create')">
            <Button :leftIcon="PlusIcon" color="primary">Dodaj Base</Button>
        </Link>
    </PageHeader>

    <PageContent fluid>
        <Card>
            <CardContent class="p-0">
                <div v-if="bases.length === 0" class="p-8 text-center text-sm text-gray-500">
                    Brak skonfigurowanych Base'ów. Kliknij "Dodaj Base" żeby zacząć.
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Etykieta</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Klucz API</th>
                                <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Zamówień</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Ostatni sync</th>
                                <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Akcje</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white">
                            <tr v-for="b in bases" :key="b.id" class="hover:bg-gray-50">
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <Link
                                        :href="route('crafter.connect.integrations.base.edit', b.id)"
                                        class="font-semibold text-primary-600 hover:underline"
                                    >
                                        {{ b.label }}
                                    </Link>
                                </td>
                                <td class="px-4 py-3">
                                    <span
                                        :class="[
                                            'inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium',
                                            b.enabled ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500',
                                        ]"
                                    >
                                        {{ b.enabled ? 'Aktywny' : 'Wyłączony' }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 font-mono text-xs">
                                    <span v-if="b.has_api_key" class="text-gray-600">{{ b.masked_api_key }}</span>
                                    <span v-else class="text-red-600">— brak klucza —</span>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded bg-primary-50 text-primary-700 text-xs font-semibold">
                                        {{ b.orders_count }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-xs text-gray-500">
                                    {{ b.last_sync_at ? formatDate(b.last_sync_at) : '—' }}
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <div class="flex justify-end gap-2">
                                        <Link :href="route('crafter.connect.integrations.base.edit', b.id)">
                                            <Button size="sm" variant="outline" color="gray">Edytuj</Button>
                                        </Link>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            color="red"
                                            @click="confirmDelete(b)"
                                        >
                                            Usuń
                                        </Button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </CardContent>
        </Card>
    </PageContent>
</template>

<script setup lang="ts">
import { Link, router } from "@inertiajs/vue3";
import { PlusIcon } from "@heroicons/vue/24/outline";
import { useToast } from "@brackets/vue-toastification";
import {
    PageHeader,
    PageContent,
    Button,
    Card,
    CardContent,
} from "crafter/Components";

interface BaseRow {
    id: number;
    label: string;
    enabled: boolean;
    has_api_key: boolean;
    masked_api_key: string | null;
    sync_interval_minutes: number;
    last_sync_at: string | null;
    last_sync_order_id: number | null;
    orders_count: number;
}

interface Props {
    bases: BaseRow[];
}

defineProps<Props>();
const toast = useToast();

function formatDate(iso: string | null): string {
    if (!iso) return "—";
    const d = new Date(iso);
    return d.toLocaleString("pl-PL", {
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
        hour: "2-digit",
        minute: "2-digit",
    });
}

function confirmDelete(base: BaseRow) {
    if (!confirm(`Usunąć Base „${base.label}”? Zamówienia zostaną zachowane (przypisanie do Base zostanie wyzerowane).`)) {
        return;
    }
    router.delete(route("crafter.connect.integrations.base.destroy", base.id), {
        preserveScroll: true,
        onSuccess: () => toast.success(`Base „${base.label}” usunięty.`),
        onError: () => toast.error("Nie udało się usunąć Base."),
    });
}
</script>
