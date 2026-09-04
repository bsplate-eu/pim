<template>
    <PageHeader
        sticky
        title="Produkcja — Magazyn"
        subtitle="W przygotowaniu"
    />

    <PageContent>
        <div class="w-full">
            <Card>
                <div class="py-10 text-center">
                    <ArchiveBoxIcon class="mx-auto h-10 w-10 text-gray-300" />
                    <h3 class="mt-3 text-base font-medium text-gray-900">
                        Magazyn produkcji
                    </h3>
                    <p class="mx-auto mt-2 max-w-md text-sm text-gray-500">
                        Ekran jest pusty — na razie to samo miejsce w menu, żeby było
                        gdzie dołożyć stany magazynowe. PIM nie trzyma jeszcze zapasu,
                        więc dane trzeba będzie tu doprowadzić z zewnątrz.
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
import { ArchiveBoxIcon } from "@heroicons/vue/24/outline";
import { Card, PageContent, PageHeader, Button } from "crafter/Components";
</script>
