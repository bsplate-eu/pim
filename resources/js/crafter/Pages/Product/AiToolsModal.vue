<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition duration-300 ease-out"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition duration-200 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="modelValue"
                class="fixed inset-0 z-50 flex items-center justify-center"
                @click="closeModal"
            >
                <!-- Overlay -->
                <div class="fixed inset-0 bg-black bg-opacity-50"></div>

                <!-- Modal Content -->
                <Transition
                    enter-active-class="transition duration-300 ease-out"
                    enter-from-class="opacity-0 scale-95"
                    enter-to-class="opacity-100 scale-100"
                    leave-active-class="transition duration-200 ease-in"
                    leave-from-class="opacity-100 scale-100"
                    leave-to-class="opacity-0 scale-95"
                >
                    <div
                        v-if="modelValue"
                        class="relative bg-white rounded-lg shadow-xl max-w-3xl w-full mx-4"
                        @click.stop
                    >
                        <!-- Modal Header -->
                        <div class="flex items-center justify-between p-6 border-b border-gray-200">
                            <h3 class="text-xl font-semibold text-gray-900">
                                AI Tools
                            </h3>
                            <button
                                @click="closeModal"
                                class="text-gray-400 hover:text-gray-600 transition-colors"
                            >
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                          d="M6 18L18 6M6 6l12 12"></path>
                                </svg>
                            </button>
                        </div>

                        <!-- Modal Body -->
                        <div class="p-6 max-h-[60vh] overflow-y-auto">
                            <!-- Wybór narzędzia -->
                            <div v-if="!selectedTool" class="space-y-4">
                                <p class="text-gray-600 mb-4">
                                    Wybierz narzędzie AI, które chcesz użyć:
                                </p>

                                <div class="space-y-3">
                                    <button
                                        v-for="tool in availableTools"
                                        :key="tool.id"
                                        @click="selectTool(tool)"
                                        class="w-full text-left p-4 border border-gray-200 rounded-lg hover:bg-gray-50 hover:border-blue-300 transition-colors"
                                    >
                                        <div class="font-medium text-gray-900">{{ tool.name }}</div>
                                        <div class="text-sm text-gray-500">{{ tool.description }}</div>
                                    </button>
                                </div>
                            </div>

                            <!-- Edycja prompta -->
                            <div v-else class="space-y-4">
                                <!-- Breadcrumb -->
                                <div class="flex items-center space-x-2 text-sm text-gray-500">
                                    <button @click="selectedTool = null" class="hover:text-blue-600 transition-colors">
                                        Narzędzia AI
                                    </button>
                                    <span>/</span>
                                    <span class="text-gray-900">{{ selectedTool.name }}</span>
                                </div>

                                <!-- Informacje o produkcie -->
                                <div class="bg-gray-50 rounded-lg p-4">
                                    <h4 class="font-medium text-gray-900 mb-2">Informacje o produkcie:</h4>
                                    <div class="text-sm text-gray-600 space-y-1">
                                        <div><strong>Nazwa:</strong> {{ form.name[currentLocale] || '-' }}</div>
                                        <div><strong>Kod produktu:</strong> {{ form.product_code || '-' }}</div>
                                        <div><strong>EAN:</strong> {{ form.ean || '-' }}</div>
                                        <div><strong>Kategoria:</strong> {{ form.category || '-' }}</div>
                                    </div>
                                </div>

                                <!-- Pole na prompt -->
                                <div v-if="selectedTool && selectedTool.provider === 'openai'"
                                     class="space-y-4 border-t pt-4">
                                    <TextArea
                                        v-model="selectedTool.config.user_content"
                                        name="user_content"
                                        :label="$t('crafter', 'User Prompt')"
                                        help="Instrukcje systemowe dla AI"
                                        rows="3"
                                    />
                                </div>
                            </div>
                        </div>

                        <!-- Modal Footer -->
                        <div class="flex justify-between gap-3 p-6 border-t border-gray-200">
                            <div>
                                <button
                                    v-if="selectedTool"
                                    @click="selectedTool = null"
                                    class="text-gray-500 hover:text-gray-700 transition-colors"
                                >
                                    ← Wróć do wyboru narzędzi
                                </button>
                            </div>

                            <div class="flex gap-3">
                                <button
                                    @click="closeModal"
                                    class="px-4 py-2 text-gray-700 bg-gray-200 rounded-lg hover:bg-gray-300 transition-colors"
                                >
                                    Anuluj
                                </button>

                                <button
                                    v-if="selectedTool"
                                    @click="executeAITool"
                                    :disabled="isProcessing"
                                    class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                                >
                                    {{ isProcessing ? 'Przetwarzanie...' : 'Wykonaj z AI' }}
                                </button>
                            </div>
                        </div>
                    </div>
                </Transition>
            </div>
        </Transition>
    </Teleport>
