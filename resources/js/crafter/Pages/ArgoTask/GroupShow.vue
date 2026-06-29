<template>
    <PageHeader>
        <template #title>
            <input
                v-if="editingName"
                ref="nameInputEl"
                v-model="nameDraft"
                type="text"
                class="text-gray-900 text-2xl font-medium leading-6 bg-white border border-primary-300 rounded px-2 py-0.5 -mx-2 -my-0.5 focus:outline-none focus:ring-2 focus:ring-primary-200 min-w-[300px]"
                @blur="saveName"
                @keydown.enter.prevent="saveName"
                @keydown.escape.prevent="cancelName"
            />
            <span
                v-else
                class="cursor-text hover:bg-gray-100 rounded px-2 py-0.5 -mx-2 -my-0.5 transition-colors"
                title="Kliknij, aby edytować"
                @click="startEditName"
            >
                {{ group.name }}
            </span>
        </template>

        <Button :leftIcon="PlusIcon" @click.prevent="addProject">
            Dodaj projekt
        </Button>
        <Button color="gray" variant="outline" :leftIcon="PencilSquareIcon"
                @click.prevent="editGroup">
            Edytuj grupę
        </Button>
        <Button color="gray" variant="outline" :leftIcon="TrashIcon"
                @click.prevent="deleteGroupModalOpen = true">
            Usuń grupę
        </Button>
    </PageHeader>

    <PageContent>
        <p v-if="group.description" class="text-sm text-gray-500 mb-6">
            {{ group.description }}
        </p>

        <div v-if="projects.length === 0" class="text-center py-16 text-gray-400">
            <p class="text-sm">Brak projektów w tej grupie.</p>
            <Button class="mt-4" :leftIcon="PlusIcon" @click.prevent="addProject">
                Dodaj pierwszy projekt
            </Button>
        </div>

        <div v-else class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            <Link
                v-for="project in projects"
                :key="project.id"
                :href="route('crafter.argo-task.projects.show', project.id)"
                class="block bg-white rounded-lg border border-gray-200 p-4 shadow-sm hover:shadow-md transition-shadow"
            >
                <div class="flex items-center gap-2 mb-2">
                    <span v-if="project.icon" class="text-xl">{{ project.icon }}</span>
                    <h3 class="text-sm font-semibold text-gray-800 flex-1 truncate">
                        {{ project.name }}
                    </h3>
                </div>
                <p v-if="project.description" class="text-xs text-gray-500 mb-3 line-clamp-2">
                    {{ project.description }}
                </p>
                <div class="flex items-center justify-between text-xs text-gray-400">
                    <span>{{ project.tasks_count ?? 0 }} zadań</span>
                </div>
            </Link>
        </div>
    </PageContent>

    <!-- Delete Group Modal -->
    <Modal :open="deleteGroupModalOpen" externalOpen type="danger"
           @toggleOpen="deleteGroupModalOpen = false">
        <template #title>Usuń grupę</template>
        <template #content>
            <p>Czy na pewno chcesz usunąć grupę <strong>{{ group.name }}</strong>?</p>
            <p v-if="projects.length > 0" class="mt-2 text-sm text-red-600">
                Grupa zawiera {{ projects.length }} projekt(ów) — usuń je najpierw lub przenieś do innej grupy.
            </p>
        </template>
        <template #buttons="{ setIsOpen }">
            <Button color="danger" :loading="deleteLoading"
                    :disabled="projects.length > 0"
                    @click.prevent="submitDelete">
                Usuń
            </Button>
            <Button color="gray" variant="outline"
                    @click.prevent="() => { setIsOpen(false); deleteGroupModalOpen = false; }">
                Anuluj
            </Button>
        </template>
    </Modal>
</template>

<script setup lang="ts">
import { ref, nextTick } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { useToast } from '@brackets/vue-toastification';
import { PlusIcon, PencilSquareIcon, TrashIcon } from '@heroicons/vue/24/outline';
import {
    PageHeader, PageContent, Button, Modal,
} from 'crafter/Components';

interface Group {
    id: number;
    name: string;
    description: string | null;
    icon: string | null;
    color: string | null;
}
interface Project {
    id: number;
    name: string;
    description: string | null;
    icon: string | null;
    color: string | null;
    tasks_count?: number;
}
interface Props {
    group: Group;
    projects: Project[];
}
const props = defineProps<Props>();
const toast = useToast();

const deleteGroupModalOpen = ref(false);
const deleteLoading = ref(false);

// ─── Inline edit nazwy grupy ─────────────────────────────────────────────────
const editingName = ref(false);
const nameDraft = ref('');
const nameInputEl = ref<HTMLInputElement | null>(null);

const startEditName = () => {
    nameDraft.value = props.group.name;
    editingName.value = true;
    nextTick(() => {
        nameInputEl.value?.focus();
        nameInputEl.value?.select();
    });
};
const cancelName = () => { editingName.value = false; nameDraft.value = ''; };
const saveName = () => {
    if (!editingName.value) return;
    const next = nameDraft.value.trim();
    if (!next || next === props.group.name) { cancelName(); return; }
    router.patch(route('crafter.argo-task.groups.update', props.group.id), { name: next }, {
        preserveScroll: true,
        onSuccess: () => toast.success('Nazwa grupy zaktualizowana.'),
        onError: (e) => {
            toast.error(Object.values(e)[0] as string);
            nameDraft.value = props.group.name;
        },
        onFinish: () => { editingName.value = false; },
    });
};

const addProject = () => {
    router.visit(route('crafter.argo-task.projects.create', props.group.id));
};

const editGroup = () => {
    router.visit(route('crafter.argo-task.groups.edit', props.group.id));
};

const submitDelete = () => {
    deleteLoading.value = true;
    router.delete(route('crafter.argo-task.groups.destroy', props.group.id), {
        onSuccess: () => toast.success('Grupa usunięta.'),
        onError: (e) => toast.error(Object.values(e)[0] as string),
        onFinish: () => { deleteLoading.value = false; },
    });
};
</script>
