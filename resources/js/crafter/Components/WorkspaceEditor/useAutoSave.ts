import { ref, watch, onBeforeUnmount } from 'vue';
import axios from 'axios';

export type SaveStatus = 'idle' | 'saving' | 'saved' | 'error';

export function useAutoSave(
    taskId: number | string,
    getContent: () => string,
    delay = 2000,
) {
    const status = ref<SaveStatus>('idle');
    const lastSavedAt = ref<Date | null>(null);
    const errorMessage = ref<string | null>(null);
    let timer: ReturnType<typeof setTimeout> | null = null;
    let inflight = false;
    let pending = false;

    const flush = async () => {
        if (inflight) {
            pending = true;
            return;
        }
        inflight = true;
        status.value = 'saving';
        try {
            await axios.patch(route('crafter.argo-task.tasks.content', taskId), {
                content: getContent(),
            });
            lastSavedAt.value = new Date();
            status.value = 'saved';
            errorMessage.value = null;
        } catch (e: any) {
            status.value = 'error';
            errorMessage.value = e?.response?.data?.message ?? 'Nie udało się zapisać.';
        } finally {
            inflight = false;
            if (pending) {
                pending = false;
                // debounce next run
                schedule();
            }
        }
    };

    const schedule = () => {
        if (timer) clearTimeout(timer);
        timer = setTimeout(() => {
            flush();
        }, delay);
    };

    const trigger = () => {
        status.value = 'saving';
        schedule();
    };

    onBeforeUnmount(() => {
        if (timer) clearTimeout(timer);
    });

    return { status, lastSavedAt, errorMessage, trigger, flush };
}
