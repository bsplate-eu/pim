import { usePage } from "@inertiajs/vue3";
import { computed } from "vue";

/**
 * Ludzkie nazwy uprawnień dla ekranów „Role" i „Uprawnienia".
 *
 * Mapę składa App\Support\PermissionLabels i wysyła jako prop strony
 * `permissionLabels`. Klucz bez wpisu wraca surowy — dzięki temu nowe
 * uprawnienie od razu widać na liście, nawet zanim dostanie etykietę.
 */
export function usePermissionLabels() {
  const labels = computed<Record<string, string>>(
    () => ((usePage().props as any).permissionLabels ?? {}) as Record<string, string>
  );

  const permissionLabel = (key: string | number): string => {
    const name = String(key);

    return labels.value[name] ?? name;
  };

  return { permissionLabel };
}
