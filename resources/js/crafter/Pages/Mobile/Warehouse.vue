<template>
  <Head title="Magazyn" />

  <div class="flex flex-col">
    <!-- Szukajka przyklejona pod paskiem: w hali to jedyna rzecz, ktorej sie uzywa -->
    <div class="sticky top-14 z-10 bg-gray-50 px-4 pt-3 pb-2">
      <!-- Te same dwie zakladki co w menu desktopu -->
      <div class="mb-2 grid grid-cols-2 gap-1 rounded-xl bg-gray-200/70 p-1">
        <button
          type="button"
          class="rounded-lg py-2 text-sm font-semibold transition-colors"
          :class="tab === 'm3r' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500'"
          @click="selectTab('m3r')"
        >
          Magazyn M3R
        </button>
        <button
          type="button"
          class="rounded-lg py-2 text-sm font-semibold transition-colors"
          :class="tab === 'sheet' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500'"
          @click="selectTab('sheet')"
        >
          Tabela
        </button>
      </div>

      <div class="relative">
        <MagnifyingGlassIcon class="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-gray-400" />
        <input
          ref="searchInput"
          v-model="q"
          type="search"
          inputmode="search"
          autocomplete="off"
          :placeholder="tab === 'sheet' ? 'Kod, miejsce albo auto…' : 'Kod albo auto…'"
          class="w-full rounded-xl border-gray-200 bg-white py-3 pl-10 pr-10 text-base shadow-sm focus:border-primary-500 focus:ring-primary-500"
        />
        <button
          v-if="q"
          type="button"
          class="absolute right-2 top-1/2 -translate-y-1/2 rounded-full p-1.5 text-gray-400 active:bg-gray-100"
          @click="clearSearch"
        >
          <XMarkIcon class="h-5 w-5" />
        </button>
      </div>

      <div class="mt-1.5 flex items-center justify-between px-1 text-xs text-gray-400">
        <span>{{ found.length }} z {{ source.length }} kodów</span>
        <span v-if="importedAt && tab === 'sheet'">arkusz z {{ importedAt }}</span>
      </div>
    </div>

    <!-- ZAKLADKA: Magazyn M3R — katalog kodow PIM z obiema ilosciami obok siebie -->
    <div v-if="tab === 'm3r'" class="px-4 pb-6 space-y-2">
      <p v-if="found.length === 0" class="py-16 text-center text-sm text-gray-400">
        Brak kodu „{{ q }}" na liście M3R.
      </p>

      <div
        v-for="row in visible"
        :key="row.code"
        class="flex items-center gap-3 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm"
      >
        <div class="min-w-0 flex-1">
          <div class="truncate font-semibold text-gray-900">{{ row.code }}</div>
          <div class="truncate text-sm text-gray-500">
            {{ row.name || '—' }}
            <span v-if="row.variants > 1" class="text-gray-400">+{{ row.variants - 1 }}</span>
          </div>
          <div v-if="row.material" class="text-xs text-gray-400">{{ row.material }}</div>
        </div>

        <div class="shrink-0 space-y-1 text-right">
          <div class="flex items-center justify-end gap-2">
            <span class="text-[11px] uppercase tracking-wide text-gray-400">M3R</span>
            <span class="rounded-full px-2.5 py-1 text-sm font-bold tabular-nums" :class="qtyClass(row.stock)">
              {{ num(row.stock) }}
            </span>
          </div>
          <div class="flex items-center justify-end gap-2">
            <span class="text-[11px] uppercase tracking-wide text-gray-400">Tabela</span>
            <span class="rounded-full px-2.5 py-1 text-sm font-bold tabular-nums" :class="qtyClass(row.sheet)">
              {{ num(row.sheet) }}
            </span>
          </div>
        </div>
      </div>

      <button
        v-if="visible.length < found.length"
        type="button"
        class="w-full rounded-xl border border-gray-200 bg-white py-3 text-sm font-medium text-gray-600 active:bg-gray-50"
        @click="limit += PAGE"
      >
        Pokaż więcej ({{ found.length - visible.length }})
      </button>
    </div>

    <!-- ZAKLADKA: Tabela — wiersze arkusza inwentury, z miejscami i rezerwacja -->
    <div v-else class="px-4 pb-6 space-y-2">
      <p v-if="found.length === 0" class="py-16 text-center text-sm text-gray-400">
        Brak kodu „{{ q }}" w arkuszu.
      </p>

      <div
        v-for="row in visible"
        :key="row.id"
        class="rounded-2xl border border-gray-100 bg-white shadow-sm"
      >
        <button
          type="button"
          class="flex w-full items-center gap-3 p-4 text-left active:bg-gray-50"
          @click="toggle(row.id)"
        >
          <div class="min-w-0 flex-1">
            <div class="truncate font-semibold text-gray-900">{{ row.code }}</div>
            <div class="truncate text-sm text-gray-500">
              {{ row.places.length ? placesSummary(row) : 'brak miejsca w arkuszu' }}
            </div>
            <!-- Rezerwacja pod sztukami: ilosc mowi ile lezy, ta linijka ile
                 z tego jest juz komus obiecane. -->
            <div
              v-if="reservationsOf(row).length"
              class="truncate text-sm font-medium text-blue-700"
            >
              Rezerwacja: {{ reservationsOf(row).map((r) => r.label).join(', ') }}
            </div>
          </div>

          <span
            class="shrink-0 rounded-full px-2.5 py-1 text-sm font-bold tabular-nums"
            :class="qtyClass(row.total)"
          >
            {{ row.total ?? '—' }}
          </span>

          <ChevronDownIcon
            class="h-5 w-5 shrink-0 text-gray-300 transition-transform"
            :class="open === row.id ? 'rotate-180' : ''"
          />
        </button>

        <!-- Szczegoly: rozwijane, zeby lista zostala skanowalna kciukiem -->
        <div v-if="open === row.id" class="border-t border-gray-100 px-4 py-3 space-y-3">
          <div v-if="row.places.length" class="space-y-1.5">
            <div
              v-for="(p, i) in row.places"
              :key="i"
              class="flex items-center justify-between rounded-lg bg-gray-50 px-3 py-2"
            >
              <span class="text-sm font-medium text-gray-700">{{ p.place || '—' }}</span>
              <span class="text-sm font-bold tabular-nums text-gray-900">{{ p.qty ?? '—' }}</span>
            </div>
          </div>

          <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
            <div v-if="row.wymiar">
              <dt class="text-xs text-gray-400">Wymiar</dt>
              <dd class="text-gray-800">{{ row.wymiar }}</dd>
            </div>
            <div v-if="row.waga">
              <dt class="text-xs text-gray-400">Waga</dt>
              <dd class="text-gray-800">{{ row.waga }}</dd>
            </div>
            <div v-if="row.team">
              <dt class="text-xs text-gray-400">Ekipa</dt>
              <dd class="text-gray-800">{{ row.team }}</dd>
            </div>
          </dl>

          <!-- Do czego pasuje kod. Na liscie tego nie ma, zeby karta zostala
               skanowalna kciukiem — ale po rozwinieciu trzeba widziec, co sie
               znalazlo. -->
          <div v-if="(row.names ?? []).length" class="rounded-lg bg-gray-50 px-3 py-2">
            <div class="text-xs text-gray-400">Pasuje do</div>
            <div class="mt-1 space-y-0.5 text-sm text-gray-700">
              <div v-for="(name, i) in row.names.slice(0, 6)" :key="i" class="truncate">
                {{ name }}
              </div>
              <div v-if="row.names.length > 6" class="text-gray-400">
                i {{ row.names.length - 6 }} więcej
              </div>
            </div>
          </div>

          <div v-if="row.uwagi" class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">
            {{ row.uwagi }}
          </div>

          <!-- Rezerwacje: lista + odlozenie sztuk. Rezerwacja NIE zmienia stanu,
               tylko mowi, ile z lezacego towaru jest juz obiecane. -->
          <div class="rounded-lg border border-blue-100 bg-blue-50 px-3 py-2">
            <div class="text-xs font-semibold uppercase tracking-wide text-blue-700">
              Rezerwacje
            </div>

            <div v-if="reservationsOf(row).length" class="mt-2 space-y-1.5">
              <div
                v-for="item in reservationsOf(row)"
                :key="item.id"
                class="flex items-center justify-between gap-2"
              >
                <span class="truncate text-sm text-blue-900">
                  {{ item.user_name || 'nieznany' }}
                  <strong class="ml-1">{{ item.quantity }} szt.</strong>
                </span>
                <button
                  type="button"
                  class="shrink-0 rounded-lg px-2 py-1 text-sm text-blue-700 active:bg-blue-100"
                  :disabled="busy"
                  @click.stop="release(row, item.id)"
                >
                  Zwolnij
                </button>
              </div>
            </div>
            <div v-else class="mt-1 text-sm text-blue-900/60">Nic nie jest odłożone</div>

            <!-- Ile zostaje po odjeciu rezerwacji — to jest liczba, ktora
                 interesuje czlowieka stojacego przy regale. -->
            <div v-if="reservedOf(row)" class="mt-2 text-sm text-blue-900">
              Wolne: <strong>{{ (row.total ?? 0) - reservedOf(row) }} szt.</strong>
              <span class="text-blue-900/60">z {{ row.total ?? 0 }}</span>
            </div>

            <div class="mt-2 flex items-center gap-2">
              <!-- Rezerwować można dla kogoś innego: przy terminalu stoi jedna
                   osoba, a odkłada dla całej zmiany. -->
              <select
                v-model="forUser[row.id]"
                class="min-w-0 flex-1 rounded-lg border border-blue-200 bg-white px-2 py-1.5 text-sm"
                @click.stop
              >
                <option v-for="person in people" :key="person.value" :value="person.value">
                  {{ person.label }}
                </option>
              </select>
              <input
                v-model="qty[row.id]"
                type="number"
                min="1"
                inputmode="numeric"
                class="w-16 rounded-lg border border-blue-200 bg-white px-2 py-1.5 text-sm"
                placeholder="1"
                @click.stop
              />
              <button
                type="button"
                class="rounded-lg bg-blue-600 px-3 py-1.5 text-sm font-medium text-white active:bg-blue-700"
                :disabled="busy"
                @click.stop="reserve(row)"
              >
                Zarezerwuj
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Doladowanie: 615 kart naraz zabija scroll na telefonie -->
      <button
        v-if="visible.length < found.length"
        type="button"
        class="w-full rounded-xl border border-gray-200 bg-white py-3 text-sm font-medium text-gray-600 active:bg-gray-50"
        @click="limit += PAGE"
      >
        Pokaż więcej ({{ found.length - visible.length }})
      </button>
    </div>
  </div>
