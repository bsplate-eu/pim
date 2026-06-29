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
                {{ project.name }}
            </span>
        </template>

        <Button
            :leftIcon="PlusIcon"
            @click.prevent="openCreate"
        >
            Dodaj zadanie
        </Button>
        <Button
            color="gray"
            variant="outline"
            :leftIcon="Cog6ToothIcon"
            @click.prevent="openBoardSettings"
        >
            Edycja
        </Button>
        <Button
            color="gray"
            variant="outline"
            :leftIcon="TrashIcon"
            @click.prevent="deleteProjectModalOpen = true"
        >
            Usuń projekt
        </Button>
    </PageHeader>

    <PageContent fluid>
        <p v-if="project.description" class="text-sm text-gray-500 mb-4">
            {{ project.description }}
        </p>

        <!-- Przełącznik widoków -->
        <div class="inline-flex rounded-lg bg-gray-100 p-1 mb-4">
            <button
                type="button"
                class="flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-md transition-colors"
                :class="viewMode === 'kanban'
                    ? 'bg-white text-gray-800 shadow-sm'
                    : 'text-gray-500 hover:text-gray-700'"
                @click="viewMode = 'kanban'"
            >
                <Squares2X2Icon class="h-4 w-4" />
                Kanban
            </button>
            <button
                type="button"
                class="flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-md transition-colors"
                :class="viewMode === 'list'
                    ? 'bg-white text-gray-800 shadow-sm'
                    : 'text-gray-500 hover:text-gray-700'"
                @click="viewMode = 'list'"
            >
                <ListBulletIcon class="h-4 w-4" />
                Lista
            </button>
        </div>

        <!-- Kanban -->
        <div v-show="viewMode === 'kanban'" class="flex gap-4 overflow-x-auto pb-6 pt-2 min-h-[70vh] items-start">
            <div
                v-for="(colLabel, colKey) in columns"
                :key="colKey"
                class="flex-shrink-0 w-72 flex flex-col rounded-lg bg-gray-50 border border-gray-200"
            >
                <!-- Column header -->
                <div class="flex items-center justify-between px-3 py-2.5 border-b border-gray-200">
                    <div class="flex items-center gap-2">
                        <span class="h-2.5 w-2.5 rounded-full flex-shrink-0" :class="columnDot(colKey)" />
                        <span class="text-sm font-semibold text-gray-700">{{ colLabel }}</span>
                    </div>
                    <span class="text-xs font-medium text-gray-400 bg-gray-100 rounded-full px-2 py-0.5">
                        {{ boardData[colKey]?.length ?? 0 }}
                    </span>
                </div>

                <!-- Draggable card list -->
                <draggable
                    :list="boardData[colKey]"
                    group="tasks"
                    item-key="id"
                    class="flex flex-col gap-2 p-2 min-h-[60px] flex-1"
                    ghost-class="opacity-40"
                    :animation="150"
                    :force-fallback="true"
                    :fallback-tolerance="4"
                    :delay="0"
                    :touch-start-threshold="3"
                    :fallback-on-body="true"
                    drag-class="argo-drag"
                    chosen-class="argo-chosen"
                    @start="onDragStart"
                    @end="onDragEnd"
                    @change="(evt) => onDragChange(evt, colKey)"
                >
                    <template #item="{ element: task }">
                        <div
                            class="bg-white rounded-md border border-gray-200 p-3 shadow-sm cursor-grab hover:shadow-md transition-shadow group"
                            @click="handleCardClick(task)"
                        >
                            <!-- Title row -->
                            <div class="flex items-start justify-between gap-1 mb-2">
                                <span class="text-sm font-semibold text-gray-800 leading-snug flex-1 hover:underline">
                                    {{ task.name }}
                                </span>
                                <div class="flex items-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity flex-shrink-0">
                                    <button
                                        type="button"
                                        class="p-0.5 rounded text-gray-400 hover:text-primary-600 hover:bg-gray-100"
                                        @click.stop="openEdit(task)"
                                        title="Edytuj"
                                    >
                                        <PencilSquareIcon class="h-3.5 w-3.5" />
                                    </button>
                                    <button
                                        type="button"
                                        class="p-0.5 rounded text-gray-400 hover:text-red-600 hover:bg-red-50"
                                        @click.stop="openDelete(task)"
                                        title="Usuń"
                                    >
                                        <TrashIcon class="h-3.5 w-3.5" />
                                    </button>
                                </div>
                            </div>

                            <p v-if="task.description" class="text-xs text-gray-500 mb-2 line-clamp-2">
                                {{ task.description }}
                            </p>

                            <div v-if="task.labels?.length" class="flex flex-wrap gap-1 mb-2">
                                <span
                                    v-for="label in task.labels"
                                    :key="label"
                                    class="inline-block rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide"
                                    :class="labelStyle(label)"
                                >
                                    {{ label }}
                                </span>
                            </div>

                            <div class="flex items-center justify-between mt-1.5 gap-2">
                                <div class="flex items-center gap-1.5 min-w-0">
                                    <template v-if="task.assignees?.length">
                                        <Avatar
                                            :name="`${task.assignees[0].first_name} ${task.assignees[0].last_name}`"
                                            size="xs"
                                        />
                                        <span class="text-[10px] text-gray-500 truncate">
                                            {{ task.assignees[0].first_name }}
                                            {{ task.assignees[0].last_name }}<span v-if="task.assignees.length > 1"> +{{ task.assignees.length - 1 }}</span>
                                        </span>
                                    </template>
                                </div>

                                <div class="flex items-center gap-1.5 flex-shrink-0">
                                    <span
                                        v-if="task.priority"
                                        class="rounded px-1.5 py-0.5 text-[10px] font-bold uppercase"
                                        :class="priorityStyle(task.priority)"
                                    >
                                        {{ task.priority }}
                                    </span>
                                    <span v-if="task.due_date" class="text-[10px] text-gray-400">
                                        {{ formatDate(task.due_date) }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </template>
                </draggable>

                <!-- + Nowe zadanie w kolumnie -->
                <button
                    type="button"
                    class="mx-2 mb-2 flex items-center justify-center gap-1 px-3 py-2 text-xs text-gray-500 hover:text-primary-600 hover:bg-white rounded-md border border-dashed border-gray-300 hover:border-primary-400 transition-colors"
                    @click.prevent="openCreateInColumn(colKey)"
                >
                    <PlusIcon class="h-3.5 w-3.5" />
                    <span>Nowe zadanie</span>
                </button>
            </div>
        </div>

        <!-- Lista -->
        <div v-show="viewMode === 'list'" class="bg-white rounded-lg border border-gray-200 overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Nazwa</th>
                        <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Status</th>
                        <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Przypisane</th>
                        <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Termin</th>
                        <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Priorytet</th>
                        <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Etykiety</th>
                        <th class="px-4 py-2.5 w-20"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <tr
                        v-for="task in flatTasks"
                        :key="task.id"
                        class="hover:bg-gray-50 cursor-pointer group"
                        @click="goToTask(task)"
                    >
                        <td class="px-4 py-2.5 font-medium text-gray-800">{{ task.name }}</td>
                        <td class="px-4 py-2.5">
                            <span class="inline-flex items-center gap-1.5 text-xs font-semibold">
                                <span class="h-2 w-2 rounded-full" :class="columnDot(task.kanban_column)" />
                                {{ columns[task.kanban_column] ?? task.kanban_column }}
                            </span>
                        </td>
                        <td class="px-4 py-2.5">
                            <div v-if="task.assignees?.length" class="flex items-center gap-1.5">
                                <Avatar :name="`${task.assignees[0].first_name} ${task.assignees[0].last_name}`" size="xs" />
                                <span class="text-xs text-gray-600">
                                    {{ task.assignees[0].first_name }} {{ task.assignees[0].last_name }}<span v-if="task.assignees.length > 1" class="text-gray-400"> +{{ task.assignees.length - 1 }}</span>
                                </span>
                            </div>
                            <span v-else class="text-xs text-gray-300">—</span>
                        </td>
                        <td class="px-4 py-2.5 text-xs text-gray-500">
                            <span v-if="task.due_date">{{ formatDate(task.due_date) }}</span>
                            <span v-else class="text-gray-300">—</span>
                        </td>
                        <td class="px-4 py-2.5">
                            <span
                                v-if="task.priority"
                                class="inline-block rounded px-1.5 py-0.5 text-[10px] font-bold uppercase"
                                :class="priorityStyle(task.priority)"
                            >
                                {{ task.priority }}
                            </span>
                            <span v-else class="text-xs text-gray-300">—</span>
                        </td>
                        <td class="px-4 py-2.5">
                            <div v-if="task.labels?.length" class="flex flex-wrap gap-1">
                                <span
                                    v-for="label in task.labels"
                                    :key="label"
                                    class="inline-block rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide"
                                    :class="labelStyle(label)"
                                >
                                    {{ label }}
                                </span>
                            </div>
                            <span v-else class="text-xs text-gray-300">—</span>
                        </td>
                        <td class="px-4 py-2.5">
                            <div class="flex items-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                                <button type="button" class="p-1 rounded text-gray-400 hover:text-primary-600 hover:bg-gray-100"
                                        @click.stop="openEdit(task)" title="Edytuj">
                                    <PencilSquareIcon class="h-3.5 w-3.5" />
                                </button>
                                <button type="button" class="p-1 rounded text-gray-400 hover:text-red-600 hover:bg-red-50"
                                        @click.stop="openDelete(task)" title="Usuń">
                                    <TrashIcon class="h-3.5 w-3.5" />
                                </button>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="flatTasks.length === 0">
                        <td colspan="7" class="px-4 py-8 text-center text-sm text-gray-400">
                            Brak zadań. Kliknij „Dodaj zadanie" żeby utworzyć pierwsze.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </PageContent>

    <!-- Create/Edit Task Modal -->
    <Modal :open="taskModalOpen" externalOpen @toggleOpen="closeTaskModal">
        <template #title>
            {{ taskForm.id ? 'Edytuj zadanie' : 'Nowe zadanie' }}
        </template>
        <template #content>
            <form @submit.prevent="submitTask" class="space-y-4">
                <TextInput
                    v-model="taskForm.name"
                    name="task_name"
                    label="Nazwa"
                    :error="taskForm.errors.name"
                    required
                />

                <TextArea
                    v-model="taskForm.description"
                    name="task_description"
                    label="Opis"
                    :rows="3"
                    :error="taskForm.errors.description"
                />

                <div class="grid grid-cols-2 gap-4">
                    <SelectInput
                        v-model="taskForm.kanban_column"
                        name="task_kanban_column"
                        label="Kolumna"
                        :options="columnOptions"
                        :error="taskForm.errors.kanban_column"
                    />
                    <SelectInput
                        v-model="taskForm.priority"
                        name="task_priority"
                        label="Priorytet"
                        :options="priorityOptions"
                        :error="taskForm.errors.priority"
                        placeholder="Brak"
                    />
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Etykiety</label>
                    <div class="flex flex-wrap gap-2">
                        <label
                            v-for="lbl in labelOptions"
                            :key="lbl"
                            class="flex items-center gap-1.5 cursor-pointer"
                        >
                            <input
                                type="checkbox"
                                :value="lbl"
                                v-model="taskForm.labels"
                                class="rounded border-gray-300 text-primary-600 focus:ring-primary-500"
                            />
                            <span
                                class="text-xs font-semibold px-1.5 py-0.5 rounded uppercase tracking-wide"
                                :class="labelStyle(lbl)"
                            >{{ lbl }}</span>
                        </label>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Termin</label>
                    <input
                        type="date"
                        v-model="taskForm.due_date"
                        class="block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-primary-500 focus:ring-primary-500"
                    />
                    <p class="text-xs text-gray-400 mt-1">Przypisania zarządzasz po otwarciu karty zadania.</p>
                </div>
            </form>
        </template>
        <template #buttons="{ setIsOpen }">
            <Button :loading="taskForm.processing" @click.prevent="submitTask">
                {{ taskForm.id ? 'Zapisz' : 'Dodaj' }}
            </Button>
            <Button color="gray" variant="outline"
                    @click.prevent="() => { setIsOpen(false); closeTaskModal(); }">
                Anuluj
            </Button>
        </template>
    </Modal>

    <!-- Delete Task Modal -->
    <Modal :open="deleteTaskModalOpen" externalOpen type="danger" @toggleOpen="closeDeleteTask">
        <template #title>Usuń zadanie</template>
        <template #content>
            Czy na pewno chcesz usunąć zadanie <strong>{{ deletingTask?.name }}</strong>?
        </template>
        <template #buttons="{ setIsOpen }">
            <Button color="danger" :loading="deleteTaskLoading" @click.prevent="submitDeleteTask">
                Usuń
            </Button>
            <Button color="gray" variant="outline"
                    @click.prevent="() => { setIsOpen(false); closeDeleteTask(); }">
                Anuluj
            </Button>
        </template>
    </Modal>

    <!-- Board Settings Modal (kolumny / etykiety / priorytety) -->
    <Modal :open="boardSettingsOpen" externalOpen size="xl" @toggleOpen="boardSettingsOpen = false">
        <template #title>Edycja tablicy</template>
        <template #content>
            <div class="space-y-6">
                <!-- Kolumny -->
                <section>
                    <div class="flex items-center justify-between mb-2">
                        <h3 class="text-sm font-semibold text-gray-800">Kolumny kanbanu</h3>
                        <button type="button" class="text-xs text-primary-600 hover:underline"
                                @click="addColumn">+ Dodaj kolumnę</button>
                    </div>
                    <div class="space-y-2">
                        <div v-for="(col, idx) in cfgColumns" :key="idx"
                             class="flex items-center gap-2 p-2 bg-gray-50 rounded border border-gray-200">
                            <span class="h-3 w-3 rounded-full flex-shrink-0" :class="SWATCH_BY_COLOR[col.color]" />
                            <input v-model="col.name" type="text" placeholder="Nazwa kolumny"
                                   class="flex-1 text-sm border-gray-300 rounded focus:border-primary-500 focus:ring-primary-500" />
                            <select v-model="col.color"
                                    class="text-sm border-gray-300 rounded focus:border-primary-500 focus:ring-primary-500">
                                <option v-for="c in allowedColors" :key="c" :value="c">{{ c }}</option>
                            </select>
                            <button type="button" class="p-1 text-gray-400 hover:text-red-600"
                                    :disabled="cfgColumns.length <= 1" @click="cfgColumns.splice(idx,1)">
                                <XMarkIcon class="h-4 w-4" />
                            </button>
                        </div>
                    </div>
                    <p class="text-xs text-gray-400 mt-1">Zadania w usuniętej kolumnie zostaną przeniesione do pierwszej kolumny.</p>
                </section>

                <!-- Etykiety -->
                <section>
                    <div class="flex items-center justify-between mb-2">
                        <h3 class="text-sm font-semibold text-gray-800">Etykiety</h3>
                        <button type="button" class="text-xs text-primary-600 hover:underline"
                                @click="addLabel">+ Dodaj etykietę</button>
                    </div>
                    <div class="space-y-2">
                        <div v-for="(lbl, idx) in cfgLabels" :key="idx"
                             class="flex items-center gap-2 p-2 bg-gray-50 rounded border border-gray-200">
                            <span class="h-3 w-3 rounded-full flex-shrink-0" :class="SWATCH_BY_COLOR[lbl.color]" />
                            <input v-model="lbl.name" type="text" placeholder="Nazwa etykiety"
                                   class="flex-1 text-sm border-gray-300 rounded focus:border-primary-500 focus:ring-primary-500" />
                            <select v-model="lbl.color"
                                    class="text-sm border-gray-300 rounded focus:border-primary-500 focus:ring-primary-500">
                                <option v-for="c in allowedColors" :key="c" :value="c">{{ c }}</option>
                            </select>
                            <button type="button" class="p-1 text-gray-400 hover:text-red-600"
                                    @click="cfgLabels.splice(idx,1)">
                                <XMarkIcon class="h-4 w-4" />
                            </button>
                        </div>
                    </div>
                </section>

                <!-- Priorytety -->
                <section>
                    <div class="flex items-center justify-between mb-2">
                        <h3 class="text-sm font-semibold text-gray-800">Priorytety</h3>
                        <button type="button" class="text-xs text-primary-600 hover:underline"
                                @click="addPriority">+ Dodaj priorytet</button>
                    </div>
                    <div class="space-y-2">
                        <div v-for="(pr, idx) in cfgPriorities" :key="idx"
                             class="flex items-center gap-2 p-2 bg-gray-50 rounded border border-gray-200">
                            <span class="h-3 w-3 rounded-full flex-shrink-0" :class="SWATCH_BY_COLOR[pr.color]" />
                            <input v-model="pr.name" type="text" placeholder="Nazwa priorytetu"
                                   class="flex-1 text-sm border-gray-300 rounded focus:border-primary-500 focus:ring-primary-500" />
                            <select v-model="pr.color"
                                    class="text-sm border-gray-300 rounded focus:border-primary-500 focus:ring-primary-500">
                                <option v-for="c in allowedColors" :key="c" :value="c">{{ c }}</option>
                            </select>
                            <button type="button" class="p-1 text-gray-400 hover:text-red-600"
                                    @click="cfgPriorities.splice(idx,1)">
                                <XMarkIcon class="h-4 w-4" />
                            </button>
                        </div>
                    </div>
                    <p class="text-xs text-gray-400 mt-1">Usunięte priorytety zostaną wyczyszczone na zadaniach.</p>
                </section>
            </div>
        </template>
        <template #buttons="{ setIsOpen }">
            <Button :loading="boardSettingsLoading" @click.prevent="submitBoardSettings">
                Zapisz
            </Button>
            <Button color="gray" variant="outline"
                    @click.prevent="() => { setIsOpen(false); boardSettingsOpen = false; }">
                Anuluj
            </Button>
        </template>
    </Modal>

    <!-- Delete Project Modal -->
    <Modal :open="deleteProjectModalOpen" externalOpen type="danger"
           @toggleOpen="deleteProjectModalOpen = false">
        <template #title>Usuń projekt</template>
        <template #content>
            Czy na pewno chcesz usunąć cały projekt <strong>{{ project.name }}</strong>
            wraz ze wszystkimi zadaniami? Tej operacji nie można cofnąć.
        </template>
        <template #buttons="{ setIsOpen }">
            <Button color="danger" :loading="deleteProjectLoading" @click.prevent="submitDeleteProject">
                Usuń projekt
            </Button>
            <Button color="gray" variant="outline"
                    @click.prevent="() => { setIsOpen(false); deleteProjectModalOpen = false; }">
                Anuluj
            </Button>
        </template>
    </Modal>
</template>

<script setup lang="ts">
import { ref, computed, watch, nextTick } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import draggable from 'vuedraggable';
import axios from 'axios';
import { useToast } from '@brackets/vue-toastification';
import { PlusIcon, PencilSquareIcon, TrashIcon, Cog6ToothIcon, XMarkIcon, Squares2X2Icon, ListBulletIcon } from '@heroicons/vue/24/outline';
import {
    PageHeader, PageContent,
    TextInput, TextArea, SelectInput,
    Modal, Button, Avatar,
} from 'crafter/Components';

interface AdminUser {
    id: number;
    first_name: string;
    last_name: string;
    email: string;
}

interface Task {
    id: number;
    argo_project_id: number;
    name: string;
    description: string | null;
    kanban_column: string;
    priority: string | null;
    labels: string[] | null;
    due_date: string | null;
    assignees: AdminUser[] | null;
    position: number;
}

interface Project {
    id: number;
    name: string;
    description: string | null;
    icon: string | null;
    color: string | null;
}

interface ColumnCfg { key: string; name: string; color: string; }
interface NamedCfg  { name: string; color: string; }

interface Props {
    project: Project;
    tasksByColumn: Record<string, Task[]>;
    columns: Record<string, string>;
    columnColors: Record<string, string>;
    priorities: string[];
    priorityColors: Record<string, string>;
    labelOptions: string[];
    labelColors: Record<string, string>;
    boardConfig: {
        columns: ColumnCfg[];
        labels: NamedCfg[];
        priorities: NamedCfg[];
    };
    allowedColors: string[];
    users: AdminUser[];
}

const props = defineProps<Props>();
const toast = useToast();

// Board data for drag-and-drop
const boardData = ref<Record<string, Task[]>>({});

// Widok: kanban (default) lub lista. Trzymamy w localStorage per-projekt, żeby pamiętał wybór.
const viewStorageKey = `argo-task.view.${props.project.id}`;
const viewMode = ref<'kanban' | 'list'>(
    (typeof localStorage !== 'undefined' && (localStorage.getItem(viewStorageKey) as 'kanban' | 'list')) || 'kanban'
);
watch(viewMode, (v) => {
    if (typeof localStorage !== 'undefined') localStorage.setItem(viewStorageKey, v);
});

// Płaska lista zadań do widoku listy — posortowana wg kolejności kolumn, potem position.
const flatTasks = computed<Task[]>(() => {
    const out: Task[] = [];
    Object.keys(props.columns).forEach((col) => {
        (boardData.value[col] ?? []).forEach((t) => out.push(t));
    });
    return out;
});
const rebuildBoard = () => {
    const next: Record<string, Task[]> = {};
    Object.keys(props.columns).forEach((col) => {
        next[col] = [...(props.tasksByColumn[col] ?? [])];
    });
    boardData.value = next;
};
rebuildBoard();
watch(() => props.tasksByColumn, rebuildBoard, { deep: true });

const columnOptions = computed(() =>
    Object.entries(props.columns).map(([value, label]) => ({ value, label }))
);
const priorityOptions = computed(() => [
    { value: '', label: 'Brak' },
    ...props.priorities.map((p) => ({ value: p, label: p })),
]);
const userOptions = computed(() => [
    { value: '', label: 'Brak' },
    ...props.users.map((u) => ({
        value: u.id,
        label: `${u.first_name} ${u.last_name}`,
    })),
]);

// ─── Task form (create + edit) ───────────────────────────────────────────────
const taskModalOpen = ref(false);
const taskForm = useForm({
    id: null as number | null,
    name: '',
    description: '',
    kanban_column: 'do_zrobienia',
    priority: '',
    labels: [] as string[],
    due_date: '',
});

const firstColumnKey = computed(() => Object.keys(props.columns)[0] ?? 'do_zrobienia');

// ─── Inline edit nazwy projektu (Notion-style) ───────────────────────────────
const editingName = ref(false);
const nameDraft = ref('');
const nameInputEl = ref<HTMLInputElement | null>(null);

const startEditName = () => {
    nameDraft.value = props.project.name;
    editingName.value = true;
    nextTick(() => {
        nameInputEl.value?.focus();
        nameInputEl.value?.select();
    });
};

const cancelName = () => {
    editingName.value = false;
    nameDraft.value = '';
};

const saveName = () => {
    if (!editingName.value) return; // blur po escape
    const next = nameDraft.value.trim();
    if (!next || next === props.project.name) {
        cancelName();
        return;
    }
    router.patch(route('crafter.argo-task.projects.update', props.project.id), {
        name: next,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success('Nazwa projektu zaktualizowana.');
        },
        onError: (e) => {
            toast.error(Object.values(e)[0] as string);
            nameDraft.value = props.project.name;
        },
        onFinish: () => {
            editingName.value = false;
        },
    });
};

