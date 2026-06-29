<template>
  <div>
    <div class="mb-4 flex items-center justify-between gap-2">
      <div class="flex items-center gap-2">
        <Button
          variant="outline"
          :leftIcon="ArrowUturnLeftIcon"
          @click="rotate(-90)"
        >
          {{ $t("crafter", "Rotate left") }}
        </Button>
        <Button
          variant="outline"
          :leftIcon="ArrowUturnRightIcon"
          @click="rotate(90)"
        >
          {{ $t("crafter", "Rotate right") }}
        </Button>
        <Button
          variant="outline"
          :leftIcon="ArrowsRightLeftIcon"
          @click="flipX"
        >
          {{ $t("crafter", "Flip X") }}
        </Button>
        <Button variant="outline" :leftIcon="ArrowsUpDownIcon" @click="flipY">
          {{ $t("crafter", "Flip Y") }}
        </Button>
        <Button
          variant="outline"
          :leftIcon="ArrowsPointingOutIcon"
          @click="selectAll"
        >
          {{ $t("crafter", "Select whole image") }}
        </Button>
      </div>

      <Button @click="crop">
        {{ $t("crafter", "Crop image") }}
      </Button>
    </div>

    <VueCropper
      ref="cropper"
      :src="src"
      :movable="false"
      :zoomable="false"
      viewMode="1"
    />
  </div>
</template>

<script setup lang="ts">
import VueCropper from "vue-cropperjs";
import "cropperjs/dist/cropper.css";
import { ref } from "vue";
import axios from "axios";
import {
  ArrowsRightLeftIcon,
  ArrowsUpDownIcon,
  ArrowUturnRightIcon,
  ArrowUturnLeftIcon,
  ArrowsPointingOutIcon,
} from "@heroicons/vue/24/outline";
import { Button } from "./index";

interface Props {
  src: string;
  // modelValue: string;
}

const props = defineProps<Props>();
const emit = defineEmits(["onCrop"]);

const cropper = ref<VueCropper>(null);

const flipX = () => {
  let scale = cropper.value?.getData().scaleX;
  scale = scale ? -scale : -1;
  cropper.value?.scaleX(scale);
};
const flipY = () => {
  let scale = cropper.value?.getData().scaleY;
  scale = scale ? -scale : -1;
  cropper.value?.scaleY(scale);
};
const reset = () => {
  cropper.value?.reset();
};
const rotate = (deg: number) => {
  cropper.value?.rotate(deg);
};

const selectAll = () => {
  cropper.value?.setCropBoxData({
    left: 0,
    top: 0,
    width: cropper.value?.getImageData().width,
    height: cropper.value?.getImageData().height,
  });
};

const crop = () => {
  const canvas = cropper.value?.getCroppedCanvas();

  const base64 = canvas.toDataURL();

  canvas.toBlob((blob: any) => {
    const file = new File([blob], "cropped.jpg", {
      type: "image/jpeg",
    });

    const formData = new FormData();
    formData.append("file", file);

    axios
      .post(`/admin/upload`, formData, {
        headers: {
          "Content-Type": "multipart/form-data",
        },
      })
      .then((response) => {
        emit("onCrop", file, response.data.path);
      })
      .catch((e) => {
        console.error(e?.message);
      });
  });
};
</script>
