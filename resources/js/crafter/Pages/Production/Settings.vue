<template>
    <PageHeader sticky title="Produkcja — Ustawienia" subtitle="W przygotowaniu" />

    <PageContent>
        <div class="w-full">
            <Card>
                <div class="py-10 text-center">
                    <Cog6ToothIcon class="mx-auto h-10 w-10 text-gray-300" />
                    <h3 class="mt-3 text-base font-medium text-gray-900">
                        Ustawienia modułu Produkcja
                    </h3>
                    <p class="mx-auto mt-2 max-w-md text-sm text-gray-500">
                        Ekran jeszcze nie ma treści. Tu wylądują rzeczy, które dziś
                        siedzą w kodzie — nazwy i kolory etapów, aliasy symboli
                        z Subiekta, wgrywanie raportu sprzedaży.
                    </p>
                    <Button
                        class="mt-5"
                        :as="Link"
                        :href="route('crafter.production.index')"
                        variant="outline"
                        color="gray"
                    >
                        Wróć do listy kodów
                    </Button>
                </div>
            </Card>
        </div>
    </PageContent>
</template>

<script setup lang="ts">
import { Link } from "@inertiajs/vue3";
import { Cog6ToothIcon } from "@heroicons/vue/24/outline";
import { Card, PageContent, PageHeader, Button } from "crafter/Components";
</script>
