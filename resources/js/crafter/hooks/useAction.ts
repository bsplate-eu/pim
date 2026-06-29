import { useToast } from "@brackets/vue-toastification";
import type {
  Errors,
  Method,
  Page,
  RequestPayload,
  VisitOptions,
} from "@inertiajs/core";
import { router } from "@inertiajs/vue3";

export function useAction() {
  const toast = useToast();

  const action = (
    method: Method,
    url: string,
    data?: RequestPayload,
    customOptions?: VisitOptions
  ) => {
    // Set default options
    const options = {
      preserveScroll: true,
      onSuccess: (page: Page) => {
        if (page.props.message) {
          toast.success(page.props.message);
        }
      },
      onError: (errors: Errors) => {
        if (errors && Object.values(errors)) {
          toast.error(Object.values(errors)[0]);
        }
      },
      // merge custom options
      ...customOptions,
    };

    router.visit(url, {
      method,
      data,
      ...options,
      preserveState: true,
      preserveScroll: true,
    });
  };

  return { action };
}
