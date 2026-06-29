<template>
    <PageHeader :title="account ? `Skrzynka — ${account.label}` : 'Dodaj skrzynkę'">
        <div class="flex gap-2">
            <Link :href="route('crafter.argo-mail.accounts.index')">
                <Button variant="outline" color="gray">← Wróć do listy</Button>
            </Link>
        </div>
    </PageHeader>

    <PageContent>
        <form @submit.prevent="save" class="space-y-6 w-full">
            <!-- Identyfikacja -->
            <Card>
                <CardHeader>
                    <h2 class="text-lg font-semibold">Skrzynka</h2>
                    <p class="text-sm text-gray-500">Podstawowe dane skrzynki.</p>
                </CardHeader>
                <CardContent>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label :class="labelClass">Nazwa / etykieta <span class="text-red-500">*</span></label>
                            <input v-model="form.label" type="text" maxlength="120" required :class="inputClass" placeholder="np. Biuro, Reklamacje" />
                        </div>
                        <div>
                            <label :class="labelClass">Adres e-mail <span class="text-red-500">*</span></label>
                            <input v-model="form.email" type="email" required :class="inputClass" placeholder="kontakt@firma.pl" />
                        </div>
                        <div>
                            <label :class="labelClass">Kolor zakładki</label>
                            <input v-model="form.color" type="color" class="h-[38px] w-16 rounded-md border border-gray-300 p-1" />
                        </div>
                    </div>
                </CardContent>
            </Card>

            <!-- Serwery -->
            <Card>
                <CardHeader>
                    <h2 class="text-lg font-semibold">Serwery (IMAP + SMTP)</h2>
                    <p class="text-sm text-gray-500">Wybierz dostawcę — ustawienia wypełnią się same.</p>
                </CardHeader>
                <CardContent>
                    <div class="mb-4">
                        <label :class="labelClass">Dostawca</label>
                        <select v-model="provider" @change="applyProvider" :class="[inputClass, 'md:w-1/2']">
                            <option value="gmail">Gmail / Google Workspace</option>
                            <option value="other">Inny (ustawienia ręczne)</option>
                        </select>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="space-y-3">
                            <div class="text-xs font-semibold uppercase tracking-wide text-gray-400">IMAP — odbiór</div>
                            <input v-model="form.imap_host" :readonly="lockServers" :class="[inputClass, lockServers && 'bg-gray-50']" placeholder="imap.serwer.pl" />
                            <div class="flex gap-2">
                                <input v-model.number="form.imap_port" :readonly="lockServers" type="number" :class="[inputClass, 'w-28', lockServers && 'bg-gray-50']" />
                                <select v-model="form.imap_encryption" :disabled="lockServers" :class="[inputClass, 'flex-1']">
                                    <option value="ssl">SSL</option>
                                    <option value="starttls">STARTTLS</option>
                                    <option :value="null">Brak</option>
                                </select>
                            </div>
                        </div>
                        <div class="space-y-3">
                            <div class="text-xs font-semibold uppercase tracking-wide text-gray-400">SMTP — wysyłka</div>
                            <input v-model="form.smtp_host" :readonly="lockServers" :class="[inputClass, lockServers && 'bg-gray-50']" placeholder="smtp.serwer.pl" />
                            <div class="flex gap-2">
                                <input v-model.number="form.smtp_port" :readonly="lockServers" type="number" :class="[inputClass, 'w-28', lockServers && 'bg-gray-50']" />
                                <select v-model="form.smtp_encryption" :disabled="lockServers" :class="[inputClass, 'flex-1']">
                                    <option value="ssl">SSL</option>
                                    <option value="starttls">STARTTLS</option>
                                    <option :value="null">Brak</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <!-- Logowanie + test -->
            <Card>
                <CardHeader>
                    <h2 class="text-lg font-semibold">Logowanie</h2>
                    <p class="text-sm text-gray-500">
                        Dla Gmaila użyj <strong>hasła aplikacji</strong> (16 znaków), nie zwykłego hasła do konta.
                        <a href="https://myaccount.google.com/apppasswords" target="_blank" rel="noopener" class="text-primary-600 underline">Wygeneruj hasło aplikacji →</a>
                    </p>
                </CardHeader>
                <CardContent>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label :class="labelClass">Login (zwykle = e-mail)</label>
                            <input v-model="form.username" :class="inputClass" :placeholder="form.email || 'login'" autocomplete="off" />
                        </div>
                        <div>
                            <label :class="labelClass">Hasło aplikacji <span v-if="!account" class="text-red-500">*</span></label>
                            <div class="relative">
                                <input
                                    :type="showPass ? 'text' : 'password'"
                                    v-model="form.password"
                                    :class="[inputClass, 'pr-16']"
                                    autocomplete="off"
                                    :placeholder="account?.has_password ? '•••••••• (zapisane)' : 'Wklej hasło aplikacji'"
                                />
                                <button type="button" @click="showPass = !showPass" class="absolute inset-y-0 right-0 px-3 text-xs text-gray-500 hover:text-gray-700">
                                    {{ showPass ? 'Ukryj' : 'Pokaż' }}
                                </button>
                            </div>
                            <p class="mt-1 text-xs text-gray-500">
                                {{ account?.has_password ? 'Hasło jest zapisane. Zostaw puste, by nie zmieniać.' : 'Hasło zostanie zaszyfrowane w bazie.' }}
                            </p>
                        </div>
                    </div>

                    <div class="mt-4">
                        <Button type="button" variant="outline" color="gray" @click="testConnection" :loading="testing">
                            Testuj połączenie
                        </Button>
                    </div>

                    <div v-if="testResult" class="mt-3 space-y-2">
                        <div :class="boxClass(testResult.ok)">
                            <span class="font-medium">{{ testResult.ok ? '✓' : '✗' }}</span> {{ testResult.message }}
                        </div>
                        <div v-if="testResult.imap" :class="boxClass(testResult.imap.ok)">
                            <span class="font-medium">IMAP:</span> {{ testResult.imap.message }}
                        </div>
                        <div v-if="testResult.smtp" :class="boxClass(testResult.smtp.ok)">
                            <span class="font-medium">SMTP:</span> {{ testResult.smtp.message }}
                        </div>
                    </div>
                </CardContent>
            </Card>

            <!-- Stopka (podpis) -->
            <Card>
                <CardHeader>
                    <h2 class="text-lg font-semibold">Stopka (podpis)</h2>
                    <p class="text-sm text-gray-500">Dodawana automatycznie przy pisaniu/odpowiadaniu z tej skrzynki.</p>
                </CardHeader>
                <CardContent>
                    <textarea v-model="form.signature" rows="4" :class="inputClass" placeholder="np.&#10;--&#10;Jan Kowalski · Argo Agency&#10;tel. 123 456 789"></textarea>
                </CardContent>
            </Card>

            <!-- Synchronizacja -->
            <Card>
                <CardHeader>
                    <h2 class="text-lg font-semibold">Synchronizacja</h2>
                </CardHeader>
                <CardContent>
                    <div class="flex items-center justify-between mb-4">
                        <div>
                            <div class="text-sm font-medium text-gray-900">Skrzynka aktywna</div>
                            <div class="text-xs text-gray-500">Wyłączona skrzynka nie jest synchronizowana.</div>
                        </div>
                        <Toggle v-model="form.is_active" />
                    </div>
                    <div>
                        <label :class="labelClass">Pobieraj maile z ostatnich</label>
                        <select v-model.number="form.sync_window_months" :class="[inputClass, 'md:w-1/2']">
                            <option :value="3">3 miesięcy</option>
                            <option :value="6">6 miesięcy</option>
                            <option :value="12">12 miesięcy</option>
                        </select>
                        <p class="mt-1 text-xs text-gray-500">
                            Starsze maile pobierzemy na żądanie — oszczędza miejsce na serwerze.
                        </p>
                    </div>
                </CardContent>
                <CardFooter>
                    <div class="flex justify-end">
                        <Button type="submit" :loading="saving">
                            {{ account ? 'Zapisz zmiany' : 'Dodaj skrzynkę' }}
                        </Button>
                    </div>
                </CardFooter>
            </Card>
        </form>
    </PageContent>
