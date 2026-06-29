<template>
    <PageHeader sticky title="Mail SMTP">
        <Button :leftIcon="PaperAirplaneIcon" variant="secondary" @click="openTest">
            Wyślij testowy mail
        </Button>
        <Button :leftIcon="CheckIcon" @click="submit" :loading="form.processing" v-can="'crafter.mail.edit'">
            Zapisz
        </Button>
    </PageHeader>

    <PageContent fluid>
        <div class="p-4 space-y-6 w-full">
            <Card title="Konfiguracja serwera poczty wychodzącej">
                <div class="space-y-4">
                    <Toggle
                        v-model="form.override_env"
                        name="override_env"
                        label="Użyj ustawień z panelu zamiast z pliku .env"
                        help="Gdy wyłączone — system używa wartości z .env (np. tymczasowo podczas migracji)."
                    />

                    <div :class="{ 'opacity-50 pointer-events-none': !form.override_env }">
                        <div class="grid grid-cols-2 gap-4">
                            <TextInput
                                v-model="form.host"
                                name="host"
                                label="Host SMTP"
                                placeholder="mail.argotech.com.pl"
                            />
                            <TextInput
                                v-model.number="form.port"
                                name="port"
                                label="Port"
                                type="number"
                                placeholder="587"
                            />
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <TextInput
                                v-model="form.username"
                                name="username"
                                label="Login / użytkownik"
                                placeholder="noreply@argotech.com.pl"
                            />
                            <div>
                                <TextInput
                                    v-model="form.password"
                                    name="password"
                                    label="Hasło"
                                    type="password"
                                    :placeholder="settings.has_password ? '•••••••• (ustawione, zostaw puste aby nie zmieniać)' : 'Wpisz hasło'"
                                />
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <SelectInput
                                v-model="form.encryption"
                                name="encryption"
                                label="Szyfrowanie"
                                :options="[
                                    { label: 'TLS (port 587)', value: 'tls' },
                                    { label: 'SSL (port 465)', value: 'ssl' },
                                    { label: 'Brak (niezalecane)', value: '' },
                                ]"
                            />
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <TextInput
                                v-model="form.from_address"
                                name="from_address"
                                label="Adres nadawcy (From)"
                                placeholder="noreply@argotech.com.pl"
                            />
                            <TextInput
                                v-model="form.from_name"
                                name="from_name"
                                label="Nazwa nadawcy"
                                placeholder="PIM"
                            />
                        </div>
                    </div>
                </div>
            </Card>

            <Card title="Aktualne wartości z pliku .env" v-if="!form.override_env">
                <table class="w-full text-sm">
                    <tbody class="divide-y divide-slate-100">
                        <tr><td class="py-1.5 text-slate-500 w-40">Host</td><td class="font-mono">{{ env_fallback.host || '—' }}</td></tr>
                        <tr><td class="py-1.5 text-slate-500">Port</td><td class="font-mono">{{ env_fallback.port }}</td></tr>
                        <tr><td class="py-1.5 text-slate-500">Użytkownik</td><td class="font-mono">{{ env_fallback.username || '—' }}</td></tr>
                        <tr><td class="py-1.5 text-slate-500">Szyfrowanie</td><td class="font-mono">{{ env_fallback.encryption || '—' }}</td></tr>
                        <tr><td class="py-1.5 text-slate-500">From</td><td class="font-mono">{{ env_fallback.from_name }} &lt;{{ env_fallback.from_address }}&gt;</td></tr>
                    </tbody>
                </table>
            </Card>
        </div>

        <!-- Test email dialog -->
        <Modal v-model="testOpen" title="Wyślij testowy mail">
            <div class="p-4 space-y-3">
                <TextInput v-model="testForm.to" name="to" label="Adres odbiorcy" placeholder="np. ja@example.com" />
                <div class="text-xs text-slate-500">
                    Mail zostanie wysłany z aktualnej konfiguracji (z panelu lub z .env — w zależności od przełącznika).
                </div>
            </div>
            <template #footer>
                <Button variant="ghost" @click="testOpen = false">Anuluj</Button>
                <Button @click="sendTest" :loading="testForm.processing" :leftIcon="PaperAirplaneIcon">Wyślij</Button>
            </template>
        </Modal>
    </PageContent>
</template>

<script setup>
import { ref } from "vue";
import { PaperAirplaneIcon, CheckIcon } from "@heroicons/vue/24/outline";
import { Card, TextInput, SelectInput, Toggle, Button, PageHeader, PageContent, Modal } from "crafter/Components";
import { useForm } from "crafter/hooks/useForm";

const props = defineProps({
    settings: { type: Object, required: true },
    env_fallback: { type: Object, required: true },
});

const { form, submit } = useForm(
    {
        override_env: props.settings.override_env,
        host: props.settings.host,
        port: props.settings.port,
        username: props.settings.username,
        password: "",
        encryption: props.settings.encryption,
        from_address: props.settings.from_address,
        from_name: props.settings.from_name,
    },
    route("crafter.mail.smtp.update"),
    "post"
);

const testOpen = ref(false);
const { form: testForm, submit: sendTestSubmit } = useForm(
    { to: "" },
    route("crafter.mail.smtp.test"),
    "post"
);
const openTest = () => { testOpen.value = true; };
const sendTest = () => sendTestSubmit({ onSuccess: () => { testOpen.value = false; } });
</script>
