import { VisitOptions } from "@inertiajs/core";
import { useForm } from "@inertiajs/vue3";
import debounce from "lodash/debounce";
import pick from "lodash/pick";
import pickBy from "lodash/pickBy";

import { computed, watch } from "vue";

export function useListingFilters(
  url: string,
  filters: Record<string, any>,
  customOptions?: VisitOptions
) {
  const filtersForm = useForm(filters);

  const activeFiltersCount = computed(() => {
    return Object.values(JSON.parse(JSON.stringify(filtersForm.data()))).filter(
      (item: any) => !!item?.length
    ).length;
  });

  const submitFilters = () => {
    filtersForm
      .transform((data) => {
        return {
          ...route().params,
          page: 1,
          filter: {
            ...pickBy({
              ...route().params.filter,
              ...data,
            }),
          },
        };
      })
      .get(url, {
        ...customOptions,
        preserveScroll: true,
        preserveState: true,
      });
  };

  const resetFilters = () => {
    Object.keys(filters).forEach((key) => {
      filtersForm[key] = null;
    });
  };

  watch(
    () => pick(filtersForm, Object.keys(filters)),
    debounce(() => submitFilters(), 500)
  );

  return { filtersForm, submitFilters, resetFilters, activeFiltersCount };
}
