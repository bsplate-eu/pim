<template>
  <div
    class="w-full"
    :class="{
      'sticky top-0 z-30': sticky,
    }"
  >
    <Head v-if="title" :title="title" />

    <header
      class="w-full bg-white shadow-md px-6 sm:px-6"
      :class="{
        'py-4': subtitle,
        'py-5': !subtitle,
      }"
    >
      <div class="flex flex-wrap items-center justify-between sm:flex-nowrap">
        <!-- Spacer -->
        <div class="h-10" />
        <div class="mr-auto">
          <h3 v-if="$slots.title || title" class="text-gray-900 text-2xl font-medium leading-6">
            <slot name="title">{{ title }}</slot>
          </h3>
          <p v-if="subtitle" class="text-gray-500 mt-1 text-sm">
            {{ subtitle }}
          </p>
        </div>
        <div class="flex-shrink-0">
          <slot />
        </div>
          
      </div>
    </header>
  </div>
</template>

<script setup lang="ts">
import { Head } from "@inertiajs/vue3";

interface Props {
  title?: string;
  subtitle?: string;
  sticky?: boolean;
}

withDefaults(defineProps<Props>(), {
  sticky: false,
});
</script>
