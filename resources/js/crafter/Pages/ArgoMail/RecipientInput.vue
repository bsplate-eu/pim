<template>
    <div class="relative flex-1">
        <input
            ref="inputEl"
            :value="modelValue"
            type="text"
            :placeholder="placeholder"
            autocomplete="off"
            class="w-full rounded-md border-gray-300 text-sm"
            @input="onInput"
            @keydown="onKeydown"
            @blur="onBlur"
        />
        <ul
            v-if="open && suggestions.length"
            class="absolute z-[60] left-0 right-0 mt-1 max-h-56 overflow-y-auto rounded-md border border-gray-200 bg-white shadow-lg py-1"
        >
            <li
                v-for="(c, i) in suggestions"
                :key="c.email"
                :class="['px-3 py-1.5 text-sm cursor-pointer flex flex-col', i === activeIndex ? 'bg-primary-50 text-primary-700' : 'text-gray-700 hover:bg-gray-50']"
                @mousedown.prevent="choose(c)"
                @mouseenter="activeIndex = i"
            >
                <span v-if="c.name" class="font-medium leading-tight">{{ c.name }}</span>
                <span :class="c.name ? 'text-xs text-gray-500 leading-tight' : 'leading-tight'">{{ c.email }}</span>
            </li>
        </ul>
    </div>
</template>

<script setup lang="ts">
// Pole adresata z autouzupełnianiem: wpisujesz litery → podpowiada adresy z dotychczasowej
// korespondencji (endpoint argo-mail.contacts). Obsługuje wiele adresów oddzielonych przecinkami —
// podpowiedzi dotyczą fragmentu po ostatnim przecinku. Używane w polach „Do" i „DW" kompozytora.
import { ref } from "vue";
import axios from "axios";
import { debounce } from "lodash";

interface Contact { email: string; name: string }

const props = defineProps<{ modelValue: string; placeholder?: string }>();
const emit = defineEmits<{ (e: "update:modelValue", v: string): void }>();

const inputEl = ref<HTMLInputElement | null>(null);
const suggestions = ref<Contact[]>([]);
const open = ref(false);
const activeIndex = ref(0);

// Fragment aktualnie wpisywany = tekst po ostatnim przecinku (pole przyjmuje wiele adresów).
function currentFragment(value: string): string {
    const idx = value.lastIndexOf(",");
    return (idx === -1 ? value : value.slice(idx + 1)).trim();
}

const fetchSuggestions = debounce(async (frag: string) => {
    if (frag.length < 2) { suggestions.value = []; open.value = false; return; }
    try {
        const { data } = await axios.get(route("crafter.argo-mail.contacts"), { params: { q: frag } });
        suggestions.value = data.contacts || [];
        activeIndex.value = 0;
        open.value = suggestions.value.length > 0;
    } catch (e) {
        suggestions.value = [];
        open.value = false;
    }
}, 180);

function onInput(e: Event) {
    const value = (e.target as HTMLInputElement).value;
    emit("update:modelValue", value);
    fetchSuggestions(currentFragment(value));
}

// Wstawia wybrany adres w miejsce ostatniego fragmentu i dokleja „, " pod kolejny adres.
function choose(c: Contact) {
    const value = props.modelValue;
    const idx = value.lastIndexOf(",");
    const prefix = idx === -1 ? "" : value.slice(0, idx + 1) + " ";
    emit("update:modelValue", prefix + c.email + ", ");
    suggestions.value = [];
    open.value = false;
    inputEl.value?.focus();
}

function onKeydown(e: KeyboardEvent) {
    if (!open.value || !suggestions.value.length) return;
    if (e.key === "ArrowDown") {
        e.preventDefault();
        activeIndex.value = (activeIndex.value + 1) % suggestions.value.length;
    } else if (e.key === "ArrowUp") {
        e.preventDefault();
        activeIndex.value = (activeIndex.value - 1 + suggestions.value.length) % suggestions.value.length;
    } else if (e.key === "Enter" || e.key === "Tab") {
        // Enter/Tab przy otwartej liście = wybierz podpowiedź (zamiast wysłać mail / przejść dalej).
        e.preventDefault();
        choose(suggestions.value[activeIndex.value]);
    } else if (e.key === "Escape") {
        open.value = false;
    }
}

function onBlur() {
    // Opóźnienie, by zdążył zadziałać klik w podpowiedź (mousedown odpala się przed blur).
    window.setTimeout(() => { open.value = false; }, 120);
}
</script>