const openCreate = () => {
    taskForm.reset();
    taskForm.kanban_column = firstColumnKey.value;
    taskModalOpen.value = true;
};

const openCreateInColumn = (colKey: string) => {
    taskForm.reset();
    taskForm.kanban_column = colKey;
    taskModalOpen.value = true;
};

const openEdit = (task: Task) => {
    taskForm.id = task.id;
    taskForm.name = task.name;
    taskForm.description = task.description ?? '';
    taskForm.kanban_column = task.kanban_column;
    taskForm.priority = task.priority ?? '';
    taskForm.labels = [...(task.labels ?? [])];
    taskForm.due_date = task.due_date ?? '';
    taskModalOpen.value = true;
};

const closeTaskModal = () => {
    taskModalOpen.value = false;
    taskForm.reset();
};

const submitTask = () => {
    if (taskForm.id) {
        taskForm.patch(route('crafter.argo-task.tasks.update', taskForm.id), {
            onSuccess: () => { toast.success('Zadanie zaktualizowane.'); closeTaskModal(); },
            onError: (e) => toast.error(Object.values(e)[0] as string),
        });
    } else {
        taskForm.post(route('crafter.argo-task.tasks.store', props.project.id), {
            onSuccess: () => { toast.success('Zadanie dodane.'); closeTaskModal(); },
            onError: (e) => toast.error(Object.values(e)[0] as string),
        });
    }
};

