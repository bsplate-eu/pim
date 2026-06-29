<template>
  <img
    v-if="isFileImage(file.file_name)"
    :src="file.base64 || file.preview_url || file.original_url"
    :alt="file.custom_properties?.name || file.file_name"
    class="aspect-square w-full rounded object-cover"
  />
  <div
    v-else
    class="relative flex aspect-square h-full items-center justify-center"
  >
    <DocumentIcon class="w-full max-w-[5rem] stroke-1 text-gray-500" />
    <span
      class="absolute translate-y-1 font-mono text-sm font-semibold uppercase text-gray-500"
    >
      {{ getFileExtension(file.file_name) }}
    </span>
  </div>
</template>

<script setup lang="ts">
import { DocumentIcon } from "@heroicons/vue/24/outline";
import { UploadedFile } from "crafter/types";
import { isFileImage, getFileExtension } from "crafter/helpers";
import { Media } from "crafter/Pages/Media/types";

interface Props {
  file: UploadedFile & Media;
}

const props = defineProps<Props>();
</script>