</template>

<script setup lang="ts">
import { reactive, ref, computed } from "vue";
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

interface AccountData {
    id: number;
    label: string;
    email: string;
    color: string | null;
    imap_host: string;
    imap_port: number;
    imap_encryption: string | null;
    smtp_host: string;
    smtp_port: number;
    smtp_encryption: string | null;
    username: string | null;
    has_password: boolean;
    sync_window_months: number;
    is_active: boolean;
    signature: string | null;
}

interface TestPart {
    ok: boolean;
    message: string;
}
interface TestResult {
    ok: boolean;
    message: string;
    imap?: TestPart;
    smtp?: TestPart;
}

const props = defineProps<{ account: AccountData | null }>();
const toast = useToast();

const inputClass =
    "block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 text-sm";
const labelClass = "block text-sm font-medium text-gray-700 mb-1";

const form = reactive({
    label: props.account?.label ?? "",
    email: props.account?.email ?? "",
    color: props.account?.color ?? "#2563eb",
    imap_host: props.account?.imap_host ?? "imap.gmail.com",
    imap_port: props.account?.imap_port ?? 993,
    imap_encryption: props.account?.imap_encryption ?? "ssl",
    smtp_host: props.account?.smtp_host ?? "smtp.gmail.com",
    smtp_port: props.account?.smtp_port ?? 465,
    smtp_encryption: props.account?.smtp_encryption ?? "ssl",
    username: props.account?.username ?? "",
    password: "",
    sync_window_months: props.account?.sync_window_months ?? 6,
    is_active: props.account?.is_active ?? true,
    signature: props.account?.signature ?? "",
});

