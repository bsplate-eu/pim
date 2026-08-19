<template>
  <Card title="Skrzynki Argo Mail">
    <div class="grid grid-cols-6 gap-6">
      <div
        v-if="roleSeesEverything"
        class="col-span-6 rounded-md bg-amber-50 px-4 py-3 text-sm text-amber-800 ring-1 ring-amber-200"
      >
        Wybrana rola ma uprawnienie „Argo Mail · dostęp do wszystkich skrzynek",
        więc ten użytkownik i tak zobaczy <strong>wszystkie</strong> skrzynki.
        Żeby ograniczyć go do wybranych, odbierz to uprawnienie jego roli w
        <Link
          :href="route('crafter.permissions.index')"
          class="font-medium underline"
        >
          macierzy uprawnień</Link
        >.
      </div>

      <Multiselect
        v-model="form.mail_account_ids"
        name="mail_account_ids"
        label="Przypisane skrzynki"
        mode="multiple"
        :options="mailAccounts"
        optionsValueProp="id"
        optionsLabel="name"
        :searchable="true"
        class="col-span-6"
      />

      <p class="col-span-6 -mt-3 text-sm text-gray-500">
        <template v-if="mailAccounts.length === 0">
          Nie ma jeszcze wpiętych skrzynek — dodaj je w Argo Mail → Skrzynki /
          konta.
        </template>
        <template v-else-if="!roleSeesEverything">
          Pusta lista = użytkownik nie zobaczy w Argo Mail żadnej skrzynki.
        </template>
      </p>
    </div>
  </Card>
</template>

<script setup lang="ts">
import { computed } from "vue";
import { Link } from "@inertiajs/vue3";
import { Card, Multiselect } from "crafter/Components";
import { InertiaForm } from "@inertiajs/vue3";

interface MailAccountOption {
  id: number;
  name: string;
}

interface Props {
  form: InertiaForm<any>;
  mailAccounts: MailAccountOption[];
  rolesWithAllMailAccess: number[];
}

const props = withDefaults(defineProps<Props>(), {
  mailAccounts: () => [],
  rolesWithAllMailAccess: () => [],
});

// Rola wybrana w formularzu (jeszcze niezapisana) — podpowiedź reaguje od razu
// po zmianie roli w selectcie obok.
const roleSeesEverything = computed(() =>
  props.rolesWithAllMailAccess.includes(Number(props.form.role_id))
);
</script>
