<template>
    <PageHeader :title="form.name || 'Zadanie'">
        <Button
            variant="outline"
            color="gray"
            :leftIcon="ArrowLeftIcon"
            :as="Link"
            :href="route('crafter.argo-task.projects.show', project.id)"
        >
            Powrót do {{ project.name }}
        </Button>
        <Button
            :leftIcon="CheckIcon"
            :loading="form.processing"
            @click.prevent="save"
        >
            Zapisz
        </Button>
    </PageHeader>

    <PageContent>
        <div class="w-full py-6">
            <!-- Icon -->
            <div class="mb-6">
                <div class="h-16 w-16 flex items-center justify-center rounded-full bg-red-500 text-white">
                    <CheckCircleIcon class="h-10 w-10" />
                </div>
            </div>

            <!-- Title -->
            <input
                v-model="form.name"
                type="text"
                placeholder="Bez tytułu"
                class="w-full border-0 focus:ring-0 p-0 text-4xl font-bold text-gray-900 placeholder-gray-300 mb-6 bg-transparent"
            />

            <!-- Metadata row -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-x-6 gap-y-4 mb-8 pb-6 border-b border-gray-200">
                <!-- Status -->
                <div>
                    <div class="text-xs text-gray-400 mb-1.5 flex items-center gap-1">
                        <ArrowPathIcon class="h-3 w-3" /> Status
                    </div>
                    <select
                        v-model="form.kanban_column"
                        class="w-full text-sm border-0 bg-gray-50 rounded-md py-1 px-2 focus:ring-1 focus:ring-primary-500"
                        :class="columnPill(form.kanban_column)"
                    >
                        <option v-for="(label, key) in columns" :key="key" :value="key">
                            {{ label }}
                        </option>
                    </select>
                </div>

                <!-- Priority -->
                <div>
                    <div class="text-xs text-gray-400 mb-1.5 flex items-center gap-1">
                        <FlagIcon class="h-3 w-3" /> Priority
                    </div>
                    <select
                        v-model="form.priority"
                        class="w-full text-sm border-0 bg-gray-50 rounded-md py-1 px-2 focus:ring-1 focus:ring-primary-500"
                        :class="form.priority ? priorityStyle(form.priority) : ''"
                    >
                        <option value="">Brak</option>
                        <option v-for="p in priorities" :key="p" :value="p">{{ p }}</option>
                    </select>
                </div>

                <!-- Due date -->
                <div>
                    <div class="text-xs text-gray-400 mb-1.5 flex items-center gap-1">
                        <CalendarIcon class="h-3 w-3" /> Termin
                    </div>
                    <input
                        type="date"
                        v-model="form.due_date"
                        class="w-full text-sm border-0 bg-gray-50 rounded-md py-1 px-2 focus:ring-1 focus:ring-primary-500"
                    />
                </div>

                <!-- Auto-save status -->
                <div>
                    <div class="text-xs text-gray-400 mb-1.5 flex items-center gap-1">
                        <ArrowPathIcon class="h-3 w-3" /> Stan treści
                    </div>
                    <div class="text-xs text-gray-500 py-1">
                        <span v-if="saveStatus === 'saving'" class="text-amber-600">Zapisywanie…</span>
                        <span v-else-if="saveStatus === 'saved'" class="text-green-600">Zapisano</span>
                        <span v-else class="text-gray-400">Gotowe</span>
                    </div>
                </div>

                <!-- Assignees (full width) -->
                <div class="col-span-2 md:col-span-4">
                    <div class="text-xs text-gray-400 mb-1.5 flex items-center gap-1">
                        <UserIcon class="h-3 w-3" /> Przypisani
                    </div>
                    <AssigneesPicker
                        :task-id="task.id"
                        :initial="assignees"
                        :users="users"
                    />
                </div>

                <!-- Labels (full width) -->
                <div class="col-span-2 md:col-span-4">
                    <div class="text-xs text-gray-400 mb-1.5 flex items-center gap-1">
                        <TagIcon class="h-3 w-3" /> Type / Etykiety
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <label
                            v-for="lbl in labelOptions"
                            :key="lbl"
                            class="flex items-center gap-1.5 cursor-pointer"
                        >
                            <input
                                type="checkbox"
                                :value="lbl"
                                v-model="form.labels"
                                class="rounded border-gray-300 text-primary-600 focus:ring-primary-500"
                            />
                            <span
                                class="text-xs font-semibold px-1.5 py-0.5 rounded uppercase tracking-wide"
                                :class="labelStyle(lbl)"
                            >{{ lbl }}</span>
                        </label>
                    </div>
                </div>
            </div>

            <!-- Short description -->
            <div class="mb-6">
                <textarea
                    v-model="form.description"
                    rows="2"
                    placeholder="Krótki opis…"
                    class="w-full border-0 focus:ring-0 p-0 text-sm text-gray-600 placeholder-gray-300 bg-transparent resize-none"
                />
            </div>

            <!-- Tabs: Treść | Aktywność | Załączniki -->
            <div class="border-b border-gray-200 mb-6">
                <nav class="flex gap-4 text-sm">
                    <button
                        type="button"
                        class="py-2 px-1 border-b-2 font-medium"
                        :class="activeTab === 'content' ? 'border-primary-500 text-primary-600' : 'border-transparent text-gray-500 hover:text-gray-700'"
                        @click="activeTab = 'content'"
                    >Treść</button>
                    <button
                        type="button"
                        class="py-2 px-1 border-b-2 font-medium"
                        :class="activeTab === 'activity' ? 'border-primary-500 text-primary-600' : 'border-transparent text-gray-500 hover:text-gray-700'"
                        @click="activeTab = 'activity'"
                    >Aktywność</button>
                    <button
                        type="button"
                        class="py-2 px-1 border-b-2 font-medium"
                        :class="activeTab === 'attachments' ? 'border-primary-500 text-primary-600' : 'border-transparent text-gray-500 hover:text-gray-700'"
                        @click="activeTab = 'attachments'"
                    >Załączniki ({{ attachmentList.length }})</button>
                </nav>
            </div>

            <!-- Tab: content -->
            <div v-show="activeTab === 'content'">
                <WorkspaceEditor
                    v-model="form.content"
                    :task-id="task.id"
                    placeholder="Wpisz / aby wybrać blok…"
                    @saving="saveStatus = 'saving'"
                    @saved="saveStatus = 'saved'"
                />
            </div>

            <!-- Tab: activity -->
            <div v-show="activeTab === 'activity'">
                <ActivityTimeline :activities="activityList" />
            </div>

            <!-- Tab: attachments -->
            <div v-show="activeTab === 'attachments'">
                <div class="mb-3">
                    <label class="inline-flex items-center gap-2 text-sm text-primary-600 hover:text-primary-700 cursor-pointer">
                        <input type="file" class="hidden" @change="onAttachmentSelected" />
                        <span>+ Dodaj załącznik</span>
                    </label>
                </div>
                <ul v-if="attachmentList.length" class="space-y-2">
                    <li
                        v-for="att in attachmentList"
                        :key="att.activity_id"
                        class="flex items-center gap-3 p-2 rounded-md border border-gray-200 bg-gray-50"
                    >
                        <span class="text-xl">📎</span>
                        <a :href="att.url" target="_blank" rel="noopener noreferrer" class="text-sm text-primary-600 hover:underline flex-1 truncate">
                            {{ att.name }}
                        </a>
                        <span class="text-xs text-gray-400">{{ formatSize(att.size) }}</span>
                        <button type="button" class="text-xs text-red-500 hover:text-red-700" @click="removeAttachment(att)">Usuń</button>
                    </li>
                </ul>
                <div v-else class="text-sm text-gray-400">Brak załączników.</div>
            </div>
        </div>
    </PageContent>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import axios from 'axios';
