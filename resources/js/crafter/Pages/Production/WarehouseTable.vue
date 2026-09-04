<template>
    <PageHeader
        sticky
        title="Tabela"
        subtitle="Arkusz Google, w którym gospodarka magazynowa prowadzona jest ręcznie"
    />

    <PageContent>
        <div class="w-full">
            <Card>
                <div class="py-10 text-center">
                    <TableCellsIcon class="mx-auto h-10 w-10 text-gray-300" />
                    <h3 class="mt-3 text-base font-medium text-gray-900">
                        Tabela z arkusza
                    </h3>
                    <p class="mx-auto mt-2 max-w-lg text-sm text-gray-500">
                        Jeszcze pusto — czekam na link do arkusza, nazwę zakładki i wskazanie,
                        która kolumna trzyma kod, a która ilość. Stąd te same liczby trafią
                        do kolumny „Tabela" na liście Magazyn M3R.
                    </p>
                    <Button
                        class="mt-5"
                        :as="Link"
                        :href="route('crafter.production.warehouse')"
                        variant="outline"
                        color="gray"
                    >
                        Przejdź do Magazyn M3R
                    </Button>
                </div>
            </Card>
        </div>
    </PageContent>
</template>

<script setup lang="ts">
import { Link } from "@inertiajs/vue3";
import { TableCellsIcon } from "@heroicons/vue/24/outline";
import { Button, Card, PageContent, PageHeader } from "crafter/Components";
</script>
