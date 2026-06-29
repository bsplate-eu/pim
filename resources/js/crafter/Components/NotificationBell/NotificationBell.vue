<template>
    <div ref="root" class="relative">
        <button
            type="button"
            class="relative p-2 rounded-md text-gray-500 hover:text-gray-700 hover:bg-gray-100 focus:outline-none"
            @click="toggleOpen"
            aria-label="Notyfikacje"
        >
            <BellIcon class="h-5 w-5" />
            <span
                v-if="unreadCount > 0"
                class="absolute -top-0.5 -right-0.5 inline-flex items-center justify-center min-w-[16px] h-[16px] px-1 rounded-full text-[10px] font-semibold bg-red-500 text-white"
            >
                {{ unreadCount > 99 ? '99+' : unreadCount }}
            </span>
        </button>

        <div
            v-if="open"
            class="absolute right-0 mt-2 w-80 max-h-[420px] overflow-y-auto bg-white rounded-lg shadow-xl border border-gray-200 z-50"
        >
            <div class="flex items-center justify-between px-3 py-2 border-b border-gray-100">
                <span class="text-sm font-semibold text-gray-900">Notyfikacje</span>
                <button
                    v-if="unreadCount > 0"
                    type="button"
                    class="text-xs text-primary-600 hover:text-primary-700"
                    @click="markAllRead"
                >
                    Oznacz wszystkie jako przeczytane
                </button>
            </div>
            <div v-if="loading" class="p-4 text-center text-sm text-gray-400">Ładowanie…</div>
            <div v-else-if="!notifications.length" class="p-4 text-center text-sm text-gray-400">Brak notyfikacji</div>
            <ul v-else class="divide-y divide-gray-100">
                <li
                    v-for="n in notifications"
                    :key="n.id"
                    class="px-3 py-2 hover:bg-gray-50 cursor-pointer"
                    :class="{ 'bg-primary-50/30': !n.read_at }"
                    @click="openNotification(n)"
                >
                    <div class="flex items-start gap-2">
                        <span class="mt-0.5 inline-flex items-center justify-center w-6 h-6 rounded-full bg-primary-100 text-primary-700 text-xs font-bold">
                            {{ iconFor(n.data?.type) }}
                        </span>
                        <div class="flex-1 min-w-0">
                            <div class="text-sm text-gray-800">
                                <template v-if="n.data?.type === 'mention'">
                                    <strong>{{ n.data.mentioned_by_name || 'Ktoś' }}</strong> wspomniał Cię w
                                    <strong>{{ n.data.task_name }}</strong>
                                </template>
                                <template v-else-if="n.data?.type === 'assigned'">
                                    <strong>{{ n.data.assigned_by_name || 'Ktoś' }}</strong> przypisał Cię do
                                    <strong>{{ n.data.task_name }}</strong>
                                </template>
                                <template v-else-if="n.data?.type === 'mail'">
                                    <strong>{{ mailTitle(n.data) }}</strong>
                                </template>
                                <template v-else-if="n.data?.type === 'due'">
                                    Termin dziś: <strong>{{ n.data.task_name }}</strong>
                                </template>
                                <template v-else>
                                    {{ n.data?.task_name || 'Notyfikacja' }}
                                </template>
                            </div>
                            <div v-if="n.data?.type === 'mail' && (n.data?.from || n.data?.subject)" class="text-xs text-gray-500 mt-0.5 line-clamp-2">
                                {{ [n.data.from, n.data.subject].filter(Boolean).join(': ') }}
                            </div>
                            <div v-else-if="n.data?.excerpt" class="text-xs text-gray-500 mt-0.5 line-clamp-2">
                                {{ n.data.excerpt }}
                            </div>
                            <div class="text-[11px] text-gray-400 mt-1">{{ timeAgo(n.created_at) }}</div>
                        </div>
                        <span v-if="!n.read_at" class="mt-1 inline-block w-2 h-2 rounded-full bg-primary-500" />
                    </div>
                </li>
            </ul>
        </div>
    </div>
