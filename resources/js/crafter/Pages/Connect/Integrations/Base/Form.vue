<template>
    <PageHeader :title="base ? `Base — ${base.label}` : 'Nowy Base'">
        <div class="flex gap-2">
            <Link :href="route('crafter.connect.integrations.base.index')">
                <Button variant="outline" color="gray">← Wróć do listy</Button>
            </Link>
            <Button
                v-if="base"
                :leftIcon="ArrowPathIcon"
                color="primary"
                @click="triggerSync"
                :loading="syncing"
                :disabled="!base.has_api_key"
            >
                Uruchom sync teraz
            </Button>
        </div>
    </PageHeader>

    <PageContent>
        <form @submit.prevent="save" class="space-y-6">
            <!-- Karta: Identyfikacja -->
            <Card>
                <CardHeader>
                    <h2 class="text-lg font-semibold">Identyfikacja</h2>
                    <p class="text-sm text-gray-500">
                        Etykieta jest używana do filtrowania zamówień i klientów.
                    </p>
                </CardHeader>
                <CardContent>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Etykieta <span class="text-red-500">*</span>
                        </label>
                        <input
                            type="text"
                            v-model="form.label"
                            placeholder="np. Argo, Odyssey"
                            class="block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 text-sm"
                            maxlength="80"
                            required
                        />
                        <p class="mt-1 text-xs text-gray-500">
                            Musi być unikatowa (max 80 znaków).
                        </p>
                    </div>
                </CardContent>
            </Card>

            <!-- Karta: Połączenie z API -->
            <Card>
                <CardHeader>
                    <h2 class="text-lg font-semibold">Połączenie z BaseLinker</h2>
                    <p class="text-sm text-gray-500">
                        Klucz API znajdziesz w
                        <a
                            href="https://panel-f.baselinker.com/profile.php#api"
                            target="_blank"
                            rel="noopener"
                            class="text-primary-600 underline"
                        >
                            panelu BaseLinker → Moje konto → API
                        </a>.
                    </p>
                </CardHeader>

                <CardContent>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Klucz API
                        </label>
                        <div class="flex gap-2">
                            <div class="flex-1 relative">
                                <input
                                    :type="showKey ? 'text' : 'password'"
                                    v-model="form.api_key"
                                    :placeholder="base?.has_api_key ? (base.masked_api_key ?? '••••••••') : 'Wklej klucz API BaseLinker'"
                                    class="block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 pr-20 text-sm"
                                    autocomplete="off"
                                />
                                <button
                                    type="button"
                                    @click="showKey = !showKey"
                                    class="absolute inset-y-0 right-0 px-3 text-xs text-gray-500 hover:text-gray-700"
                                >
                                    {{ showKey ? 'Ukryj' : 'Pokaż' }}
                                </button>
                            </div>
                            <Button
                                type="button"
                                variant="outline"
                                color="gray"
                                @click="testConnection"
                                :loading="testing"
                            >
                                Testuj połączenie
                            </Button>
                        </div>
                        <p class="mt-1 text-xs text-gray-500">
                            {{ base?.has_api_key
                                ? 'Klucz jest zapisany. Pozostaw pole puste aby nie nadpisywać.'
                                : 'Klucz API zostanie zaszyfrowany w bazie.' }}
                        </p>

                        <div
                            v-if="testResult"
                            :class="[
                                'mt-3 rounded-md p-3 text-sm',
                                testResult.ok
                                    ? 'bg-green-50 text-green-700 border border-green-200'
                                    : 'bg-red-50 text-red-700 border border-red-200',
                            ]"
                        >
                            <span class="font-medium">{{ testResult.ok ? '✓ OK' : '✗ Błąd' }}</span>
                            — {{ testResult.message }}
                            <span v-if="testResult.ok && testResult.statuses_count != null">
                                (pobrano {{ testResult.statuses_count }} statusów).
                            </span>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <!-- Karta: Synchronizacja -->
            <Card>
                <CardHeader>
                    <h2 class="text-lg font-semibold">Synchronizacja zamówień</h2>
                </CardHeader>

                <CardContent>
                    <div class="space-y-5">
                        <div class="flex items-center justify-between">
                            <div>
                                <div class="text-sm font-medium text-gray-900">Automatyczne pobieranie zamówień</div>
                                <div class="text-xs text-gray-500">
                                    Scheduler co {{ form.sync_interval_minutes }} min pobiera nowe zamówienia.
                                </div>
                            </div>
                            <Toggle v-model="form.enabled" />
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">
                                    Interwał (minuty)
                                </label>
                                <select
                                    v-model.number="form.sync_interval_minutes"
                                    class="block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 text-sm"
                                >
                                    <option :value="5">5 minut</option>
                                    <option :value="15">15 minut</option>
                                    <option :value="30">30 minut</option>
                                    <option :value="60">60 minut (1h)</option>
                                    <option :value="180">3 godziny</option>
                                    <option :value="360">6 godzin</option>
                                </select>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">
                                    Pobieraj zamówienia od daty
                                </label>
                                <input
                                    type="date"
                                    v-model="form.sync_from_date"
                                    class="block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 text-sm"
                                />
                                <p class="mt-1 text-xs text-gray-500">
                                    Domyślnie: ostatnie 30 dni. Używane tylko przy pierwszym syncu.
                                </p>
                            </div>
                        </div>

                        <!-- Filtr daty BaseLinker -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                Filtr daty w BaseLinker
                            </label>
                            <div class="space-y-2">
                                <label class="flex items-start gap-2 cursor-pointer">
                                    <input
                                        type="radio"
                                        v-model="form.date_filter_type"
                                        value="date_add"
                                        class="mt-1 text-primary-600 focus:ring-primary-500"
                                    />
                                    <span class="text-sm">
                                        <span class="font-medium">Data dodania zamówienia</span> (zalecane)
                                        <span class="block text-xs text-gray-500">
                                            Łapie wszystkie zamówienia złożone od podanej daty — także niepotwierdzone i te, których status zmieniono wstecznie.
                                        </span>
                                    </span>
                                </label>
                                <label class="flex items-start gap-2 cursor-pointer">
                                    <input
                                        type="radio"
                                        v-model="form.date_filter_type"
                                        value="date_confirmed"
                                        class="mt-1 text-primary-600 focus:ring-primary-500"
                                    />
                                    <span class="text-sm">
                                        <span class="font-medium">Data potwierdzenia zamówienia</span>
                                        <span class="block text-xs text-gray-500">
                                            Tylko zamówienia potwierdzone przez klienta od podanej daty. Pomija niepotwierdzone.
                                        </span>
                                    </span>
                                </label>
                            </div>
                        </div>

                        <!-- Co zaciągać -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                Zakres pobieranych zamówień
                            </label>
                            <div class="space-y-2">
                                <label class="flex items-start gap-2 cursor-pointer">
                                    <input
                                        type="checkbox"
                                        v-model="form.include_unconfirmed"
                                        class="mt-1 rounded text-primary-600 focus:ring-primary-500"
                                    />
                                    <span class="text-sm">
                                        <span class="font-medium">Pobieraj niepotwierdzone zamówienia</span>
                                        <span class="block text-xs text-gray-500">
                                            Zamówienia ze statusu „Nowe zamówienia" (jeszcze niepotwierdzone przez klienta).
                                        </span>
                                    </span>
                                </label>
                                <label class="flex items-start gap-2 cursor-pointer">
                                    <input
                                        type="checkbox"
                                        v-model="form.include_archive"
                                        class="mt-1 rounded text-primary-600 focus:ring-primary-500"
                                    />
                                    <span class="text-sm">
                                        <span class="font-medium">Pobieraj zamówienia z archiwum BaseLinker</span>
                                        <span class="block text-xs text-gray-500">
                                            Domyślnie BL nie zwraca zarchiwizowanych — włącz jeśli archiwizujesz stare zamówienia, ale chcesz je mieć w PIM.
                                        </span>
                                    </span>
                                </label>
                            </div>
                        </div>
                    </div>
                </CardContent>

                <CardFooter>
                    <div class="flex justify-end">
                        <Button type="submit" :loading="saving">
                            {{ base ? 'Zapisz ustawienia' : 'Utwórz Base' }}
                        </Button>
                    </div>
                </CardFooter>
            </Card>

            <!-- Karta: Statystyki — tylko przy edycji -->
            <Card v-if="base && stats">
                <CardHeader>
                    <h2 class="text-lg font-semibold">Statystyki</h2>
                </CardHeader>
                <CardContent>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
                        <div class="rounded-lg border border-gray-200 p-4">
                            <div class="text-xs text-gray-500 uppercase">Zamówień w tym Base</div>
                            <div class="mt-1 text-2xl font-semibold">{{ stats.total_orders }}</div>
                        </div>
                        <div class="rounded-lg border border-gray-200 p-4">
                            <div class="text-xs text-gray-500 uppercase">Ostatnia synchronizacja</div>
                            <div class="mt-1 text-sm font-medium">
                                {{ stats.last_sync_at ? formatDate(stats.last_sync_at) : '—' }}
                            </div>
                        </div>
                        <div class="rounded-lg border border-gray-200 p-4">
                            <div class="text-xs text-gray-500 uppercase">Ostatni ID zamówienia</div>
                            <div class="mt-1 text-sm font-medium">
                                {{ stats.last_sync_order_id ?? '—' }}
                            </div>
                        </div>
                    </div>

                    <div>
                        <h3 class="text-sm font-medium text-gray-700 mb-2">Ostatnie 10 synchronizacji</h3>
                        <div v-if="recentLogs.length === 0" class="text-sm text-gray-500">
                            Brak synchronizacji.
                        </div>
                        <div v-else class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 text-sm">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Data</th>
                                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Typ</th>
                                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                        <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Pobrane</th>
                                        <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Nowe</th>
                                        <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Update</th>
                                        <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Czas</th>
                                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Błąd</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 bg-white">
                                    <tr v-for="log in recentLogs" :key="log.id">
                                        <td class="px-3 py-2 whitespace-nowrap text-xs">{{ formatDate(log.started_at) }}</td>
                                        <td class="px-3 py-2 text-xs">{{ log.trigger }}</td>
                                        <td class="px-3 py-2">
                                            <span
                                                :class="[
                                                    'px-2 py-0.5 rounded-full text-xs font-medium',
                                                    log.status === 'success' ? 'bg-green-100 text-green-700' :
                                                    log.status === 'error' ? 'bg-red-100 text-red-700' :
                                                    'bg-yellow-100 text-yellow-700',
                                                ]"
                                            >
                                                {{ log.status }}
                                            </span>
                                        </td>
                                        <td class="px-3 py-2 text-right">{{ log.orders_fetched }}</td>
                                        <td class="px-3 py-2 text-right text-green-600 font-medium">+{{ log.orders_new }}</td>
                                        <td class="px-3 py-2 text-right text-blue-600">~{{ log.orders_updated }}</td>
                                        <td class="px-3 py-2 text-right text-xs">{{ log.duration_seconds ?? '—' }}s</td>
                                        <td class="px-3 py-2 text-xs text-red-600 max-w-xs truncate" :title="log.error_message">
                                            {{ log.error_message || '—' }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </CardContent>
            </Card>
        </form>
    </PageContent>
</template>

<script setup lang="ts">
import { ref, reactive } from "vue";
import { Link, router } from "@inertiajs/vue3";
import axios from "axios";
import { ArrowPathIcon } from "@heroicons/vue/24/outline";
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

interface BaseData {
    id: number;
    label: string;
    enabled: boolean;
    has_api_key: boolean;
    masked_api_key: string | null;
    sync_from_date: string | null;
    date_filter_type: "date_add" | "date_confirmed";
    include_archive: boolean;
    include_unconfirmed: boolean;
    sync_interval_minutes: number;
}

interface Stats {
    total_orders: number;
    last_sync_at: string | null;
    last_sync_order_id: number | null;
}

interface SyncLog {
    id: number;
    trigger: string;
    status: string;
    orders_fetched: number;
    orders_new: number;
    orders_updated: number;
    error_message: string | null;
    started_at: string | null;
    finished_at: string | null;
    duration_seconds: number | null;
}

interface Props {
    base: BaseData | null;
    stats: Stats | null;
    recentLogs: SyncLog[];
}

const props = defineProps<Props>();
const toast = useToast();

const form = reactive({
    label: props.base?.label ?? "",
    api_key: "",
    enabled: props.base?.enabled ?? false,
    sync_from_date: props.base?.sync_from_date ?? null,
    date_filter_type: (props.base?.date_filter_type ?? "date_add") as "date_add" | "date_confirmed",
    include_archive: props.base?.include_archive ?? false,
    include_unconfirmed: props.base?.include_unconfirmed ?? true,
    sync_interval_minutes: props.base?.sync_interval_minutes ?? 15,
});

const showKey = ref(false);
const testing = ref(false);
const saving = ref(false);
const syncing = ref(false);
const testResult = ref<{ ok: boolean; message: string; statuses_count?: number } | null>(null);

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

async function testConnection() {
    testing.value = true;
    testResult.value = null;
    try {
        const url = props.base
            ? route("crafter.connect.integrations.base.test.existing", props.base.id)
            : route("crafter.connect.integrations.base.test");
        const { data } = await axios.post(url, { api_key: form.api_key || null });
        testResult.value = data;
    } catch (e: any) {
        testResult.value = {
            ok: false,
            message: e?.response?.data?.message ?? "Błąd żądania.",
        };
    } finally {
        testing.value = false;
    }
}

function save() {
    saving.value = true;
    const payload = {
        label: form.label,
        api_key: form.api_key || null,
        enabled: form.enabled,
        sync_from_date: form.sync_from_date,
        date_filter_type: form.date_filter_type,
        include_archive: form.include_archive,
        include_unconfirmed: form.include_unconfirmed,
        sync_interval_minutes: form.sync_interval_minutes,
    };

    const common = {
        preserveScroll: true,
        onFinish: () => {
            saving.value = false;
            form.api_key = "";
        },
        onSuccess: () => toast.success(props.base ? "Ustawienia zapisane." : "Base utworzony."),
        onError: (errors: Record<string, string>) => {
            const first = Object.values(errors)[0];
            if (first) toast.error(first);
        },
    };

    if (props.base) {
        router.put(route("crafter.connect.integrations.base.update", props.base.id), payload, common);
    } else {
        router.post(route("crafter.connect.integrations.base.store"), payload, common);
    }
}

async function triggerSync() {
    if (!props.base) return;
    syncing.value = true;
    try {
        const { data } = await axios.post(
            route("crafter.connect.integrations.base.sync", props.base.id)
        );
        if (data.ok) {
            toast.success(
                `Sync OK: pobrano ${data.log.orders_fetched}, nowych ${data.log.orders_new}, zmienionych ${data.log.orders_updated}`
            );
            router.reload({ only: ["stats", "recentLogs", "base"] });
        } else {
            toast.error(`Sync nie powiódł się: ${data.log?.error_message ?? "błąd"}`);
        }
    } catch (e: any) {
        toast.error(e?.response?.data?.message ?? "Błąd wywołania sync.");
    } finally {
        syncing.value = false;
    }
}
</script>
