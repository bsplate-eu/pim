<template>
  <PageHeader sticky :title="$t('crafter', 'Settings')">
    <Button
      :leftIcon="ArrowDownTrayIcon"
      @click="submit"
      :loading="form.processing"
      v-can="'crafter.settings.edit'"
    >
      {{ $t("crafter", "Save") }}
    </Button>
  </PageHeader>

  <PageContent>
    <template #tabs>
      <TabGroup variant="underline">
        <Tab>
          {{ $t("crafter", "General") }}
        </Tab>
        </TabGroup>
    </template>

    <TabPanel>
      <div class="divide-y divide-slate-200 [&>*]:py-5 w-full">
        <Multiselect
          v-model="form.available_locales"
          mode="tags"
          name="available_locales"
          :required="true"
          :label="$t('crafter', 'Available locales')"
          :options="availableLocales"
          :placeholder="$t('crafter', 'Type abbreviation and hit enter')"
          :createOption="true"
          labelPlacement="left"
          :leftIcon="MagnifyingGlassIcon"
        >
          <template #tag="{ option, handleTagRemove, disabled }">
            <Tag
              variant="outline"
              @dismiss="(event) => handleTagRemove(option, event)"
              :dissmisable="true"
            >
              <LocaleFlag :locale="option.label" />
            </Tag>
          </template>
          <template #option="{ option, search }">
            <LocaleFlag :locale="option.label" />
          </template>
        </Multiselect>
        <Multiselect
          v-model="form.default_locale"
          mode="single"
          name="default_locale"
          :label="$t('crafter', 'Default locale')"
          :options="form.available_locales"
          labelPlacement="left"
          inputClass="w-1/2"
        >
          <template #singlelabel="{ value }">
            <LocaleFlag :locale="value.label" />
          </template>
          <template #option="{ option, search }">
            <LocaleFlag :locale="option.label" />
          </template>
        </Multiselect>
        <Multiselect
          v-model="form.default_route"
          mode="single"
          name="default_route"
          :label="$t('crafter', 'Default route')"
          :options="availableRoutes"
          labelPlacement="left"
        />
      </div>
    </TabPanel>
    <TabPanel></TabPanel>
  </PageContent>
</template>

<script setup lang="ts">
import { ArrowDownTrayIcon } from "@heroicons/vue/24/outline";
import {
  PageHeader,
  PageContent,
  Button,
  Multiselect,
  Tag,
  Tab,
  TabGroup,
  LocaleFlag,
} from "crafter/Components";
import { useForm } from "crafter/hooks/useForm";
import { GeneralSettings } from "crafter/Pages/Settings/types";
import { TabPanel } from "@headlessui/vue";
import { MagnifyingGlassIcon } from "@heroicons/vue/24/solid";

interface Props {
  generalSettings: GeneralSettings;
  availableRoutes: string[];
}

const props = defineProps<Props>();

const availableLocales = props.generalSettings.available_locales;

const { form, submit } = useForm<GeneralSettings>(
  {
    available_locales: props.generalSettings.available_locales,
    default_locale: props.generalSettings.default_locale,
    default_route: props.generalSettings.default_route,
  },
  route("crafter.settings.update")
);
</script>
