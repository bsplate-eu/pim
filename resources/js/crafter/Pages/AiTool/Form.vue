<template>
    <PageContent>
        <div class="w-full">
            <CardLocaleSwitcher v-model="currentLocale" class="mb-6" />

            <Card>
                <div class="space-y-4">
                    <TextInput
                        v-model="form.name[currentLocale]"
                        name="name"
                        :label="getLabelWithLocale($t('crafter', 'Name'))"
                    />

                    <TextInput
                        v-model="form.description[currentLocale]"
                        name="description"
                        :label="getLabelWithLocale($t('crafter', 'Description'))"
                    />

                    <SelectInput
                        v-model="form.provider"
                        name="provider"
                        :options="providerOptions"
                        :label="$t('crafter', 'Provider')"
                    />

                    <!-- Konfiguracja OpenAI -->
                    <div v-if="form.provider === 'openai'" class="space-y-4 border-t pt-4">
                        <h3 class="text-lg font-medium text-gray-900">{{ $t('crafter', 'OpenAI Configuration') }}</h3>


                        <TextArea
                            v-model="form.config.system_content"
                            name="system_content"
                            :label="$t('crafter', 'System Prompt')"
                            help="Instrukcje systemowe dla AI"
                            rows="3"
                        />
                        <TextArea
                            v-model="form.config.user_content"
                            name="user_content"
                            :label="$t('crafter', 'User Prompt')"
                            help="Instrukcje systemowe dla AI"
                            rows="3"
                        />
                        <div class="grid grid-cols-2 gap-4">
                            <TextInput
                                v-model="form.config.max_tokens"
                                type="number"
                                name="max_tokens"
                                :label="$t('crafter', 'Max Tokens')"
                                help="Maksymalna liczba tokenów (1-128000)"
                                min="1"
                                max="128000"
                            />
                            <TextInput
                                v-model="form.config.temperature"
                                type="number"
                                step="0.1"
                                min="0"
                                max="2"
                                name="temperature"
                                :label="$t('crafter', 'Temperature')"
                                help="Kreatywność odpowiedzi (0.0-2.0)"
                            />
                        </div>

                        <div class="grid grid-cols-2 gap-4">

                            <TextInput
                                v-model="form.config.top_p"
                                type="number"
                                step="0.01"
                                min="0"
                                max="1"
                                name="top_p"
                                :label="$t('crafter', 'Top P')"
                                help="Nucleus sampling (0.0-1.0)"
                            />
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <TextInput
                                v-model="form.config.frequency_penalty"
                                type="number"
                                step="0.1"
                                min="-2"
                                max="2"
                                name="frequency_penalty"
                                :label="$t('crafter', 'Frequency Penalty')"
                                help="Kara za powtarzanie (-2.0 do 2.0)"
                            />

                            <TextInput
                                v-model="form.config.presence_penalty"
                                type="number"
                                step="0.1"
                                min="-2"
                                max="2"
                                name="presence_penalty"
                                :label="$t('crafter', 'Presence Penalty')"
                                help="Kara za obecność słów (-2.0 do 2.0)"
                            />
                        </div>

                    </div>

                    <!-- Konfiguracja PhotoRoom -->
                    <div v-if="form.provider === 'photoroom'" class="space-y-4 border-t pt-4">
                        <h3 class="text-lg font-medium text-gray-900">{{ $t('crafter', 'PhotoRoom Configuration') }}</h3>

                        <SelectInput
                            v-model="form.config.operation"
                            name="operation"
                            :options="photoRoomOperations"
                            :label="$t('crafter', 'Operation')"
                            help="Typ operacji do wykonania"
                        />

                        <div class="grid grid-cols-2 gap-4">
                            <SelectInput
                                v-model="form.config.format"
                                name="format"
                                :options="imageFormatOptions"
                                :label="$t('crafter', 'Output Format')"
                                help="Format wyjściowego obrazu"
                            />

                            <SelectInput
                                v-model="form.config.size"
                                name="size"
                                :options="imageSizeOptions"
                                :label="$t('crafter', 'Processing Size')"
                                help="Rozmiar przetwarzania"
                            />
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <TextInput
                                v-model="form.config.quality"
                                type="number"
                                min="1"
                                max="100"
                                name="quality"
                                :label="$t('crafter', 'Quality')"
                                help="Jakość obrazu JPEG (1-100)"
                            />

                            <TextInput
                                v-model="form.config.scale"
                                type="number"
                                step="0.1"
                                min="0.1"
                                max="4.0"
                                name="scale"
                                :label="$t('crafter', 'Scale Factor')"
                                help="Współczynnik skalowania (0.1-4.0)"
                            />
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <TextInput
                                v-model="form.config.width"
                                type="number"
                                min="64"
                                max="4096"
                                name="width"
                                :label="$t('crafter', 'Output Width')"
                                help="Szerokość wyjściowa (px)"
                            />

                            <TextInput
                                v-model="form.config.height"
                                type="number"
                                min="64"
                                max="4096"
                                name="height"
                                :label="$t('crafter', 'Output Height')"
                                help="Wysokość wyjściowa (px)"
                            />
                        </div>

                        <SelectInput
                            v-model="form.config.background_color"
                            name="background_color"
                            :options="backgroundColorOptions"
                            :label="$t('crafter', 'Background Color')"
                            help="Kolor tła po usunięciu"
                        />

                        <TextInput
                            v-model="form.config.custom_background"
                            name="custom_background"
                            :label="$t('crafter', 'Custom Background')"
                            help="Niestandardowy kolor tła (hex: #FFFFFF)"
                        />

                        <div class="grid grid-cols-2 gap-4">
                            <Checkbox
                                v-model="form.config.crop_to_result"
                                name="crop_to_result"
                                :label="$t('crafter', 'Crop to Result')"
                                help="Przytnij do wyniku"
                            />

                            <Checkbox
                                v-model="form.config.add_shadow"
                                name="add_shadow"
                                :label="$t('crafter', 'Add Shadow')"
                                help="Dodaj cień do obiektu"
                            />
                        </div>

                        <SelectInput
                            v-model="form.config.shadow_type"
                            name="shadow_type"
                            :options="shadowTypeOptions"
                            :label="$t('crafter', 'Shadow Type')"
                            help="Typ cienia"
                        />

                        <TextInput
                            v-model="form.config.padding"
                            type="number"
                            min="0"
                            max="200"
                            name="padding"
                            :label="$t('crafter', 'Padding')"
                            help="Margines wokół obiektu (px)"
                        />
                    </div>

                    <Checkbox
                        v-model="form.enabled"
                        name="enabled"
                        :label="$t('crafter', 'Enabled')"
                    />
                </div>
            </Card>
        </div>
    </PageContent>
