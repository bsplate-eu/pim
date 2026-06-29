<template>
  <Menu as="div" class="relative flex text-left">
    <Float
      enter="transition duration-200 ease-out"
      enter-from="scale-95 opacity-0"
      enter-to="scale-100 opacity-100"
      leave="transition duration-150 ease-in"
      leave-from="scale-100 opacity-100"
      leave-to="scale-95 opacity-0"
      tailwindcss-origin-class
      :offset="8"
      portal
      v-bind="floatProps"
    >
      <MenuButton as="div" class="flex">
        <slot name="button" />
      </MenuButton>
      <MenuItems
        class="z-40 rounded-md border border-gray-300 bg-white shadow-lg focus:outline-none sm:min-w-[16rem]"
        :class="contentClass"
      >
        <div :class="{ 'p-4': !noContentPadding }">
          <slot name="content" />
        </div>
      </MenuItems>
    </Float>
  </Menu>
</template>

<script setup lang="ts">
import { Menu, MenuButton, MenuItems } from "@headlessui/vue";
import { Float } from "@headlessui-float/vue";
import type { FloatPropsType } from "@headlessui-float/vue";
import { computed } from "vue";

interface Props {
  placement?: FloatPropsType["placement"];
  noContentPadding?: boolean;
  contentClass?: string;
}

const props = withDefaults(defineProps<Props>(), {
  placement: undefined,
  noContentPadding: false,
  contentClass: "",
});

const floatProps = computed(() => {
  return props.placement
    ? { placement: props.placement }
    : {
        autoPlacement: {
          allowedPlacements: [
            "top-start",
            "top-end",
            "bottom-start",
            "bottom-end",
          ],
        },
      };
});
</script>
