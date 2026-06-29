<template>
    <PageHeader title="Integracje — KSeF" />

    <PageContent fluid>
        <!-- Zakładki Integracji (spójne z eBay/BaseLinker) -->
        <div class="mb-5 border-b border-gray-200">
            <nav class="-mb-px flex gap-6">
                <Link
                    :href="route('crafter.connect.integrations.base.index')"
                    class="border-b-2 border-transparent px-1 py-3 text-sm font-medium text-gray-500 hover:text-gray-700 hover:border-gray-300"
                >
                    BaseLinker
                </Link>
                <Link
                    :href="route('crafter.connect.integrations.ebay.index')"
                    class="border-b-2 border-transparent px-1 py-3 text-sm font-medium text-gray-500 hover:text-gray-700 hover:border-gray-300"
                >
                    Ebay
                </Link>
                <span class="border-b-2 border-primary-500 px-1 py-3 text-sm font-medium text-primary-600">
                    KSeF
                </span>
            </nav>
        </div>

        <!-- Taby firm (po jednej na firmę) -->
        <div class="mb-5">
            <div class="inline-flex rounded-lg bg-gray-100 p-1">
                <button
                    v-for="c in companies"
                    :key="c.company"
                    type="button"
                    @click="activeCompany = c.company"
                    :class="[
                        'rounded-md px-4 py-1.5 text-sm font-medium transition-colors',
                        activeCompany === c.company
                            ? 'bg-white text-primary-700 shadow-sm'
                            : 'text-gray-500 hover:text-gray-700',
                    ]"
                >
                    {{ c.label }}
                </button>
            </div>
        </div>

        <form @submit.prevent="save" class="space-y-6">
            <Card>
                <CardHeader>
                    <h2 class="text-lg font-semibold">Połączenie z KSeF — {{ activeMeta?.label }}</h2>
                    <p class="text-sm text-gray-500">
                        Poświadczenia integracji z Krajowym Systemem e-Faktur dla firmy
                        <span class="font-medium">{{ activeMeta?.label }}</span>. Token jest szyfrowany w bazie.
                    </p>
                </CardHeader>
                <CardContent>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">NIP firmy</label>
                            <input type="text" v-model="form.nip" maxlength="32"
                                placeholder="np. 1234567890"
                                class="block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 text-sm font-mono" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Środowisko</label>
                            <select v-model="form.environment"
                                class="block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 text-sm">
                                <option value="test">Test (środowisko testowe KSeF)</option>
                                <option value="prod">Produkcja</option>
                            </select>
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Token autoryzacyjny KSeF</label>
                            <div class="relative">
                                <input :type="showToken ? 'text' : 'password'" v-model="form.auth_token" autocomplete="off"
                                    :placeholder="activeMeta?.has_token ? (activeMeta.masked_token ?? '••••••••') : 'Wklej token KSeF'"
                                    class="block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 pr-20 text-sm font-mono" />
                                <button type="button" @click="showToken = !showToken"
                                    class="absolute inset-y-0 right-0 px-3 text-xs text-gray-500 hover:text-gray-700">
                                    {{ showToken ? 'Ukryj' : 'Pokaż' }}
                                </button>
                            </div>
                            <p class="mt-1 text-xs text-gray-500">
                                {{ activeMeta?.has_token ? 'Token zapisany. Zostaw puste, aby nie nadpisywać.' : 'Token zostanie zaszyfrowany w bazie.' }}
                            </p>
                        </div>
                    </div>

                    <div class="mt-4 flex items-center justify-between">
                        <div>
                            <div class="text-sm font-medium text-gray-900">Integracja aktywna</div>
                            <div class="text-xs text-gray-500">Połączenie KSeF gotowe do użycia dla tej firmy.</div>
                        </div>
                        <Toggle v-model="form.enabled" />
                    </div>
                </CardContent>
                <CardFooter>
                    <div class="flex justify-end">
                        <Button type="submit" :loading="saving">Zapisz ustawienia</Button>
                    </div>
                </CardFooter>
            </Card>
        </form>
    </PageContent>
</template>

<script setup lang="ts">
import { computed, reactive, ref } from "vue";
import { Link, router } from "@inertiajs/vue3";
import { useToast } from "@brackets/vue-toastification";
import {
    PageHeader,
    PageContent,
    Button,
    Card,
    CardHeader,
    CardContent,
    CardFooter,
    Toggle,
} from "crafter/Components";

interface Company {
    company: string;
    label: string;
    nip: string | null;
    environment: string;
    has_token: boolean;
    masked_token: string | null;
    enabled: boolean;
    last_sync_at: string | null;
}

interface Props {
    companies: Company[];
}

const props = defineProps<Props>();
const toast = useToast();

const activeCompany = ref<string>(props.companies[0]?.company ?? "pareto");
const showToken = ref(false);
const saving = ref(false);

// Stan formularza per firma — token zawsze pusty (nie nadpisujemy, jeśli puste).
const forms = reactive<Record<string, { nip: string; environment: string; auth_token: string; enabled: boolean }>>(
    Object.fromEntries(
        props.companies.map((c) => [
            c.company,
            {
                nip: c.nip ?? "",
                environment: c.environment ?? "test",
                auth_token: "",
                enabled: c.enabled ?? false,
            },
        ])
    )
);

const form = computed(() => forms[activeCompany.value]);
const activeMeta = computed(() => props.companies.find((c) => c.company === activeCompany.value));

function save() {
    saving.value = true;
    const company = activeCompany.value;
    router.put(
        route("crafter.connect.integrations.ksef.update"),
        { company, ...forms[company], auth_token: forms[company].auth_token || null },
        {
            preserveScroll: true,
            onFinish: () => {
                saving.value = false;
                forms[company].auth_token = "";
                showToken.value = false;
            },
            onSuccess: () => toast.success(`Ustawienia KSeF (${activeMeta.value?.label}) zapisane.`),
            onError: (errors: Record<string, string>) => {
                const first = Object.values(errors)[0];
                if (first) toast.error(first);
            },
        }
    );
}
</script>