// ─── Delete task ──────────────────────────────────────────────────────────────
const deleteTaskModalOpen = ref(false);
const deletingTask = ref<Task | null>(null);
const deleteTaskLoading = ref(false);

const openDelete = (task: Task) => {
    deletingTask.value = task;
    deleteTaskModalOpen.value = true;
};
const closeDeleteTask = () => {
    deleteTaskModalOpen.value = false;
    deletingTask.value = null;
};
const submitDeleteTask = () => {
    if (!deletingTask.value) return;
    deleteTaskLoading.value = true;
    router.delete(route('crafter.argo-task.tasks.destroy', deletingTask.value.id), {
        onSuccess: () => {
            toast.success('Zadanie usunięte.');
            Object.keys(boardData.value).forEach((col) => {
                boardData.value[col] = boardData.value[col].filter(
                    (t) => t.id !== deletingTask.value!.id
                );
            });
            closeDeleteTask();
        },
        onError: () => toast.error('Błąd podczas usuwania.'),
        onFinish: () => { deleteTaskLoading.value = false; },
    });
};

// ─── Board settings (kolumny / etykiety / priorytety) ───────────────────────
const boardSettingsOpen = ref(false);
const boardSettingsLoading = ref(false);
const allowedColors = computed(() => props.allowedColors ?? ['gray','blue','red','green','amber','orange','yellow','purple','pink','indigo','cyan']);
const cfgColumns = ref<ColumnCfg[]>([]);
const cfgLabels = ref<NamedCfg[]>([]);
const cfgPriorities = ref<NamedCfg[]>([]);