</template>

<script>
import MobileLayout from "crafter/Layouts/MobileLayout.vue";
export default { layout: MobileLayout };
</script>

<script setup>
import { ref, computed, watch } from "vue";
import { Head, router } from "@inertiajs/vue3";
import axios from "axios";
import {
  MagnifyingGlassIcon,
  XMarkIcon,
  ChevronDownIcon,
} from "@heroicons/vue/24/outline";

const props = defineProps({
  rows: { type: Array, default: () => [] },
  m3r: { type: Array, default: () => [] },
  sheet: { type: String, default: "" },
  importedAt: { type: String, default: null },
  people: { type: Array, default: () => [] },
  me: { type: Number, default: null },
});

const PAGE = 40;

/* Te same dwie zakladki co w menu desktopu. Oba komplety przychodza jednym
   strzalem i przelaczaja sie lokalnie — w hali zasieg potrafi zniknac, a
   przeskok miedzy widokami nie ma wtedy prawa wisiec na zadaniu do serwera. */
const tab = ref("m3r"); // 'm3r' | 'sheet'

const q = ref("");
const open = ref(null);
const limit = ref(PAGE);
const searchInput = ref(null);

/* Kod bywa zapisany „25.159 ALU" albo „25.159ALU" — porownujemy bez spacji
   i wielkosci liter, zeby wpisanie jednej wersji znalazlo obie. */
