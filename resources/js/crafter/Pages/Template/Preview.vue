<template>
    <PageHeader
        sticky
        :title="$t('crafter', 'Preview Template') + ' - ' + props.template?.name"
        :subtitle="`Last updated at ${dayjs(template.updated_at).format('DD.MM.YYYY')}`"
    >
            <Button
                :leftIcon="PencilSquareIcon"
                :href="route('crafter.templates.edit', [props.template?.id])"
                v-can="'crafter.template.edit'"
            >
                {{ $t("crafter", "Edit") }}
            </Button>
    </PageHeader>

    <PageContent>
        <Card :title="props.title">
            <div v-html="props.description"></div>
        </Card>
    </PageContent>
</template>

<script setup lang="ts">
import {PencilSquareIcon, EyeIcon} from "@heroicons/vue/24/outline";
import {PageHeader, Button, Card, PageContent} from "crafter/Components";
import dayjs from "dayjs";
import type {Template} from "crafter/Pages/Template/types";


interface Props {
    template: Template;
    title: string;
    description: string;

}

const props = defineProps<Props>();

</script>
