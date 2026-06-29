<template>
    <div>
        <div class="flex items-center flex-wrap gap-2">
            <span
                v-for="u in assignees"
                :key="u.id"
                class="inline-flex items-center gap-1.5 bg-gray-100 rounded-full pl-1 pr-2 py-0.5 text-xs"
            >
                <span class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-primary-500 text-white text-[10px] font-semibold">
                    {{ initials(u) }}
                </span>
                <span>{{ u.first_name }} {{ u.last_name }}</span>
                <button type="button" class="text-gray-400 hover:text-red-500" @click="remove(u.id)" title="Usuń">×</button>
            </span>

            <button
                type="button"
                class="inline-flex items-center gap-1 text-xs text-primary-600 hover:text-primary-700 border border-dashed border-gray-300 rounded-full px-2 py-0.5"
                @click="showPicker = !showPicker"
            >
                + Dodaj
            </button>
        </div>

        <div v-if="showPicker" class="mt-2 p-2 bg-white border border-gray-200 rounded-md shadow-sm max-w-md">
            <input
                v-model="query"
                type="text"
                placeholder="Szukaj użytkownika…"
                class="w-full text-sm border border-gray-200 rounded px-2 py-1 mb-2 focus:outline-none focus:ring-1 focus:ring-primary-500"
            />
            <ul class="max-h-48 overflow-y-auto">
                <li
                    v-for="u in filteredUsers"
                    :key="u.id"
                    class="flex items-center gap-2 px-2 py-1 rounded text-sm hover:bg-gray-100 cursor-pointer"
                    @click="add(u.id)"
                >
                    <span class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-gray-200 text-[10px] font-semibold">
                        {{ initials(u) }}
                    </span>
                    <span class="flex-1">{{ u.first_name }} {{ u.last_name }}</span>
                    <span class="text-xs text-gray-400">{{ u.email }}</span>
                </li>
                <li v-if="!filteredUsers.length" class="px-2 py-1 text-xs text-gray-400">Brak dostępnych użytkowników</li>
            </ul>
        </div>
    </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue';
import axios from 'axios';

interface User {
    id: number;
    first_name: string;
    last_name: string;
    email: string;
}

const props = defineProps<{
    taskId: number | string;
    initial: User[];
    users: User[];
}>();

const assignees = ref<User[]>([...props.initial]);
const showPicker = ref(false);
const query = ref('');

const filteredUsers = computed(() => {
    const assignedIds = new Set(assignees.value.map(u => u.id));
    const q = query.value.toLowerCase();
    return props.users
        .filter(u => !assignedIds.has(u.id))
        .filter(u => {
            if (!q) return true;
            return `${u.first_name} ${u.last_name} ${u.email}`.toLowerCase().includes(q);
        })
        .slice(0, 20);
});

const initials = (u: User) => `${(u.first_name || '?').charAt(0)}${(u.last_name || '').charAt(0)}`.toUpperCase();

const add = async (userId: number) => {
    try {
        const resp = await axios.post(route('crafter.argo-task.tasks.assignees.store', props.taskId), {
            admin_user_id: userId,
        });
        assignees.value = resp.data.assignees ?? assignees.value;
        showPicker.value = false;
        query.value = '';
    } catch {/* ignore */}
};

const remove = async (userId: number) => {
    try {
        const resp = await axios.delete(route('crafter.argo-task.tasks.assignees.destroy', [props.taskId, userId]));
        assignees.value = resp.data.assignees ?? assignees.value.filter(u => u.id !== userId);
    } catch {/* ignore */}
};
</script>
