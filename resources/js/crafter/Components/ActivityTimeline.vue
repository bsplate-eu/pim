<template>
    <ul class="space-y-3">
        <li v-for="item in activities" :key="item.id" class="flex items-start gap-3">
            <span class="mt-1 inline-flex items-center justify-center w-7 h-7 rounded-full bg-gray-100 text-gray-500 text-xs font-semibold flex-shrink-0">
                {{ initialsOf(item.user) }}
            </span>
            <div class="flex-1 min-w-0">
                <div class="text-sm text-gray-800">
                    <strong>{{ fullName(item.user) || 'System' }}</strong>
                    <span class="text-gray-600"> {{ labelFor(item.action) }}</span>
                    <template v-if="item.action === 'status_changed'">
                        <span class="text-gray-500"> → <code class="text-xs bg-gray-100 px-1 rounded">{{ item.payload?.to }}</code></span>
                    </template>
                    <template v-else-if="item.action === 'attachment_added' && item.payload?.name">
                        <span class="text-gray-500"> {{ item.payload.name }}</span>
                    </template>
                </div>
                <div class="text-xs text-gray-400 mt-0.5">{{ timeAgo(item.created_at) }}</div>
            </div>
        </li>
        <li v-if="!activities.length" class="text-sm text-gray-400">Brak aktywności.</li>
    </ul>
</template>

<script setup lang="ts">
interface Activity {
    id: number;
    action: string;
    payload: Record<string, any> | null;
    created_at: string;
    user?: { id: number; first_name: string; last_name: string; email: string } | null;
}

defineProps<{
    activities: Activity[];
}>();

const fullName = (u?: Activity['user']) => u ? `${u.first_name} ${u.last_name}`.trim() : '';

const initialsOf = (u?: Activity['user']) => {
    if (!u) return '?';
    return `${(u.first_name || '?').charAt(0)}${(u.last_name || '').charAt(0)}`.toUpperCase();
};

const labelFor = (action: string): string => {
    switch (action) {
        case 'created':          return 'utworzył zadanie';
        case 'updated':          return 'zaktualizował treść';
        case 'assigned':         return 'przypisał użytkownika';
        case 'unassigned':       return 'odpiął użytkownika';
        case 'mentioned':        return 'wspomniał o użytkownikach';
        case 'status_changed':   return 'zmienił status';
        case 'attachment_added': return 'dodał załącznik';
        case 'commented':        return 'skomentował';
        default:                 return action;
    }
};

const timeAgo = (iso: string): string => {
    const t = new Date(iso).getTime();
    const diff = Math.max(0, Math.round((Date.now() - t) / 1000));
    if (diff < 60) return `${diff}s temu`;
    if (diff < 3600) return `${Math.round(diff / 60)} min temu`;
    if (diff < 86400) return `${Math.round(diff / 3600)} h temu`;
    return `${Math.round(diff / 86400)} d temu`;
};
</script>
