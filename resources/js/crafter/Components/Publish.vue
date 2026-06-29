<template>
  <div class="flex gap-1">
    <div v-if="!isPublished">
      <Modal type="info">
        <template #trigger="{ setIsOpen }">
          <Button
            @click="() => setIsOpen(true)"
            color="gray"
            variant="outline"
            :leftIcon="CheckCircleIcon"
            :size="size"
          >
            {{ $t("crafter", "Publish") }}
          </Button>
        </template>

        <template #title>
          {{ $t("crafter", "Publish") }}
        </template>

        <template #content>
          <DatePicker
            name="published_at"
            v-model="publishLaterForm.published_at"
            :mode="mode"
            :label="$t('crafter', 'Set publish date and time')"
          />
        </template>

        <template #buttons="{ setIsOpen }">
          <Button
            @click.prevent="
              () => {
                action(
                  'patch',
                  updateUrl,
                  {
                    [columnName]: publishLaterForm.published_at,
                  },
                  {
                    onFinish: () => {
                      setIsOpen(false);
                    },
                  }
                );
              }
            "
            color="primary"
          >
            {{
              isScheduledPublish
                ? $t("crafter", "Schedule publishing")
                : $t("crafter", "Publish")
            }}
          </Button>

          <Button
            @click.prevent="() => setIsOpen()"
            color="gray"
            variant="outline"
          >
            {{ $t("crafter", "Cancel") }}
          </Button>
        </template>
      </Modal>
    </div>

    <template v-if="isPublished">
      <ButtonGroup>
        <Modal type="warning">
          <template #trigger="{ setIsOpen }">
            <Tooltip :position="tooltipPosition">
              <template #button>
                <Button
                  @click="() => setIsOpen(true)"
                  color="gray"
                  variant="outline"
                  :size="size"
                  class="rounded-r-none"
                >
                  <component
                    :is="isScheduledPublish ? ClockIcon : CheckCircleIconSolid"
                    class="-ml-1 mr-2 h-4 w-4 flex-shrink-0 stroke-2"
                    :class="isScheduledPublish ? '' : 'text-green-500'"
                    aria-hidden="true"
                  />

                  {{
                    mode === "dateTime"
                      ? dayjs(publishedAt).format("DD.MM.YYYY HH:mm")
                      : dayjs(publishedAt).format("DD.MM.YYYY")
                  }}
                </Button>
              </template>
              <template #content>
                {{
                  isScheduledPublish
                    ? $t("crafter", "Reschedule")
                    : $t("crafter", "Change published at")
                }}
              </template>
            </Tooltip>
          </template>

          <template #title>
            {{ $t("crafter", "Edit publish date") }}
          </template>

          <template #content>
            <DatePicker
              name="published_at"
              v-model="publishLaterForm.published_at"
              :mode="mode"
              :label="$t('crafter', 'Set publish date and time')"
            />
          </template>

          <template #buttons="{ setIsOpen }">
            <Button
              @click.prevent="
                () => {
                  action(
                    'patch',
                    updateUrl,
                    {
                      [columnName]: publishLaterForm.published_at,
                    },
                    {
                      onFinish: () => {
                        setIsOpen(false);
                      },
                    }
                  );
                }
              "
              color="primary"
            >
              {{
                isScheduledPublish
                  ? $t("crafter", "Schedule publishing")
                  : $t("crafter", "Publish")
              }}
            </Button>

            <Button
              @click.prevent="() => setIsOpen()"
              color="gray"
              variant="outline"
            >
              {{ $t("crafter", "Cancel") }}
            </Button>
          </template>
        </Modal>

        <Modal type="danger">
          <template #trigger="{ setIsOpen }">
            <Tooltip :position="tooltipPosition">
              <template #button>
                <IconButton
                  @click="() => setIsOpen(true)"
                  :icon="XMarkIcon"
                  color="gray"
                  variant="outline"
                  class="!bg-gray-50 hover:!bg-gray-100 -ml-px rounded-l-none stroke-2"
                  :size="size"
                />
              </template>
              <template #content>
                {{
                  isScheduledPublish
                    ? $t("crafter", "Cancel schedulement")
                    : $t("crafter", "Unpublish")
                }}
              </template>
            </Tooltip>
          </template>

          <template #title>
            {{ $t("crafter", "Unpublish") }}
          </template>

          <template #content>
            {{ $t("crafter", "Are you sure you want to unpublish?") }}
          </template>

          <template #buttons="{ setIsOpen }">
            <Button
              @click.prevent="
                () => {
                  action(
                    'patch',
                    updateUrl,
                    {
                      [columnName]: null,
                    },
                    {
                      onFinish: () => {
                        setIsOpen(false);
                      },
                    }
                  );
                }
              "
              color="danger"
            >
              {{ $t("crafter", "Unpublish") }}
            </Button>

            <Button
              @click.prevent="() => setIsOpen()"
              color="gray"
              variant="outline"
            >
              {{ $t("crafter", "Cancel") }}
            </Button>
          </template>
        </Modal>
      </ButtonGroup>
    </template>
  </div>
</template>

<script setup lang="ts">
import {
  Button,
  Modal,
  DatePicker,
  IconButton,
  ButtonGroup,
  Tooltip,
} from "crafter/Components";
import {
  CheckCircleIcon,
  ClockIcon,
  XMarkIcon,
} from "@heroicons/vue/24/outline";
import { CheckCircleIcon as CheckCircleIconSolid } from "@heroicons/vue/24/solid";
import { useAction } from "crafter/hooks/useAction";
import { computed, watch } from "vue";
import dayjs from "dayjs";
import { useForm } from "@inertiajs/vue3";
import type { DatePickerMode, SizesType } from "../types";

const { action } = useAction();

interface Props {
  publishedAt: string | null;
  updateUrl: string;
  columnName: string;
  mode?: DatePickerMode;
  size?: SizesType;
  tooltipPosition?: "top" | "bottom";
}

const props = withDefaults(defineProps<Props>(), {
  mode: "dateTime",
  size: "sm",
  tooltipPosition: "top",
});

const isPublished = computed(() => {
  return props.publishedAt;
});

const isScheduledPublish = computed(() => {
  if (publishLaterForm.published_at) {
    return dayjs(publishLaterForm.published_at).isAfter(dayjs());
  }

  return false;
});

const publishLaterForm = useForm({
  published_at: props.publishedAt ?? dayjs().format("YYYY-MM-DD HH:mm"),
});
</script>
