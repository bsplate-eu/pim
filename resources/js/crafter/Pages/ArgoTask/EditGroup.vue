<template>
    <PageHeader :title="`Edytuj grupę: ${group.name}`" />

    <PageContent>
        <div class="w-full">
            <Card>
                <CardHeader>
                    <h2 class="text-base font-semibold text-gray-700">Edycja grupy</h2>
                </CardHeader>
                <CardContent>
                    <form @submit.prevent="submit" class="space-y-4">
                        <TextInput
                            v-model="form.name"
                            name="name"
                            label="Nazwa grupy"
                            :error="form.errors.name"
                            required
                        />

                        <TextArea
                            v-model="form.description"
                            name="description"
                            label="Opis"
                            :rows="3"
                            :error="form.errors.description"
                        />

                        <div class="grid grid-cols-2 gap-4">
                            <TextInput
                                v-model="form.icon"
                                name="icon"
                                label="Ikona"
                                :error="form.errors.icon"
                            />
                            <TextInput
                                v-model="form.color"
                                name="color"
                                label="Kolor"
                                :error="form.errors.color"
                            />
                        </div>

                        <div class="flex justify-end pt-2 gap-2">
                            <Button :loading="form.processing" type="submit">
                                Zapisz
                            </Button>
                        </div>
                    </form>
                </CardContent>
            </Card>
        </div>
    </PageContent>
</template>

<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { useToast } from '@brackets/vue-toastification';
import {
    PageHeader, PageContent,
    Card, CardHeader, CardContent,
    TextInput, TextArea, Button,
} from 'crafter/Components';

interface Group {
    id: number;
    name: string;
    description: string | null;
    icon: string | null;
    color: string | null;
}
interface Props { group: Group }
const props = defineProps<Props>();
const toast = useToast();

const form = useForm({
    name: props.group.name,
    description: props.group.description ?? '',
    icon: props.group.icon ?? '',
    color: props.group.color ?? '',
});

const submit = () => {
    form.patch(route('crafter.argo-task.groups.update', props.group.id), {
        onSuccess: () => toast.success('Grupa zaktualizowana.'),
        onError: (e) => toast.error(Object.values(e)[0] as string),
    });
};
</script>