</template>

<script setup lang="ts">
import {ref, computed, onMounted} from 'vue';


// Import hook do obsługi lokalizacji
import {useFormLocale} from "crafter/hooks/useFormLocale";
import {useToast} from "@brackets/vue-toastification";
import {TextArea, TextInput} from "@/crafter/Components";

const {availableLocales, currentLocale} = useFormLocale();

interface Props {
    modelValue: boolean;
    form: any;
}

const props = defineProps<Props>();
const toast = useToast();
const emit = defineEmits<{
    'update:modelValue': [value: boolean];
    'tool-executed': [result: any];
}>();

// Stan komponentu
const selectedTool = ref<any>(null);
const prompt = ref('');
const isProcessing = ref(false);

// Funkcja pomocnicza do wyciągnięcia wartości z pól wielojęzycznych
const getTranslatableValue = (field: any, locale: string = currentLocale.value) => {
    if (typeof field === 'object' && field !== null) {
        return field[locale] || field[availableLocales[0]] || '';
    }
    return field || '';
};

// Funkcja do przygotowania danych produktu dla AI
const prepareformData = () => {
    return {
        name: getTranslatableValue(props.form.name),
        product_code: props.form.product_code || '',
        ean: props.form.ean || '',
        category: props.form.category || '',
        info_1: getTranslatableValue(props.form.info_1),
        info_2: getTranslatableValue(props.form.info_2),
        info_3: getTranslatableValue(props.form.info_3),
        meta_title: getTranslatableValue(props.form.meta_title),
        meta_description: getTranslatableValue(props.form.meta_description),
        meta_keywords: getTranslatableValue(props.form.meta_keywords),
    };
};


const selectTool = (tool: any) => {
    selectedTool.value = { ...tool }; // Utwórz kopię obiektu

};
// DODAJ TĘ LINIĘ - definicja availableTools
const availableTools = ref([]);

// Pobierz narzędzia AI z API
onMounted(async () => {
    try {
        const response = await fetch('/admin/api/ai-tools');
        const tools = await response.json();
        availableTools.value = tools;
    } catch (error) {
        toast.error('Error loading AI tools');
    }
});
const executeAITool = async () => {
    if (!selectedTool.value) return;

    isProcessing.value = true;
    try {
        // Przygotuj dane do wysłania
        const requestData = {
            ai_tool: selectedTool.value,
            product: prepareformData(),
            current_locale: currentLocale.value,
        };

        console.log('Sending request:', requestData);

        // Wywołanie API
        const response = await fetch('/admin/api/ai-tools/execute', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
            },
            body: JSON.stringify(requestData)
        });

        const result = await response.json();
        console.log('API Response:', result);

        if (result.success) {
            const processedResult = processAIResult(result);
            console.log('Processed result:', processedResult);

            emit('tool-executed', processedResult);
            closeModal();
            toast.success('AI Tool executed successfully');
        } else {
            console.error('AI Tool error:', result.message);
            toast.error(`AI Tool error: ${result.message}`);
        }

    } catch (error) {
        console.error('Error executing AI tool:', error);
        toast.error('Error executing AI tool');
    } finally {
        isProcessing.value = false;
    }
};
// Funkcja do przetwarzania wyników AI
const processAIResult = (result: any) => {
    const processedFields = {};

    if (result.data) {
        Object.keys(result.data).forEach(fieldName => {
            const value = result.data[fieldName];

            console.log('Processing field:', fieldName, 'value:', value);

            // Sprawdź czy pole jest wielojęzyczne
            if (isTranslatableField(fieldName)) {
                // Upewnij się, że props.form[fieldName] istnieje i jest obiektem
                const existingValue = props.form[fieldName] || {};

                processedFields[fieldName] = {
                    ...existingValue, // zachowaj istniejące tłumaczenia
                    [currentLocale.value]: value
                };
            } else {
                processedFields[fieldName] = value;
            }
        });
    }

    return {
        output_fields: processedFields
    };
};

// Funkcja sprawdzająca czy pole jest wielojęzyczne
const isTranslatableField = (fieldName: string) => {
    const translatableFields = [
        'name', 'info_1', 'info_2', 'info_3',
        'meta_title', 'meta_description', 'meta_keywords', 'meta_url'
    ];
    return translatableFields.includes(fieldName);
};

// Reszta funkcji bez zmian...
const closeModal = () => {
    emit('update:modelValue', false);
    selectedTool.value = null;
    prompt.value = '';
};
</script>