const openBoardSettings = () => {
    cfgColumns.value = props.boardConfig.columns.map((c) => ({ ...c }));
    cfgLabels.value = props.boardConfig.labels.map((l) => ({ ...l }));
    cfgPriorities.value = props.boardConfig.priorities.map((p) => ({ ...p }));
    boardSettingsOpen.value = true;
};

const addColumn = () => cfgColumns.value.push({ key: '', name: 'Nowa kolumna', color: 'gray' });
const addLabel = () => cfgLabels.value.push({ name: 'Nowa etykieta', color: 'gray' });
const addPriority = () => cfgPriorities.value.push({ name: 'NEW', color: 'gray' });

const submitBoardSettings = () => {
    boardSettingsLoading.value = true;
    router.put(route('crafter.argo-task.projects.config', props.project.id), {
        columns: cfgColumns.value,
        labels: cfgLabels.value,
        priorities: cfgPriorities.value,
    } as any, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success('Ustawienia tablicy zapisane.');
            boardSettingsOpen.value = false;
        },
        onError: (e) => toast.error(Object.values(e)[0] as string),
        onFinish: () => { boardSettingsLoading.value = false; },
    });
};

// ─── Delete project ───────────────────────────────────────────────────────────
const deleteProjectModalOpen = ref(false);
const deleteProjectLoading = ref(false);
const submitDeleteProject = () => {
    deleteProjectLoading.value = true;
    router.delete(route('crafter.argo-task.projects.destroy', props.project.id), {
        onSuccess: () => toast.success('Projekt usunięty.'),
        onError: () => toast.error('Błąd podczas usuwania projektu.'),
        onFinish: () => { deleteProjectLoading.value = false; },
    });
};