const normalize = (v) => String(v ?? "").replace(/\s+/g, "").toUpperCase();

const source = computed(() => (tab.value === "sheet" ? props.rows : props.m3r));

const haystacks = computed(() =>
  tab.value === "sheet"
    ? props.rows.map((row) => ({
        row,
        key:
          normalize(row.code) +
          " " +
          normalize(row.places.map((p) => p.place).join("")) +
          " " +
          normalize((row.names ?? []).join(" ")),
      }))
    : props.m3r.map((row) => ({
        row,
        key: normalize(row.code) + " " + normalize(row.name) + " " + normalize(row.material),
      }))
);

const found = computed(() => {
  const needle = normalize(q.value);
  if (!needle) return source.value;
  return haystacks.value.filter((h) => h.key.includes(needle)).map((h) => h.row);
});

const visible = computed(() => found.value.slice(0, limit.value));

// Nowe szukanie zaczyna liste od gory — inaczej „Pokaż więcej" zostaje rozwiniete.
watch([q, tab], () => {
  limit.value = PAGE;
  open.value = null;
});

const selectTab = (name) => {
  tab.value = name;
  window.scrollTo(0, 0);
};

/* Brak migawki zrodla to nie zero: „nie wiem" nie moze udawac „nie ma".
   Ta sama zasada co na desktopie, tylko zamiast kolumny jest kreska. */
