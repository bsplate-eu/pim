<!--
  Wspólny pasek zakładek modułu eBay. Dwa ekrany (Wystawianie i Aukcje) mają wyglądać
  jak jeden — dlatego pasek jest tu, a nie skopiowany w obu stronach.

  Zakładki nie renderują treści: każda strona sama decyduje, co robi z `select`
  (nawigacja Inertią do drugiego ekranu albo lokalna zmiana widoku).
-->
<template>
    <div class="mb-5 border-b border-gray-200">
        <nav class="-mb-px flex gap-6">
            <button v-for="t in TABS" :key="t.key" type="button" @click="emit('select', t.key)" :class="cls(active === t.key)">
                {{ t.label }}
            </button>
        </nav>
    </div>
</template>

<!-- Typ w zwykłym bloku — `<script setup>` nie może nic eksportować. -->
<script lang="ts">
export type EbayTab = "products" | "offers" | "auto" | "logs";
</script>

<script setup lang="ts">
defineProps<{ active: EbayTab }>();
const emit = defineEmits<{ (e: "select", tab: EbayTab): void }>();

const TABS: Array<{ key: EbayTab; label: string }> = [
    { key: "products", label: "Produkty (wystawianie)" },
    { key: "offers", label: "Aukcje" },
    { key: "auto", label: "Automatyczne akcje" },
    { key: "logs", label: "Logi" },
];

function cls(active: boolean): string {
    return [
        "border-b-2 px-1 py-3 text-sm font-medium whitespace-nowrap",
        active ? "border-primary-500 text-primary-600" : "border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700",
    ].join(" ");
}
</script>