// ─── Navigate to task detail ──────────────────────────────────────────────────
const goToTask = (task: Task) => {
    router.visit(route('crafter.argo-task.tasks.show', task.id));
};

const isDragging = ref(false);
const dragEndedAt = ref(0);

const onDragStart = () => {
    isDragging.value = true;
    // Blokada zaznaczania tekstu na całej stronie podczas draga
    document.body.style.userSelect = 'none';
    (document.body.style as any).webkitUserSelect = 'none';
    window.getSelection()?.removeAllRanges();
};

const onDragEnd = () => {
    isDragging.value = false;
    dragEndedAt.value = Date.now();
    document.body.style.userSelect = '';
    (document.body.style as any).webkitUserSelect = '';
    window.getSelection()?.removeAllRanges();
};

const handleCardClick = (task: Task) => {
    // Ignoruj klik jeśli właśnie skończyliśmy dragować (cooldown 250ms)
    if (isDragging.value || Date.now() - dragEndedAt.value < 250) return;
    goToTask(task);
};

// ─── Drag and drop ────────────────────────────────────────────────────────────
const onDragChange = (event: any, targetColumn: string) => {
    if (event.added) {
        const task: Task = event.added.element;
        task.kanban_column = targetColumn;
        axios.post(route('crafter.argo-task.tasks.move', task.id), {
            kanban_column: targetColumn,
        }).catch(() => toast.error('Błąd podczas przenoszenia karty.'));
    }
};

