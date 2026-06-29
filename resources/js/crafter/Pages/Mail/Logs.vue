<template>
    <PageHeader sticky title="Logi poczty" />

    <PageContent fluid>
        <div class="p-4">
            <div class="mb-3 text-xs text-slate-500">
                Historia wysłanych wiadomości. Logi starsze niż 30 dni są usuwane automatycznie.
            </div>

            <div class="bg-white rounded-lg border border-slate-200 overflow-hidden">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="px-4 py-2 text-left font-medium text-slate-600">Data</th>
                            <th class="px-4 py-2 text-left font-medium text-slate-600">Odbiorca</th>
                            <th class="px-4 py-2 text-left font-medium text-slate-600">Temat</th>
                            <th class="px-4 py-2 text-left font-medium text-slate-600">Szablon</th>
                            <th class="px-4 py-2 text-left font-medium text-slate-600">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        <tr v-if="!logs.data.length">
                            <td colspan="5" class="px-4 py-8 text-center text-slate-400">
                                Brak zapisanych wysyłek.
                            </td>
                        </tr>
                        <tr v-for="log in logs.data" :key="log.id" class="hover:bg-slate-50">
                            <td class="px-4 py-2 whitespace-nowrap text-slate-600">
                                {{ formatDate(log.sent_at || log.created_at) }}
                            </td>
                            <td class="px-4 py-2 font-mono text-xs">{{ log.to_email }}</td>
                            <td class="px-4 py-2">{{ log.subject || '—' }}</td>
                            <td class="px-4 py-2 font-mono text-xs text-slate-500">{{ log.template_key || '—' }}</td>
                            <td class="px-4 py-2">
                                <span
                                    v-if="log.status === 'sent'"
                                    class="inline-block rounded bg-green-100 px-2 py-0.5 text-xs text-green-700"
                                >
                                    wysłany
                                </span>
                                <span
                                    v-else
                                    class="inline-block rounded bg-red-100 px-2 py-0.5 text-xs text-red-700"
                                    :title="log.error"
                                >
                                    błąd
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div v-if="logs.last_page > 1" class="mt-4 flex items-center justify-between text-sm">
                <div class="text-slate-500">
                    Wyświetlono {{ logs.from }}–{{ logs.to }} z {{ logs.total }}
                </div>
                <div class="flex gap-1">
                    <!-- v-html bezpieczne: link.label to string z Laravel paginatora (&laquo;, &raquo;, numer) - nie zawiera user inputu -->
                    <Link
                        v-for="link in logs.links"
                        :key="link.label"
                        :href="link.url || '#'"
                        v-html="link.label"
                        :class="[
                            'px-3 py-1 rounded border',
                            link.active ? 'bg-primary-600 text-white border-primary-600' : 'bg-white border-slate-300 text-slate-600 hover:bg-slate-50',
                            !link.url ? 'opacity-40 pointer-events-none' : '',
                        ]"
                    />
                </div>
            </div>
        </div>
    </PageContent>
</template>

<script setup>
import { Link } from "@inertiajs/vue3";
import { PageHeader, PageContent } from "crafter/Components";

defineProps({
    logs: { type: Object, required: true },
});

const formatDate = (iso) => {
    if (!iso) return "—";
    const d = new Date(iso);
    return d.toLocaleString("pl-PL", {
        year: "numeric", month: "2-digit", day: "2-digit",
        hour: "2-digit", minute: "2-digit",
    });
};
</script>
