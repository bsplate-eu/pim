<template>
    <PageHeader title="Administrator — AI poczty">
        <template #subtitle>
            <span class="text-sm text-gray-500">
                Automatyczne zarządzanie mailami: sortowanie, antyspam, przypisania — napędzane AI
            </span>
        </template>
        <div class="flex gap-2">
            <Link :href="route('crafter.argo-mail.index')">
                <Button variant="outline" color="gray" :leftIcon="EnvelopeIcon">Otwórz skrzynkę</Button>
            </Link>
        </div>
    </PageHeader>

    <PageContent fluid>
        <!-- Status -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
                <div class="text-xs uppercase tracking-wide text-gray-400">Skrzynki</div>
                <div class="mt-1 text-2xl font-semibold text-gray-900">{{ stats.accounts }}</div>
            </div>
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
                <div class="text-xs uppercase tracking-wide text-gray-400">Maile w PIM</div>
                <div class="mt-1 text-2xl font-semibold text-gray-900">{{ stats.messages }}</div>
            </div>
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
                <div class="text-xs uppercase tracking-wide text-gray-400">Nieprzeczytane</div>
                <div class="mt-1 text-2xl font-semibold" :class="stats.unread > 0 ? 'text-blue-600' : 'text-gray-900'">{{ stats.unread }}</div>
            </div>
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
                <div class="text-xs uppercase tracking-wide text-gray-400">Do skategoryzowania</div>
                <div class="mt-1 text-2xl font-semibold" :class="stats.uncategorized > 0 ? 'text-amber-600' : 'text-gray-900'">{{ stats.uncategorized }}</div>
            </div>
        </div>

        <!-- Auto-kategoryzacja (DZIAŁA) -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-5 mb-4">
            <div class="flex items-center gap-2 mb-1">
                <TagIcon class="h-5 w-5 text-primary-500" />
                <h2 class="text-lg font-semibold text-gray-900">Auto-kategoryzacja</h2>
                <span class="ml-2 inline-flex items-center rounded-full bg-green-100 px-2 py-0.5 text-[11px] font-medium text-green-700">aktywne</span>
            </div>
            <p class="text-sm text-gray-500 mb-4">
                AI przypisuje maile do jednej z poniższych kategorii (po nadawcy i treści). Działa na nieskategoryzowanych mailach.
            </p>

            <!-- Kategorie -->
            <div class="flex flex-wrap gap-2 mb-4">
                <span
                    v-for="c in categories"
                    :key="c.id"
                    class="inline-flex items-center gap-1.5 rounded-full border border-gray-200 bg-gray-50 pl-2 pr-1 py-1 text-sm"
                >
                    <span class="h-2.5 w-2.5 rounded-full" :style="{ backgroundColor: c.color }"></span>
                    {{ c.name }}
                    <span class="text-xs text-gray-400">{{ c.messages_count }}</span>
                    <button type="button" @click="deleteCategory(c)" class="ml-0.5 rounded-full p-0.5 text-gray-400 hover:bg-gray-200 hover:text-red-600" title="Usuń kategorię">
                        <XMarkIcon class="h-3.5 w-3.5" />
                    </button>
                </span>
            </div>

            <!-- Dodaj kategorię + uruchom -->
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="block text-xs font-medium text-gray-500 mb-1">Nowa kategoria</label>
                    <div class="flex items-center gap-2">
                        <input v-model="newCat.color" type="color" class="h-[38px] w-12 rounded-md border border-gray-300 p-1" />
                        <input
                            v-model="newCat.name"
                            type="text"
                            maxlength="60"
                            placeholder="np. Wsparcie techniczne"
                            class="block w-56 rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 text-sm"
                            @keyup.enter="addCategory"
                        />
                        <Button type="button" variant="outline" color="gray" :leftIcon="PlusIcon" @click="addCategory">Dodaj</Button>
                    </div>
                </div>

                <div class="ml-auto flex items-center gap-3">
                    <span v-if="lastResult" class="text-xs text-gray-500">
                        Ostatnio: skategoryzowano {{ lastResult.categorized }} z {{ lastResult.processed }}
                    </span>
                    <Button
                        type="button"
                        :leftIcon="SparklesIcon"
                        :loading="categorizing"
                        :disabled="stats.uncategorized === 0"
                        @click="runCategorize"
                    >
                        Kategoryzuj AI ({{ Math.min(stats.uncategorized, 25) }})
                    </Button>
                </div>
            </div>
            <p class="mt-2 text-xs text-gray-400">
                Jedno kliknięcie przetwarza do 25 maili (oszczędza koszt AI). Klikaj ponownie, by dokończyć resztę.
            </p>
        </div>

        <!-- Pozostałe funkcje (w przygotowaniu) -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-5">
            <h2 class="text-lg font-semibold text-gray-900 mb-1">Kolejne funkcje automatyzacji</h2>
            <p class="text-sm text-gray-500 mb-4">Dorabiamy po kolei.</p>
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                <div v-for="f in features" :key="f.title" class="rounded-lg border border-gray-200 p-4 flex flex-col">
                    <div class="flex items-center gap-2">
                        <component :is="f.icon" class="h-5 w-5 text-gray-400" />
                        <span class="text-sm font-semibold text-gray-900">{{ f.title }}</span>
                    </div>
                    <p class="mt-1 text-xs text-gray-500 flex-1">{{ f.desc }}</p>
                    <span class="mt-3 inline-flex w-fit items-center rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-medium text-gray-500">wkrótce</span>
                </div>
            </div>
        </div>
    </PageContent>
