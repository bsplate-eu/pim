<template>
    <PageHeader title="Integracje — eBay" />

    <PageContent fluid>
        <!-- Zakładki Integracji -->
        <div class="mb-5 border-b border-gray-200">
            <nav class="-mb-px flex gap-6">
                <Link
                    :href="route('crafter.connect.integrations.base.index')"
                    class="border-b-2 border-transparent px-1 py-3 text-sm font-medium text-gray-500 hover:text-gray-700 hover:border-gray-300"
                >
                    BaseLinker
                </Link>
                <span class="border-b-2 border-primary-500 px-1 py-3 text-sm font-medium text-primary-600">
                    Ebay
                </span>
                <Link
                    :href="route('crafter.connect.integrations.ksef.index')"
                    class="border-b-2 border-transparent px-1 py-3 text-sm font-medium text-gray-500 hover:text-gray-700 hover:border-gray-300"
                >
                    KSeF
                </Link>
            </nav>
        </div>

        <form @submit.prevent="save" class="space-y-6">
            <!-- Połączenie z API -->
            <Card>
                <CardHeader>
                    <h2 class="text-lg font-semibold">Połączenie z eBay (Browse API)</h2>
                    <p class="text-sm text-gray-500">
                        Klucze z
                        <a href="https://developer.ebay.com/my/keys" target="_blank" rel="noopener" class="text-primary-600 underline">
                            developer.ebay.com → Application Keys → Production
                        </a>. Cert ID jest szyfrowany w bazie.
                    </p>
                </CardHeader>
                <CardContent>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Etykieta</label>
                            <input type="text" v-model="form.label" maxlength="80"
                                class="block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 text-sm" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">App ID (Client ID)</label>
                            <input type="text" v-model="form.client_id" autocomplete="off"
                                placeholder="BSPBlack-PIM-PRD-..."
                                class="block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 text-sm font-mono" />
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Cert ID (Client Secret)</label>
                            <div class="flex gap-2">
                                <div class="flex-1 relative">
                                    <input :type="showSecret ? 'text' : 'password'" v-model="form.client_secret" autocomplete="off"
                                        :placeholder="settings?.has_secret ? (settings.masked_secret ?? '••••••••') : 'Wklej Cert ID'"
                                        class="block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 pr-20 text-sm font-mono" />
                                    <button type="button" @click="showSecret = !showSecret"
                                        class="absolute inset-y-0 right-0 px-3 text-xs text-gray-500 hover:text-gray-700">
                                        {{ showSecret ? 'Ukryj' : 'Pokaż' }}
                                    </button>
                                </div>
                                <Button type="button" variant="outline" color="gray" @click="testConnection" :loading="testing">
                                    Testuj połączenie
                                </Button>
                            </div>
                            <p class="mt-1 text-xs text-gray-500">
                                {{ settings?.has_secret ? 'Cert ID zapisany. Zostaw puste, aby nie nadpisywać.' : 'Cert ID zostanie zaszyfrowany w bazie.' }}
                            </p>
                            <div v-if="testResult"
                                :class="['mt-3 rounded-md p-3 text-sm', testResult.ok ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-red-50 text-red-700 border border-red-200']">
                                <span class="font-medium">{{ testResult.ok ? '✓ OK' : '✗ Błąd' }}</span> — {{ testResult.message }}
                                <span v-if="testResult.ok && testResult.total != null"> ({{ testResult.total }} ofert u sprzedawcy).</span>
                            </div>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <!-- Konto sprzedawcy — Sell API (OAuth user-token) -->
            <Card>
                <CardHeader>
                    <h2 class="text-lg font-semibold">Konto sprzedawcy — Sell API (własne oferty + ceny)</h2>
                    <p class="text-sm text-gray-500">
                        Autoryzacja Twojego konta eBay (OAuth user-token) — wymagana do pobierania własnych aukcji i zmiany cen
                        (to co innego niż Browse API powyżej, które tylko czyta oferty konkurencji). RuName utwórz w
                        <a href="https://developer.ebay.com/my/auth" target="_blank" rel="noopener" class="text-primary-600 underline">developer.ebay.com → User Tokens</a>.
                    </p>
                </CardHeader>
                <CardContent>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">RuName (redirect)</label>
                        <input type="text" v-model="form.ru_name" autocomplete="off" placeholder="Twoja-Nazwa-RuName"
                            class="block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 text-sm font-mono" />
                        <p class="mt-1 text-xs text-gray-500">
                            W ustawieniach RuName na eBay ustaw „Auth accepted URL" na:
                            <code class="bg-gray-100 px-1 rounded break-all">{{ callbackUrl }}</code>
                            — wymaga publicznego HTTPS (nie zadziała na localhost).
                        </p>
                    </div>
                    <div class="mt-4 flex items-center justify-between gap-3 flex-wrap">
                        <div class="text-sm">
                            <template v-if="settings?.oauth_connected">
                                <span class="font-medium text-green-700">✓ Konto połączone</span>
                                <span v-if="settings.ebay_user_id" class="text-gray-600"> ({{ settings.ebay_user_id }})</span>
                                <div class="text-xs text-gray-500">od {{ formatDate(settings.oauth_connected_at) }}</div>
                            </template>
                            <span v-else class="text-gray-500">Konto niepołączone — najpierw zapisz ustawienia, potem połącz.</span>
                        </div>
                        <div class="flex gap-2">
                            <a :href="route('crafter.connect.integrations.ebay.oauth.connect')"
                                class="inline-flex items-center rounded-md bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
                                {{ settings?.oauth_connected ? 'Połącz ponownie' : 'Połącz konto eBay' }}
                            </a>
                            <Button v-if="settings?.oauth_connected" type="button" variant="outline" color="red" @click="disconnect">
                                Rozłącz
                            </Button>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <!-- Monitorowany sprzedawca -->
            <Card>
                <CardHeader>
                    <h2 class="text-lg font-semibold">Monitorowany sprzedawca</h2>
                    <p class="text-sm text-gray-500">Te dane posłużą do raportu i cennika (budowane osobno).</p>
                </CardHeader>
                <CardContent>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Sprzedawca (seller ID)</label>
                            <input type="text" v-model="form.seller"
                                class="block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 text-sm" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Rynek</label>
                            <select v-model="form.marketplace"
                                class="block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 text-sm">
                                <option value="EBAY_DE">eBay.de (Niemcy)</option>
                                <option value="EBAY_PL">eBay.pl (Polska)</option>
                                <option value="EBAY_US">eBay.com (USA)</option>
                                <option value="EBAY_GB">eBay.co.uk (UK)</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Słowo kluczowe (q)</label>
                            <input type="text" v-model="form.keyword"
                                class="block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 text-sm" />
                        </div>
                    </div>
                    <div class="mt-4 flex items-center justify-between">
                        <div>
                            <div class="text-sm font-medium text-gray-900">Integracja aktywna</div>
                            <div class="text-xs text-gray-500">Połączenie z eBay API gotowe do użycia przez raport/cennik.</div>
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
import axios from "axios";
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

