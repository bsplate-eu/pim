<template>
    <PageHeader title="Skrzynki">
        <template #subtitle>
            <span class="text-sm text-gray-500">Konta pocztowe wpięte do Argo Mail</span>
        </template>
        <div class="flex gap-2">
            <Link :href="route('crafter.argo-mail.index')">
                <Button variant="outline" color="gray">← Skrzynka</Button>
            </Link>
            <Link :href="route('crafter.argo-mail.accounts.create')">
                <Button :leftIcon="PlusIcon">Dodaj skrzynkę</Button>
            </Link>
        </div>
    </PageHeader>

    <PageContent fluid>
        <div
            v-if="accounts.length === 0"
            class="bg-white rounded-lg shadow-sm border border-gray-200 p-10 text-center"
        >
            <EnvelopeIcon class="mx-auto h-12 w-12 text-gray-300" />
            <h3 class="mt-4 text-base font-semibold text-gray-900">Brak wpiętych skrzynek</h3>
            <p class="mx-auto mt-1 max-w-md text-sm text-gray-500">
                Zacznij od wpięcia pierwszej skrzynki (Gmail i inne).
            </p>
            <Link :href="route('crafter.argo-mail.accounts.create')">
                <Button :leftIcon="PlusIcon" class="mt-5">Dodaj skrzynkę</Button>
            </Link>
        </div>

        <div v-else class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
            <div
                v-for="acc in accounts"
                :key="acc.id"
                class="bg-white rounded-lg shadow-sm border border-gray-200 p-4 flex flex-col"
            >
                <div class="flex items-center gap-3">
                    <span
                        class="inline-flex h-9 w-9 items-center justify-center rounded-full bg-gray-300"
                        :style="acc.color ? { backgroundColor: acc.color } : undefined"
                    >
                        <EnvelopeIcon class="h-5 w-5 text-white" />
                    </span>
                    <div class="min-w-0">
                        <p class="truncate text-sm font-semibold text-gray-900">{{ acc.label }}</p>
                        <p class="truncate text-xs text-gray-500">{{ acc.email }}</p>
                    </div>
                    <span v-if="!acc.is_active" class="ml-auto text-xs text-gray-400">nieaktywna</span>
                </div>

                <div class="mt-3 grid grid-cols-2 gap-2 text-center">
                    <div class="rounded-md bg-gray-50 py-2">
                        <div class="text-lg font-semibold text-gray-900">{{ acc.messages_count }}</div>
                        <div class="text-[11px] uppercase text-gray-400">maili</div>
                    </div>
                    <div class="rounded-md bg-gray-50 py-2">
                        <div class="text-lg font-semibold" :class="acc.unread_count > 0 ? 'text-blue-600' : 'text-gray-900'">
                            {{ acc.unread_count }}
                        </div>
                        <div class="text-[11px] uppercase text-gray-400">nieprzeczytane</div>
                    </div>
                </div>

                <div class="mt-3 flex items-center justify-between text-xs">
                    <span :class="statusClass(acc.sync_status)">{{ statusLabel(acc.sync_status) }}</span>
                    <span class="text-gray-400">
                        {{ acc.last_sync_at ? "Sync: " + formatDate(acc.last_sync_at) : "jeszcze nie synchronizowano" }}
                    </span>
                </div>
                <p v-if="acc.sync_status === 'error' && acc.sync_error" class="mt-1 text-xs text-red-600 truncate" :title="acc.sync_error">
                    {{ acc.sync_error }}
                </p>

                <div class="mt-4 pt-3 border-t border-gray-100 flex items-center gap-3 text-sm">
                    <Button
                        type="button"
                        variant="outline"
                        color="gray"
                        :leftIcon="ArrowPathIcon"
                        :loading="syncingId === acc.id"
                        @click="syncNow(acc)"
                    >
                        Synchronizuj
                    </Button>
                    <Link
                        :href="route('crafter.argo-mail.accounts.edit', acc.id)"
                        class="inline-flex items-center gap-1 text-primary-600 hover:text-primary-700"
                    >
                        <PencilSquareIcon class="h-4 w-4" /> Edytuj
                    </Link>
                    <button
                        type="button"
                        @click="destroy(acc)"
                        class="inline-flex items-center gap-1 text-red-600 hover:text-red-700 ml-auto"
                    >
                        <TrashIcon class="h-4 w-4" /> Usuń
                    </button>
                </div>
            </div>
        </div>
    </PageContent>
</template>

<script setup lang="ts">
import { ref } from "vue";
import { Link, router } from "@inertiajs/vue3";
import axios from "axios";
import { useToast } from "@brackets/vue-toastification";
import { PageHeader, PageContent, Button } from "crafter/Components";
import {
    EnvelopeIcon,
    PlusIcon,
    PencilSquareIcon,
    TrashIcon,
    ArrowPathIcon,
} from "@heroicons/vue/24/outline";

interface MailAccount {
    id: number;
    label: string;
    email: string;
    color: string | null;
    is_active: boolean;
    sync_status: string;
    sync_error: string | null;
    last_sync_at: string | null;
    messages_count: number;
    unread_count: number;
}

defineProps<{ accounts: MailAccount[] }>();

const toast = useToast();
const syncingId = ref<number | null>(null);

async function syncNow(acc: MailAccount) {
    syncingId.value = acc.id;
    try {
        const { data } = await axios.post(route("crafter.argo-mail.accounts.sync", acc.id));
        if (data.ok) {
            toast.success(`${acc.label}: pobrano ${data.fetched} (nowych ${data.new}).`);
        } else {
            toast.error(`${acc.label}: ${data.message ?? "błąd synchronizacji"}`);
        }
    } catch (e: any) {
        toast.error(e?.response?.data?.message ?? "Błąd synchronizacji.");
    } finally {
        syncingId.value = null;
        router.reload({ only: ["accounts"] });
    }
}

function destroy(acc: MailAccount) {
    if (!confirm(`Usunąć skrzynkę „${acc.label}"? Wszystkie pobrane maile tej skrzynki znikną z PIM.`)) {
        return;
    }
    router.delete(route("crafter.argo-mail.accounts.destroy", acc.id), {
        preserveScroll: true,
        onSuccess: () => toast.success("Skrzynka usunięta."),
    });
}

function statusLabel(status: string): string {
    const map: Record<string, string> = { idle: "Gotowe", syncing: "Synchronizacja…", error: "Błąd" };
    return map[status] ?? status;
}
function statusClass(status: string): string {
    const map: Record<string, string> = { idle: "text-gray-500", syncing: "text-blue-600", error: "text-red-600 font-medium" };
    return map[status] ?? "text-gray-500";
}
function formatDate(iso: string): string {
    return new Date(iso).toLocaleString("pl-PL", {
        day: "2-digit", month: "2-digit", hour: "2-digit", minute: "2-digit",
    });
}
</script>