</template>

<script setup lang="ts">
import { reactive, ref } from "vue";
import { Link, router } from "@inertiajs/vue3";
import axios from "axios";
import { useToast } from "@brackets/vue-toastification";
import { PageHeader, PageContent, Button } from "crafter/Components";
import {
    EnvelopeIcon,
    SparklesIcon,
    TagIcon,
    ShieldExclamationIcon,
    UserPlusIcon,
    FunnelIcon,
    DocumentTextIcon,
    ChatBubbleLeftRightIcon,
    BellAlertIcon,
    PlusIcon,
    XMarkIcon,
} from "@heroicons/vue/24/outline";

const props = defineProps<{
    accounts: Array<{ id: number; label: string; email: string; color: string | null; is_active: boolean }>;
    categories: Array<{ id: number; name: string; color: string; is_system: boolean; messages_count: number }>;
    stats: { accounts: number; messages: number; unread: number; uncategorized: number };
}>();

const toast = useToast();
const categorizing = ref(false);
const lastResult = ref<{ processed: number; categorized: number } | null>(null);
const newCat = reactive({ name: "", color: "#2563eb" });

async function runCategorize() {
    categorizing.value = true;
    try {
        const { data } = await axios.post(route("crafter.ai-tools.mail.categorize"), { limit: 25 });
        if (data.ok) {
            toast.success(`Skategoryzowano ${data.categorized} z ${data.processed}.`);
            lastResult.value = data;
        } else {
            toast.error(data.message ?? "Błąd kategoryzacji.");
        }
    } catch (e: any) {
        toast.error(e?.response?.data?.message ?? "Błąd kategoryzacji.");
    } finally {
        categorizing.value = false;
        router.reload({ only: ["categories", "stats"] });
    }
}

function addCategory() {
    if (!newCat.name.trim()) return;
    router.post(
        route("crafter.ai-tools.mail.categories.store"),
        { name: newCat.name.trim(), color: newCat.color },
        {
            preserveScroll: true,
            onSuccess: () => {
                toast.success("Kategoria dodana.");
                newCat.name = "";
            },
            onError: (errors: Record<string, string>) => toast.error(Object.values(errors)[0] ?? "Błąd."),
        }
    );
}

function deleteCategory(c: { id: number; name: string }) {
    if (!confirm(`Usunąć kategorię „${c.name}"? Maile stracą to oznaczenie.`)) return;
    router.delete(route("crafter.ai-tools.mail.categories.destroy", c.id), {
        preserveScroll: true,
        onSuccess: () => toast.success("Kategoria usunięta."),
    });
}

const features = [
    { title: "Wykrywanie spamu", desc: "Model + reguły domen oznaczają spam i odsiewają śmieci.", icon: ShieldExclamationIcon },
    { title: "Auto-przypisanie do osób", desc: "Mail trafia do właściwej osoby na podstawie treści i nadawcy.", icon: UserPlusIcon },
    { title: "Reguły i filtry", desc: "Warunki → akcje: kategoria, przypisanie, oznaczenie, przeniesienie.", icon: FunnelIcon },
    { title: "Podsumowania wątków", desc: "Krótkie streszczenie długiej konwersacji jednym kliknięciem.", icon: DocumentTextIcon },
    { title: "Sugestie odpowiedzi", desc: "AI proponuje gotowy szkic odpowiedzi do akceptacji.", icon: ChatBubbleLeftRightIcon },
    { title: "Remindery o nieodczytanych", desc: "Przypomnienie, gdy mail czeka zbyt długo bez reakcji.", icon: BellAlertIcon },
];
</script>
