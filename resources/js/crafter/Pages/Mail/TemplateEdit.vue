<template>
    <PageHeader sticky :title="'Edycja szablonu: ' + template.name">
        <Button variant="secondary" @click="back">Wróć</Button>
        <Button :leftIcon="CheckIcon" @click="submit" :loading="form.processing" v-can="'crafter.mail.templates.edit'">
            Zapisz
        </Button>
    </PageHeader>

    <PageContent fluid>
        <div class="p-4 space-y-6 w-full">
            <Card title="Podstawowe">
                <div class="space-y-4">
                    <TextInput v-model="form.name" name="name" label="Nazwa szablonu (widoczna tylko w panelu)" />
                    <TextInput v-model="form.subject" name="subject" label="Temat wiadomości" help="Możesz używać zmiennych, np. {{ app_name }}" />
                    <Toggle v-model="form.is_active" name="is_active" label="Szablon aktywny" />
                </div>
            </Card>

            <Card title="Treść wiadomości">
                <div class="mb-3 flex items-center gap-2">
                    <Button
                        size="xs"
                        :variant="mode === 'wysiwyg' ? 'primary' : 'secondary'"
                        @click="mode = 'wysiwyg'"
                    >
                        Widok wizualny
                    </Button>
                    <Button
                        size="xs"
                        :variant="mode === 'html' ? 'primary' : 'secondary'"
                        @click="mode = 'html'"
                    >
                        Kod HTML
                    </Button>
                </div>

                <Wysiwyg
                    v-if="mode === 'wysiwyg'"
                    v-model="form.body_html"
                    name="body_html"
                    label=""
                />
                <TextArea
                    v-else
                    v-model="form.body_html"
                    name="body_html"
                    label=""
                    :rows="18"
                    class="font-mono text-sm"
                />

                <div class="mt-4 rounded-md border border-slate-200 bg-slate-50 p-3">
                    <div class="text-xs font-medium text-slate-600 mb-2">Dostępne znaczniki do wklejenia w temacie i treści:</div>
                    <div v-if="!variables || !variables.length" class="text-xs text-slate-400">
                        Brak zdefiniowanych znaczników dla tego szablonu.
                    </div>
                    <div v-else class="flex flex-wrap gap-2">
                        <button
                            v-for="v in variables"
                            :key="v.key"
                            type="button"
                            @click="copyVar(v.key)"
                            class="inline-flex items-center gap-1 rounded border border-slate-300 bg-white px-2 py-1 text-xs font-mono text-slate-700 hover:bg-slate-100"
                            :title="'Kliknij aby skopiować: {{ ' + v.key + ' }}'"
                        >
                            <span class="text-slate-400" v-text="'{{'"></span>
                            <span>{{ v.key }}</span>
                            <span class="text-slate-400" v-text="'}}'"></span>
                            <span class="text-slate-400">— {{ v.label }}</span>
                        </button>
                    </div>
                </div>
            </Card>
        </div>
    </PageContent>
</template>

<script setup>
import { ref, computed } from "vue";
import { router } from "@inertiajs/vue3";
import { CheckIcon } from "@heroicons/vue/24/outline";
import { useToast } from "@brackets/vue-toastification";
import { Card, TextInput, TextArea, Toggle, Button, PageHeader, PageContent, Wysiwyg } from "crafter/Components";
import { useForm } from "crafter/hooks/useForm";

const props = defineProps({
    template: { type: Object, required: true },
});

const toast = useToast();
const mode = ref("wysiwyg");
const variables = computed(() => props.template.variables || []);

const { form, submit } = useForm(
    {
        name: props.template.name,
        subject: props.template.subject,
        body_html: props.template.body_html,
        is_active: props.template.is_active,
    },
    route("crafter.mail.templates.update", props.template.id),
    "put"
);

const back = () => router.visit(route("crafter.mail.templates"));

const copyVar = async (key) => {
    const text = `{{ ${key} }}`;
    try {
        await navigator.clipboard.writeText(text);
        toast.success(`Skopiowano ${text}`);
    } catch {
        toast.info(`Wklej ręcznie: ${text}`);
    }
};
</script>