const provider = ref<"gmail" | "other">(
    (props.account?.imap_host ?? "imap.gmail.com").includes("gmail") ? "gmail" : "other"
);
const lockServers = computed(() => provider.value === "gmail");

const showPass = ref(false);
const testing = ref(false);
const saving = ref(false);
const testResult = ref<TestResult | null>(null);

function applyProvider() {
    if (provider.value === "gmail") {
        form.imap_host = "imap.gmail.com";
        form.imap_port = 993;
        form.imap_encryption = "ssl";
        form.smtp_host = "smtp.gmail.com";
        form.smtp_port = 465;
        form.smtp_encryption = "ssl";
    }
}

function boxClass(ok: boolean) {
    return [
        "rounded-md p-3 text-sm border",
        ok
            ? "bg-green-50 text-green-700 border-green-200"
            : "bg-red-50 text-red-700 border-red-200",
    ];
}

async function testConnection() {
    testing.value = true;
    testResult.value = null;
    try {
        const url = props.account
            ? route("crafter.argo-mail.accounts.test.existing", props.account.id)
            : route("crafter.argo-mail.accounts.test");
        const { data } = await axios.post(url, {
            imap_host: form.imap_host,
            imap_port: form.imap_port,
            imap_encryption: form.imap_encryption,
            smtp_host: form.smtp_host,
            smtp_port: form.smtp_port,
            smtp_encryption: form.smtp_encryption,
            username: form.username || null,
            email: form.email || null,
            password: form.password || null,
        });
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
    const payload: Record<string, unknown> = { ...form };
    if (!payload.password) {
        delete payload.password; // przy edycji puste = nie zmieniaj hasła
    }

    const common = {
        preserveScroll: true,
        onFinish: () => {
            saving.value = false;
            form.password = "";
        },
        onSuccess: () => toast.success(props.account ? "Zapisano." : "Skrzynka dodana."),
        onError: (errors: Record<string, string>) => {
            const first = Object.values(errors)[0];
            if (first) toast.error(first);
        },
    };

    if (props.account) {
        router.put(route("crafter.argo-mail.accounts.update", props.account.id), payload, common);
    } else {
        router.post(route("crafter.argo-mail.accounts.store"), payload, common);
    }
}
</script>
