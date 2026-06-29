<template>
    <PageHeader title="Nowa grupa projektów" />

    <PageContent>
        <div class="w-full">
            <Card>
                <CardHeader>
                    <h2 class="text-base font-semibold text-gray-700">Utwórz grupę</h2>
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
                                label="Ikona (opcjonalnie)"
                                placeholder="np. 📁"
                                :error="form.errors.icon"
                            />
                            <TextInput
                                v-model="form.color"
                                name="color"
                                label="Kolor (opcjonalnie)"
                                placeholder="np. blue"
                                :error="form.errors.color"
                            />
                        </div>

                        <div class="flex justify-end pt-2 gap-2">
                            <Button
                                type="submit"
                                :leftIcon="PlusIcon"
                                :loading="form.processing"
                            >
                                Utwórz grupę
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
import { PlusIcon } from '@heroicons/vue/24/outline';
import {
    PageHeader, PageContent,
    Card, CardHeader, CardContent,
    TextInput, TextArea, Button,
} from 'crafter/Components';

const toast = useToast();

const form = useForm({
    name: '',
    description: '',
    icon: '',
    color: '',
});

const submit = () => {
    form.post(route('crafter.argo-task.groups.store'), {
        onSuccess: () => {
            toast.success('Grupa utworzona.');
            form.reset();
        },
        onError: (errors) => {
            toast.error(Object.values(errors)[0] as string);
        },
    });
};
</script>