const num = (v) => (v === null || v === undefined ? "—" : v);

/* Rezerwacje trzymamy lokalnie obok wiersza: propsy Inertii sa zamrozone,
   a po zapisie ma sie odswiezyc jedna karta, nie caly ekran. */
const overrides = ref({});
const qty = ref({});
const forUser = ref({});
const busy = ref(false);

const reservationsOf = (row) => overrides.value[row.id] ?? row.reservations ?? [];

const reservedOf = (row) =>
  reservationsOf(row).reduce((sum, item) => sum + Number(item.quantity || 0), 0);

const reserve = async (row) => {
  const value = Number(String(qty.value[row.id] ?? "1").replace(",", "."));

  if (!Number.isFinite(value) || value < 1) return;

  busy.value = true;

  try {
    const { data } = await axios.post(
      route("crafter.production.warehouse.reservation.store"),
      {
        source_code: row.code,
        quantity: Math.round(value),
        for_user_id: forUser.value[row.id] ?? props.me,
        area: "mobile",
      }
    );
    overrides.value = { ...overrides.value, [row.id]: data?.reservations ?? [] };
    qty.value = { ...qty.value, [row.id]: "" };
    router.reload({ only: ["rows"], preserveScroll: true, preserveState: true });
  } catch (e) {
    /* Telefon w hali bywa bez zasiegu — cisza jest lepsza niz alert,
       ktory trzeba klikac w rekawicach. Stan zostaje niezmieniony. */
  } finally {
    busy.value = false;
  }
};

const release = async (row, id) => {
  busy.value = true;

  try {
    const { data } = await axios.delete(
      route("crafter.production.warehouse.reservation.release"),
      { data: { id, area: "mobile" } }
    );
    overrides.value = { ...overrides.value, [row.id]: data?.reservations ?? [] };
    router.reload({ only: ["rows"], preserveScroll: true, preserveState: true });
  } catch (e) {
    /* jak wyzej */
  } finally {
    busy.value = false;
  }
};

const toggle = (id) => {
  open.value = open.value === id ? null : id;

  // Domyslnie rezerwuje sie na siebie; wybor kogos innego ma byc swiadomy.
  if (open.value === id && forUser.value[id] === undefined) {
    forUser.value = { ...forUser.value, [id]: props.me };
  }
};

const clearSearch = () => {
  q.value = "";
  searchInput.value?.focus();
};

const placesSummary = (row) =>
  row.places
    .map((p) => (p.qty !== null && p.qty !== undefined ? `${p.place || "—"} · ${p.qty}` : p.place || "—"))
    .join("   ");

/* Zero to nie to samo co brak wpisu — pierwsze znaczy „nie ma na stanie",
   drugie „arkusz nie mowi". Kolor rozdziela te dwa przypadki. */
const qtyClass = (total) => {
  if (total === null || total === undefined) return "bg-gray-100 text-gray-400";
  if (total <= 0) return "bg-red-50 text-red-600";
  return "bg-green-50 text-green-700";
};
</script>
