import {
    provide,
    inject,
    ref,
    computed,
    watch,
    onUnmounted,
    type Ref,
    type ComputedRef,
} from "vue";

interface ActiveRegister {
    register: (id: number, active: boolean) => void;
}

const KEY = Symbol("sidebar-active");
let __idSeq = 0;
const nextId = () => ++__idSeq;

export function useSidebarActiveProvider(initialOpen = false) {
    const activeIds = ref<Set<number>>(new Set());
    const hasActive = computed(() => activeIds.value.size > 0);
    const isOpen = ref<boolean>(initialOpen);

    const parent = inject<ActiveRegister | null>(KEY, null);
    const myId = nextId();

    provide<ActiveRegister>(KEY, {
        register(id: number, active: boolean) {
            const set = new Set(activeIds.value);
            if (active) set.add(id);
            else set.delete(id);
            activeIds.value = set;
        },
    });

    // Auto-open when contains active leaf; propagate up to parent group.
    watch(
        hasActive,
        (v) => {
            if (v) isOpen.value = true;
            parent?.register(myId, v);
        },
        { immediate: true }
    );

    onUnmounted(() => parent?.register(myId, false));

    return { isOpen, hasActive };
}

export function useSidebarActiveConsumer(
    active: Ref<boolean> | ComputedRef<boolean>
) {
    const parent = inject<ActiveRegister | null>(KEY, null);
    if (!parent) return;
    const id = nextId();

    watch(active, (v) => parent.register(id, v), { immediate: true });
    onUnmounted(() => parent.register(id, false));
}
