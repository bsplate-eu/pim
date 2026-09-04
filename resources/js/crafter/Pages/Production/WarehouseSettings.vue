<template>
    <PageHeader sticky title="Magazyn — Ustawienia" subtitle="W przygotowaniu" />

    <PageContent>
        <div class="w-full">
            <Card>
                <div class="py-10 text-center">
                    <Cog6ToothIcon class="mx-auto h-10 w-10 text-gray-300" />
                    <h3 class="mt-3 text-base font-medium text-gray-900">
                        Ustawienia magazynu
                    </h3>
                    <p class="mx-auto mt-2 max-w-lg text-sm text-gray-500">
                        Jeszcze pusto. Tu wskażemy, który magazyn Subiekta GT jest czytany
                        jako M3R, skąd leci arkusz Google do kolumny „Tabela", jak często
                        odświeżamy oba źródła i co robić z kodami, których w PIM nie ma.
                    </p>
                    <Button
                        class="mt-5"
                        :as="Link"
                        :href="route('crafter.production.warehouse')"
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
import { Button, Card, PageContent, PageHeader } from "crafter/Components";
</script>