</template>

<script setup lang="ts">
import {
    Card,
    TextInput,
    TextArea,
    PageContent,
    Checkbox,
    CardLocaleSwitcher,
    SelectInput
} from "crafter/Components";
import { InertiaForm } from "crafter/types";
import type { AiToolForm } from "./types";
import { ref, watch } from 'vue';
import { useFormLocale } from "crafter/hooks/useFormLocale";

const { availableLocales, currentLocale, translatableDefaultValue, getLabelWithLocale } = useFormLocale();

interface Props {
    form: InertiaForm<AiToolForm>;
    submit: void;
}

const props = defineProps<Props>();

// Opcje providerów
const providerOptions = ref([
    { value: 'openai', label: 'OpenAI' },
    { value: 'photoroom', label: 'PhotoRoom' }
]);


const responseFormatOptions = ref([
    { value: 'text', label: 'Text' },
    { value: 'json_object', label: 'JSON Object' },
    { value: 'json_schema', label: 'JSON Schema' }
]);

// Opcje PhotoRoom
const photoRoomOperations = ref([
    { value: 'remove-background', label: 'Remove Background' },
    { value: 'enhance', label: 'Enhance Image' },
    { value: 'upscale', label: 'Upscale Image' },
    { value: 'crop', label: 'Smart Crop' },
    { value: 'recolor', label: 'Recolor Object' }
]);
const imageFormatOptions = ref([
    { value: 'PNG', label: 'PNG (transparent background)' },
    { value: 'JPEG', label: 'JPEG (smaller file size)' },
    { value: 'WEBP', label: 'WebP (modern format)' }
]);

const imageSizeOptions = ref([
    { value: 'preview', label: 'Preview (fast processing)' },
    { value: 'medium', label: 'Medium (balanced quality)' },
    { value: 'full', label: 'Full (highest quality)' },
    { value: 'hd', label: 'HD (ultra high quality)' }
]);

const backgroundColorOptions = ref([
    { value: 'transparent', label: 'Transparent' },
    { value: 'white', label: 'White' },
    { value: 'black', label: 'Black' },
    { value: 'gray', label: 'Gray' },
    { value: 'custom', label: 'Custom' }
]);

const shadowTypeOptions = ref([
    { value: 'none', label: 'No shadow' },
    { value: 'soft', label: 'Soft shadow' },
    { value: 'hard', label: 'Hard shadow' },
    { value: 'realistic', label: 'Realistic shadow' }
]);

// Domyślne konfiguracje
const defaultOpenAiConfig = {
    max_tokens: 1000,
    temperature: 0.7,
    top_p: 1.0,
    frequency_penalty: 0.0,
    presence_penalty: 0.0,
    system_content: '',
    user_content: '',
};

const defaultPhotoRoomConfig = {
    operation: 'remove-background',
    format: 'PNG',
    size: 'medium',
    quality: 95,
    scale: 1.0,
    width: null,
    height: null,
    background_color: 'transparent',
    custom_background: '',
    crop_to_result: false,
    add_shadow: false,
    shadow_type: 'none',
    padding: 0
};

// Watch dla zmiany providera
watch(() => props.form.provider, (newProvider) => {
    if (newProvider === 'openai') {
        props.form.config = defaultOpenAiConfig

    } else if (newProvider === 'photoroom') {
        props.form.config = defaultPhotoRoomConfig

    }
});
</script>
