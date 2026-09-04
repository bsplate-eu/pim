<template>
    <PageHeader sticky title="Magazyn — Logi" subtitle="W przygotowaniu" />

    <PageContent>
        <div class="w-full">
            <Card>
                <div class="py-10 text-center">
                    <ClipboardDocumentListIcon class="mx-auto h-10 w-10 text-gray-300" />
                    <h3 class="mt-3 text-base font-medium text-gray-900">
                        Logi odczytów
                    </h3>
                    <p class="mx-auto mt-2 max-w-lg text-sm text-gray-500">
                        Jeszcze pusto. Tu wyląduje dziennik pobrań: kiedy przyszła paczka
                        z ARGO Bridge i z arkusza, ile kodów objęła, co się nie zmapowało
                        i co poszło nie tak.
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
import { ClipboardDocumentListIcon } from "@heroicons/vue/24/outline";
import { Button, Card, PageContent, PageHeader } from "crafter/Components";
</script>
