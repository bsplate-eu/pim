<template>
  <Card title="Skrzynki Argo Mail">
    <div
      v-if="roleSeesEverything"
      class="mb-4 rounded-md bg-amber-50 px-4 py-3 text-sm text-amber-800 ring-1 ring-amber-200"
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

    <div v-if="mailAccounts.length === 0" class="text-sm text-gray-500">
      Nie ma jeszcze wpiętych skrzynek — dodaj je w Argo Mail → Skrzynki /
      konta.
    </div>

    <template v-else>
      <div class="mb-2 flex items-center justify-between">
        <p class="text-sm text-gray-600">
          Zaznaczone: <strong>{{ selected.length }}</strong> z
          {{ mailAccounts.length }}
        </p>
        <div class="flex gap-3 text-sm">
          <button
            type="button"
            class="font-medium text-primary-600 hover:underline disabled:cursor-default disabled:text-gray-300 disabled:no-underline"
            :disabled="selected.length === mailAccounts.length"
            @click.prevent="selectAll"
          >
            Zaznacz wszystkie
          </button>
          <button
            type="button"
            class="font-medium text-primary-600 hover:underline disabled:cursor-default disabled:text-gray-300 disabled:no-underline"
            :disabled="selected.length === 0"
            @click.prevent="clearAll"
          >
            Wyczyść
          </button>
        </div>
      </div>

      <ul class="divide-y divide-gray-100 rounded-md ring-1 ring-gray-200">
        <li v-for="account in mailAccounts" :key="account.id">
          <label
            class="flex cursor-pointer items-center gap-3 px-4 py-2.5 hover:bg-gray-50"
          >
            <input
              type="checkbox"
              class="h-4 w-4 cursor-pointer rounded border-gray-300 text-primary-600 focus:ring-primary-300"
              :value="account.id"
              :checked="selected.includes(account.id)"
              @change="toggle(account.id)"
            />
            <span class="text-sm text-gray-900">{{ account.name }}</span>
            <span
              v-if="!account.is_active"
              class="rounded bg-gray-100 px-1.5 py-0.5 text-xs font-medium text-gray-500"
            >
              nieaktywna
            </span>
          </label>
        </li>
      </ul>

      <p class="mt-3 text-sm text-gray-500">
        <template v-if="roleSeesEverything">
          Zaznaczenia zapiszą się, ale zaczną działać dopiero po odebraniu roli
          uprawnienia „dostęp do wszystkich skrzynek".
        </template>
        <template v-else-if="selected.length === 0">
          Nic nie zaznaczone — użytkownik nie zobaczy w Argo Mail żadnej
          skrzynki.
        </template>
        <template v-else>
          Użytkownik zobaczy w Argo Mail wyłącznie zaznaczone skrzynki.
        </template>
      </p>
    </template>
  </Card>
</template>

<script setup lang="ts">
import { computed } from "vue";
import { Link } from "@inertiajs/vue3";
import { Card } from "crafter/Components";
import { InertiaForm } from "@inertiajs/vue3";

interface MailAccountOption {
  id: number;
  name: string;
  is_active?: boolean;
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

const selected = computed<number[]>(() => props.form.mail_account_ids ?? []);

const toggle = (id: number) => {
  const current = [...selected.value];
  const at = current.indexOf(id);

  if (at === -1) {
    current.push(id);
  } else {
    current.splice(at, 1);
  }

  props.form.mail_account_ids = current;
};

const selectAll = () => {
  props.form.mail_account_ids = props.mailAccounts.map((a) => a.id);
};

const clearAll = () => {
  props.form.mail_account_ids = [];
};

// Rola wybrana w formularzu (jeszcze niezapisana) — podpowiedź reaguje od razu
// po zmianie roli w selectcie obok.
const roleSeesEverything = computed(() =>
  props.rolesWithAllMailAccess.includes(Number(props.form.role_id))
);
</script>
