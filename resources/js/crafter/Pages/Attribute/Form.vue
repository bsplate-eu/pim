<template>
    <PageContent>
        <div class="w-full">
            <CardLocaleSwitcher v-model="currentLocale" class="mb-6"/>

            <Card :title="$t('crafter', 'Attribute')" class="mb-4">
                <div class="space-y-4">
                    <TextInput
                        v-model="form.name[currentLocale]"
                        name="name"
                        :label="getLabelWithLocale($t('crafter', 'Name'))"
                    />
                </div>
            </Card>
            <Card :title="$t('crafter', 'Values')">
                <template #actions>
                    <IconButton size="sm" :icon="PlusIcon" class="mt-3" @click="addNewValue"/>
                </template>


                    <div v-for="(value, index) in form.attribute_values" :key="index" class="flex space-x-2 mb-3">
                        <div class="flex-1 w-75">
                            <TextInput
                                v-model="value.name[currentLocale]"
                                name="value"
                                :label="getLabelWithLocale($t('crafter', 'Value'))"
                            />

                        </div>
                        <div class="flex-1 w-25 content-end">
                            <IconButton size="md" :icon="TrashIcon" @click="removeValue(index)" class="bg-red-700 hover:bg-red-500 text-white font-semibold py-2 px-4 rounded focus:outline-none focus:ring-2 focus:ring-red-300"></IconButton>
                        </div>

                    </div>

            </Card>
        </div>
    </PageContent>
</template>

<script setup lang="ts">
import {
    Card,
    TextInput,
    PageContent,
    CardLocaleSwitcher,
    IconButton
} from "crafter/Components";
import {PlusIcon, TrashIcon} from "@heroicons/vue/20/solid";
import { useFormLocale } from "crafter/hooks/useFormLocale";

const { currentLocale, translatableDefaultValue, getLabelWithLocale } = useFormLocale();

interface Props {
    form: InertiaForm<AttributeForm>;
    submit: void;
}

const props = defineProps<Props>();
function getNewTranslatableValue() {
    return { ...translatableDefaultValue };
}
function addNewValue() {
    props.form.attribute_values.push({ name: getNewTranslatableValue() });
}

function removeValue(index: number) {
    props.form.attribute_values.splice(index, 1);
}
</script>
