<template>
    <PageHeader sticky title="Szablony maili" />

    <PageContent fluid>
        <div class="p-4">
            <div class="bg-white rounded-lg border border-slate-200 overflow-hidden">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="px-4 py-2 text-left font-medium text-slate-600">Nazwa</th>
                            <th class="px-4 py-2 text-left font-medium text-slate-600">Klucz</th>
                            <th class="px-4 py-2 text-left font-medium text-slate-600">Temat</th>
                            <th class="px-4 py-2 text-left font-medium text-slate-600">Język</th>
                            <th class="px-4 py-2 text-left font-medium text-slate-600">Aktywny</th>
                            <th class="px-4 py-2 text-right font-medium text-slate-600">Akcje</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        <tr v-if="!templates.length">
                            <td colspan="6" class="px-4 py-8 text-center text-slate-400">
                                Brak szablonów.
                            </td>
                        </tr>
                        <tr v-for="t in templates" :key="t.id" class="hover:bg-slate-50">
                            <td class="px-4 py-2 font-medium">{{ t.name }}</td>
                            <td class="px-4 py-2 font-mono text-xs text-slate-500">{{ t.key }}</td>
                            <td class="px-4 py-2 text-slate-700">{{ t.subject }}</td>
                            <td class="px-4 py-2 uppercase text-xs">{{ t.lang }}</td>
                            <td class="px-4 py-2">
                                <span
                                    :class="t.is_active ? 'bg-green-100 text-green-700' : 'bg-slate-100 text-slate-500'"
                                    class="inline-block px-2 py-0.5 rounded text-xs"
                                >
                                    {{ t.is_active ? 'TAK' : 'NIE' }}
                                </span>
                            </td>
                            <td class="px-4 py-2 text-right">
                                <Link :href="route('crafter.mail.templates.edit', t.id)" class="text-primary-600 hover:underline">
                                    Edytuj
                                </Link>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </PageContent>
</template>

<script setup>
import { Link } from "@inertiajs/vue3";
import { PageHeader, PageContent } from "crafter/Components";

defineProps({
    templates: { type: Array, required: true },
});
</script>