</template>

<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount } from 'vue';
import axios from 'axios';
import { BellIcon } from '@heroicons/vue/24/outline';
import { router, usePage } from '@inertiajs/vue3';

interface Notif {
    id: string;
    data: Record<string, any>;
    read_at: string | null;
    created_at: string;
}

const open = ref(false);
const loading = ref(false);
const notifications = ref<Notif[]>([]);
const unreadCount = ref<number>(0);
const root = ref<HTMLElement | null>(null);

const page = usePage();
const initialUnread = (page.props.auth as any)?.unreadNotifications ?? 0;
unreadCount.value = Number(initialUnread);

const fetchList = async () => {
    loading.value = true;
    try {
        const resp = await axios.get(route('crafter.argo-task.notifications.index'));
        notifications.value = resp.data.notifications ?? [];
        unreadCount.value = resp.data.unread_count ?? 0;
    } finally {
        loading.value = false;
    }
};

const POLL_MS = 30000;
let pollTimer: ReturnType<typeof setInterval> | null = null;

// Lekki polling — odświeża badge (i listę, gdy panel otwarty) bez migotania "Ładowanie…".
const poll = async () => {
    if (typeof document !== 'undefined' && document.visibilityState === 'hidden') return;
    try {
        const resp = await axios.get(route('crafter.argo-task.notifications.index'));
        unreadCount.value = resp.data.unread_count ?? 0;
        if (open.value) notifications.value = resp.data.notifications ?? [];
    } catch {/* ignore */}
};

const toggleOpen = async () => {
    open.value = !open.value;
    if (open.value) await fetchList();
};

const close = () => { open.value = false; };

const openNotification = async (n: Notif) => {
    if (!n.read_at) {
        try {
            const resp = await axios.post(route('crafter.argo-task.notifications.read', n.id));
            unreadCount.value = resp.data.unread_count ?? Math.max(0, unreadCount.value - 1);
            n.read_at = new Date().toISOString();
        } catch {/* ignore */}
    }
    // Mail: na desktopie kierujemy do desktopowej skrzynki (data.url to deeplink mobilny /m/mail).
    const url = n.data?.type === 'mail' ? route('crafter.argo-mail.index') : n.data?.url;
    if (url) {
        close();
        router.visit(url);
    }
};

const markAllRead = async () => {
    try {
        await axios.post(route('crafter.argo-task.notifications.readAll'));
        unreadCount.value = 0;
        notifications.value = notifications.value.map(n => ({ ...n, read_at: n.read_at ?? new Date().toISOString() }));
    } catch {/* ignore */}
};

const iconFor = (type?: string) => {
    if (type === 'mention') return '@';
    if (type === 'assigned') return '👤';
    if (type === 'mail') return '📧';
    if (type === 'due') return '⏰';
    return '🔔';
};

const mailTitle = (d: Record<string, any>) => {
    const c = Number(d?.count || 1);
    return c > 1 ? `Nowe wiadomości (${c})` : 'Nowy e-mail';
};

const timeAgo = (iso: string): string => {
    const t = new Date(iso).getTime();
    const diff = Math.max(0, Math.round((Date.now() - t) / 1000));
    if (diff < 60) return `${diff}s temu`;
    if (diff < 3600) return `${Math.round(diff / 60)} min temu`;
    if (diff < 86400) return `${Math.round(diff / 3600)} h temu`;
    return `${Math.round(diff / 86400)} d temu`;
};

// Click-outside: zamknij panel, gdy klik poza komponentem.
const outside = (e: MouseEvent) => {
    if (!open.value) return;
    if (root.value && !root.value.contains(e.target as Node)) close();
};

onMounted(() => {
    document.addEventListener('click', outside);
    poll();
    pollTimer = setInterval(poll, POLL_MS);
});
onBeforeUnmount(() => {
    document.removeEventListener('click', outside);
    if (pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
    }
});
</script>