// ─── Helpers ──────────────────────────────────────────────────────────────────
const formatDate = (date: string) => {
    if (!date) return '';
    return new Date(date).toLocaleDateString('pl-PL', { day: 'numeric', month: 'short', year: 'numeric' });
};

// Mapowanie kolor → klasy Tailwind. Statyczne stałe ciągi, by purge nie wyciął klas.
const DOT_BY_COLOR: Record<string, string> = {
    gray: 'bg-gray-400', blue: 'bg-blue-500', red: 'bg-red-500',
    green: 'bg-green-500', amber: 'bg-amber-500', orange: 'bg-orange-500',
    yellow: 'bg-yellow-500', purple: 'bg-purple-500', pink: 'bg-pink-500',
    indigo: 'bg-indigo-500', cyan: 'bg-cyan-500',
};
const CHIP_BY_COLOR: Record<string, string> = {
    gray: 'bg-gray-100 text-gray-600', blue: 'bg-blue-100 text-blue-700',
    red: 'bg-red-100 text-red-700', green: 'bg-green-100 text-green-700',
    amber: 'bg-amber-100 text-amber-700', orange: 'bg-orange-100 text-orange-700',
    yellow: 'bg-yellow-100 text-yellow-700', purple: 'bg-purple-100 text-purple-700',
    pink: 'bg-pink-100 text-pink-700', indigo: 'bg-indigo-100 text-indigo-700',
    cyan: 'bg-cyan-100 text-cyan-700',
};
const SWATCH_BY_COLOR: Record<string, string> = {
    gray: 'bg-gray-400', blue: 'bg-blue-500', red: 'bg-red-500',
    green: 'bg-green-500', amber: 'bg-amber-500', orange: 'bg-orange-500',
    yellow: 'bg-yellow-400', purple: 'bg-purple-500', pink: 'bg-pink-500',
    indigo: 'bg-indigo-500', cyan: 'bg-cyan-500',
};

const columnDot = (col: string): string =>
    DOT_BY_COLOR[props.columnColors?.[col]] ?? 'bg-gray-400';

const priorityStyle = (priority: string): string =>
    CHIP_BY_COLOR[props.priorityColors?.[priority]] ?? 'bg-gray-100 text-gray-500';

const labelStyle = (label: string): string =>
    CHIP_BY_COLOR[props.labelColors?.[label]] ?? 'bg-gray-100 text-gray-600';
</script>

<style scoped>
/* Blokada zaznaczania tekstu podczas draga (forceFallback SortableJS) */
:deep(.argo-chosen),
:deep(.argo-drag),
:deep(.argo-chosen *),
:deep(.argo-drag *) {
    user-select: none;
    -webkit-user-select: none;
}
:deep(.argo-drag) {
    opacity: 0.9;
    transform: rotate(1deg);
    box-shadow: 0 10px 20px rgba(0, 0, 0, 0.15);
}
</style>