import { useForm, Link } from '@inertiajs/vue3';
import { useToast } from '@brackets/vue-toastification';
import {
    ArrowLeftIcon, CheckIcon, CheckCircleIcon,
    ArrowPathIcon, UserIcon, FlagIcon, CalendarIcon, TagIcon,
} from '@heroicons/vue/24/outline';
import {
    PageHeader, PageContent, Button,
} from 'crafter/Components';
import WorkspaceEditor from 'crafter/Components/WorkspaceEditor/WorkspaceEditor.vue';
import AssigneesPicker from 'crafter/Components/AssigneesPicker.vue';
import ActivityTimeline from 'crafter/Components/ActivityTimeline.vue';

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
    content: string | null;
    kanban_column: string;
    priority: string | null;
    labels: string[] | null;
    due_date: string | null;
}

interface Project {
    id: number;
    name: string;
}

interface Activity {
    id: number;
    action: string;
    payload: Record<string, any> | null;
    created_at: string;
    user?: AdminUser | null;
}

interface Attachment {
    activity_id: number;
    name: string;
    url: string;
    size?: number | null;
    mime?: string | null;
    path?: string;
}

interface Props {
    task: Task;
    project: Project;
    columns: Record<string, string>;
    columnColors?: Record<string, string>;
    priorities: string[];
    priorityColors?: Record<string, string>;
    labelOptions: string[];
    labelColors?: Record<string, string>;
    users: AdminUser[];
    assignees: AdminUser[];
    activities: Activity[];
    attachments: Attachment[];
}