interface Settings {
    id: number;
    label: string;
    client_id: string | null;
    has_secret: boolean;
    masked_secret: string | null;
    seller: string;
    marketplace: string;
    keyword: string;
    enabled: boolean;
    last_sync_at: string | null;
    last_sync_count: number | null;
    ru_name: string | null;
    oauth_connected: boolean;
    oauth_connected_at: string | null;
    ebay_user_id: string | null;
}

interface Props {
    settings: Settings | null;
}

const props = defineProps<Props>();
const toast = useToast();

const form = reactive({
    label: props.settings?.label ?? "eBay",
    client_id: props.settings?.client_id ?? "",
    client_secret: "",
    seller: props.settings?.seller ?? "scutprotectionsrl",
    marketplace: props.settings?.marketplace ?? "EBAY_DE",
    keyword: props.settings?.keyword ?? "Unterfahrschutz",
    enabled: props.settings?.enabled ?? true,
    ru_name: props.settings?.ru_name ?? "",
});

const showSecret = ref(false);
const testing = ref(false);
const saving = ref(false);
const testResult = ref<{ ok: boolean; message: string; total?: number } | null>(null);

async function testConnection() {
    testing.value = true;
    testResult.value = null;
    try {
        const { data } = await axios.post(route("crafter.connect.integrations.ebay.test"), {
            client_id: form.client_id || null,
            client_secret: form.client_secret || null,
            seller: form.seller,
            marketplace: form.marketplace,
            keyword: form.keyword,
        });
        testResult.value = data;
    } catch (e: any) {
        testResult.value = { ok: false, message: e?.response?.data?.message ?? "Błąd żądania." };
    } finally {
        testing.value = false;
    }
}

function save() {
    saving.value = true;
    router.put(route("crafter.connect.integrations.ebay.update"), { ...form, client_secret: form.client_secret || null }, {
        preserveScroll: true,
        onFinish: () => { saving.value = false; form.client_secret = ""; },
        onSuccess: () => toast.success("Ustawienia eBay zapisane."),
        onError: (errors: Record<string, string>) => {
            const first = Object.values(errors)[0];
            if (first) toast.error(first);
        },
    });
}

const callbackUrl = computed(() => route("crafter.connect.integrations.ebay.oauth.callback"));

function formatDate(iso: string | null): string {
    if (!iso) return "—";
    return new Date(iso).toLocaleString("pl-PL", { dateStyle: "short", timeStyle: "short" });
}

function disconnect() {
    if (!window.confirm("Rozłączyć konto eBay? Trzeba będzie połączyć ponownie, by pobierać oferty / zmieniać ceny.")) return;
    router.delete(route("crafter.connect.integrations.ebay.oauth.disconnect"), {
        preserveScroll: true,
        onSuccess: () => toast.success("Konto eBay rozłączone."),
    });
}
</script>
