<template>
    <PageHeader sticky title="Produkcja — Raporty" subtitle="W przygotowaniu" />

    <PageContent>
        <div class="w-full">
            <Card>
                <div class="py-10 text-center">
                    <ChartBarIcon class="mx-auto h-10 w-10 text-gray-300" />
                    <h3 class="mt-3 text-base font-medium text-gray-900">
                        Raporty produkcyjne
                    </h3>
                    <p class="mx-auto mt-2 max-w-md text-sm text-gray-500">
                        Ekran jeszcze nie ma treści — pozycja w menu jest po to, żeby
                        było gdzie ją dołożyć. Dane, na których stanie, już są:
                        znaczniki etapów i sprzedaż 12 mc na kodach.
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
import { ChartBarIcon } from "@heroicons/vue/24/outline";
import { Card, PageContent, PageHeader, Button } from "crafter/Components";
</script>