const props = defineProps<Props>();
const toast = useToast();

const form = useForm({
    name: props.task.name,
    description: props.task.description ?? '',
    content: props.task.content ?? '',
    kanban_column: props.task.kanban_column,
    priority: props.task.priority ?? '',
    labels: [...(props.task.labels ?? [])] as string[],
    due_date: props.task.due_date ?? '',
});

const activeTab = ref<'content' | 'activity' | 'attachments'>('content');
const saveStatus = ref<'idle' | 'saving' | 'saved'>('idle');
const activityList = ref<Activity[]>([...(props.activities ?? [])]);
const attachmentList = ref<Attachment[]>([...(props.attachments ?? [])]);

const save = () => {
    form.patch(route('crafter.argo-task.tasks.update', props.task.id), {
        preserveScroll: true,
        onSuccess: () => toast.success('Zapisano.'),
        onError: (e) => toast.error(Object.values(e)[0] as string),
    });
};

const onAttachmentSelected = async (event: Event) => {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file) return;
    const formData = new FormData();
    formData.append('file', file);
    try {
        const resp = await axios.post(route('crafter.argo-task.tasks.attachments.store', props.task.id), formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        attachmentList.value.push(resp.data.attachment);
        toast.success('Załącznik dodany.');
    } catch (e: any) {
        toast.error(e?.response?.data?.error ?? 'Nie udało się wgrać pliku.');
    } finally {
        input.value = '';
    }
};

const removeAttachment = async (att: Attachment) => {
    try {
        await axios.delete(route('crafter.argo-task.tasks.attachments.destroy', [props.task.id, att.activity_id]));
        attachmentList.value = attachmentList.value.filter(a => a.activity_id !== att.activity_id);
    } catch {
        toast.error('Nie udało się usunąć załącznika.');
    }
};

const formatSize = (bytes?: number | null): string => {
    if (!bytes) return '';
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
};

// Style helpers — kolory pochodzą z konfiguracji projektu (patrz ArgoProject).
const TEXT_BY_COLOR: Record<string, string> = {
    gray: 'text-gray-600', blue: 'text-blue-700', red: 'text-red-700',
    green: 'text-green-700', amber: 'text-amber-700', orange: 'text-orange-700',
    yellow: 'text-yellow-700', purple: 'text-purple-700', pink: 'text-pink-700',
    indigo: 'text-indigo-700', cyan: 'text-cyan-700',
};
const CHIP_BY_COLOR: Record<string, string> = {
    gray: 'bg-gray-100 text-gray-600', blue: 'bg-blue-100 text-blue-700',
    red: 'bg-red-100 text-red-700', green: 'bg-green-100 text-green-700',
    amber: 'bg-amber-100 text-amber-700', orange: 'bg-orange-100 text-orange-700',
    yellow: 'bg-yellow-100 text-yellow-700', purple: 'bg-purple-100 text-purple-700',
    pink: 'bg-pink-100 text-pink-700', indigo: 'bg-indigo-100 text-indigo-700',
    cyan: 'bg-cyan-100 text-cyan-700',
};

const columnPill = (col: string): string =>
    TEXT_BY_COLOR[props.columnColors?.[col] ?? ''] ?? '';

const priorityStyle = (priority: string): string =>
    TEXT_BY_COLOR[props.priorityColors?.[priority] ?? ''] ?? '';

const labelStyle = (label: string): string =>
    CHIP_BY_COLOR[props.labelColors?.[label] ?? ''] ?? 'bg-gray-100 text-gray-600';
</script>
