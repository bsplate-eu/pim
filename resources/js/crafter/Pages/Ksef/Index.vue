<template>
    <PageHeader :title="`KSeF — ${companyLabel}`">
        <Button v-if="tab === 'faktury'" color="gray" variant="outline" :leftIcon="PlusIcon" @click.prevent="openCost()">
            Dodaj koszt
        </Button>
        <Button v-if="tab === 'faktury'" :leftIcon="ArrowDownTrayIcon" @click.prevent="importOpen = true">
            Import faktur
        </Button>
    </PageHeader>

    <PageContent fluid>
        <!-- Zakładki -->
        <div class="mb-5 border-b border-gray-200">
            <nav class="-mb-px flex gap-6">
                <button
                    type="button"
                    @click="tab = 'faktury'"
                    :class="tab === 'faktury'
                        ? 'border-b-2 border-primary-500 px-1 py-3 text-sm font-medium text-primary-600'
                        : 'border-b-2 border-transparent px-1 py-3 text-sm font-medium text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                >
                    Faktury
                </button>
                <button
                    type="button"
                    @click="tab = 'ustawienia'"
                    :class="tab === 'ustawienia'
                        ? 'border-b-2 border-primary-500 px-1 py-3 text-sm font-medium text-primary-600'
                        : 'border-b-2 border-transparent px-1 py-3 text-sm font-medium text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                >
                    Ustawienia
                </button>
            </nav>
        </div>

        <!-- ════════ TAB: FAKTURY ════════ -->
        <div v-show="tab === 'faktury'">
            <!-- Podsumowanie -->
            <div class="mb-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-gray-600">
                <span><span class="font-semibold text-gray-900">{{ liveSummary.count }}</span> poz.</span>
                <span>·</span>
                <span>razem <span class="font-semibold text-gray-900">{{ formatAmount(liveSummary.sum) }}</span></span>
                <span>·</span>
                <span class="text-red-600">do zapłaty {{ formatAmount(liveSummary.sum_unpaid) }}</span>
                <template v-if="liveSummary.missing_fx > 0">
                    <span>·</span>
                    <span
                        class="rounded bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800"
                        title="Pozycje w obcej walucie bez kursu NBP — nie są wliczone w sumy. Uzupełnij: artisan ksef:fx-fill"
                    >
                        {{ liveSummary.missing_fx }} poz. bez kursu — poza sumą
                    </span>
                </template>
            </div>

            <!-- Filtry -->
            <div class="mb-4 flex flex-wrap items-end gap-3">
                <div>
                    <label class="block text-xs font-medium uppercase tracking-wide text-gray-500 mb-1">Miesiąc</label>
                    <select v-model="local.month" @change="reload" class="block rounded-md border-gray-300 text-sm focus:border-primary-500 focus:ring-primary-500">
                        <option value="all">Wszystkie</option>
                        <option v-for="(m, i) in MONTHS" :key="i" :value="i + 1">{{ m }}</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-medium uppercase tracking-wide text-gray-500 mb-1">Kwartał</label>
                    <select v-model="local.quarter" @change="reload" class="block rounded-md border-gray-300 text-sm focus:border-primary-500 focus:ring-primary-500">
                        <option value="all">Wszystkie</option>
                        <option v-for="q in 4" :key="q" :value="q">Q{{ q }}</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium uppercase tracking-wide text-gray-500 mb-1">Rok</label>
                    <select v-model="local.year" @change="reload" class="block rounded-md border-gray-300 text-sm focus:border-primary-500 focus:ring-primary-500">
                        <option value="all">Wszystkie</option>
                        <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
                    </select>
                </div>

                <!-- Status: przełączane przyciski -->
                <div>
                    <label class="block text-xs font-medium uppercase tracking-wide text-gray-500 mb-1">Status</label>
                    <div class="inline-flex rounded-lg bg-gray-100 p-1">
                        <button
                            v-for="opt in STATUS_FILTERS"
                            :key="opt.key"
                            type="button"
                            @click="setStatus(opt.key)"
                            :class="[
                                'rounded-md px-3 py-1.5 text-sm font-medium transition-colors',
                                local.status === opt.key ? 'bg-white text-primary-700 shadow-sm' : 'text-gray-500 hover:text-gray-700',
                            ]"
                        >
                            {{ opt.label }}
                        </button>
                    </div>
                </div>

                <!-- X — wyczyść filtrowanie -->
                <button
                    v-if="hasActiveFilters"
                    type="button"
                    @click="clearFilters"
                    class="inline-flex items-center gap-1 rounded-md border border-gray-300 px-3 py-2 text-sm text-gray-600 hover:bg-gray-50"
                    title="Wyczyść filtrowanie"
                >
                    <XMarkIcon class="w-4 h-4" /> Wyczyść
                </button>
            </div>

            <!-- Tabela FV -->
            <div class="bg-white rounded-lg shadow overflow-x-auto">
                <table class="min-w-full text-sm border-collapse">
                    <thead>
                        <tr class="bg-gray-50 text-gray-600 text-xs uppercase tracking-wide border-b border-gray-200">
                            <th class="px-3 py-2 text-left">Data</th>
                            <th class="px-3 py-2 text-left">Kategoria</th>
                            <th class="px-3 py-2 text-left">Nr FV</th>
                            <th class="px-3 py-2 text-left">Kontrahent</th>
                            <th class="px-3 py-2 text-left">Pozycja FV</th>
                            <th class="px-3 py-2 text-left">Termin</th>
                            <th class="px-3 py-2 text-center">Dni</th>
                            <th class="px-3 py-2 text-right">Kwota</th>
                            <th class="px-3 py-2 text-left">Nr konta</th>
                            <th class="px-3 py-2 text-center">Opłacone</th>
                            <th class="px-3 py-2 text-center w-16">PDF</th>
                            <th class="px-2 py-2 w-10"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in rows" :key="row.id" class="border-b odd:bg-white even:bg-gray-50 hover:bg-gray-100">
                            <td class="px-3 py-1.5 whitespace-nowrap">{{ formatDate(row.issue_date) }}</td>

                            <td class="px-2 py-1">
                                <input
                                    v-model="row.category"
                                    @change="saveCategory(row)"
                                    :list="'ksef-cats-' + company"
                                    placeholder="—"
                                    class="w-32 bg-transparent px-1 py-0.5 text-sm focus:outline-none focus:bg-white focus:ring-1 focus:ring-blue-400 rounded"
                                />
                            </td>

                            <td class="px-3 py-1.5 whitespace-nowrap font-medium text-gray-800">
                                <span
                                    v-if="row.is_manual"
                                    class="mr-1 rounded bg-gray-200 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-gray-600"
                                    title="Pozycja wpisana ręcznie, nie z KSeF"
                                >ręcznie</span>{{ row.number }}
                            </td>
                            <td class="px-3 py-1.5 max-w-[16rem] truncate" :title="row.contractor || ''">{{ row.contractor }}</td>

                            <td class="px-3 py-1.5">
                                <div class="group relative max-w-[18rem]">
                                    <span class="block truncate text-gray-700">{{ row.items_text || '—' }}</span>
                                    <div
                                        v-if="row.items_text"
                                        class="pointer-events-none absolute left-0 top-full z-20 mt-1 hidden w-80 rounded-md border border-gray-200 bg-white p-3 text-xs text-gray-700 shadow-lg group-hover:block"
                                    >
                                        {{ row.items_text }}
                                    </div>
                                </div>
                            </td>

                            <td class="px-3 py-1.5 whitespace-nowrap">{{ formatDate(row.due_date) }}</td>

                            <td class="px-2 py-1.5 text-center">
                                <span v-if="row.status === 'paid'" class="inline-block text-xs font-semibold px-2 py-0.5 rounded bg-green-100 text-green-800">
                                    Zapłacone
                                </span>
                                <span v-else-if="daysInfo(row)" :class="daysPillClass(row)" class="inline-block text-xs font-semibold px-2 py-0.5 rounded">
                                    {{ daysInfo(row) }}
                                </span>
                                <span v-else class="text-gray-300 text-xs">—</span>
                            </td>

                            <!-- Kwota: oryginał + (dla walut obcych) przeliczenie na PLN kursem NBP -->
                            <td class="px-3 py-1.5 text-right whitespace-nowrap font-medium">
                                <div>{{ formatNumber(row.amount) }} <span class="text-xs text-gray-400">{{ row.currency }}</span></div>
                                <div
                                    v-if="row.currency !== 'PLN'"
                                    class="text-xs font-normal"
                                    :class="row.amount_pln === null ? 'text-amber-600' : 'text-gray-400'"
                                    :title="fxTitle(row)"
                                >
                                    {{ row.amount_pln === null ? 'brak kursu' : '≈ ' + formatNumber(row.amount_pln) + ' PLN' }}
                                </div>
                            </td>

                            <!-- Nr konta sprzedawcy (Płatność → RachunekBankowy w XML) — klik kopiuje ciągiem, pod przelew -->
                            <td class="px-3 py-1.5 whitespace-nowrap">
                                <div v-if="row.bank_account" class="group relative inline-flex items-center gap-1">
                                    <button
                                        type="button"
                                        @click="copyAccount(row.bank_account)"
                                        class="font-mono text-xs text-gray-700 hover:text-primary-600 hover:underline"
                                        title="Kliknij, aby skopiować"
                                    >
                                        {{ formatAccount(row.bank_account) }}
                                    </button>
                                    <span
                                        v-if="row.bank_accounts.length > 1"
                                        class="rounded bg-amber-100 px-1 text-[10px] font-semibold text-amber-700"
                                        title="Sprzedawca podał kilka rachunków"
                                    >
                                        +{{ row.bank_accounts.length - 1 }}
                                    </span>
                                    <div
                                        v-if="hasAccountDetails(row)"
                                        class="pointer-events-none absolute left-0 top-full z-20 mt-1 hidden w-72 rounded-md border border-gray-200 bg-white p-3 text-xs shadow-lg group-hover:block"
                                    >
                                        <div
                                            v-for="(acc, i) in row.bank_accounts"
                                            :key="i"
                                            :class="i > 0 ? 'mt-2 border-t border-gray-100 pt-2' : ''"
                                        >
                                            <div class="font-mono text-gray-800">{{ formatAccount(acc.nr) }}</div>
                                            <div v-if="acc.bank || acc.swift" class="text-gray-500">
                                                {{ [acc.bank, acc.swift].filter(Boolean).join(' · ') }}
                                            </div>
                                            <div v-if="acc.opis" class="text-gray-400">{{ acc.opis }}</div>
                                        </div>
                                    </div>
                                </div>
                                <span v-else class="text-xs text-gray-300">—</span>
                            </td>

                            <!-- Opłacone (zaznaczenie) -->
                            <td class="px-3 py-1.5 text-center">
                                <input
                                    type="checkbox"
                                    :checked="row.status === 'paid'"
                                    @change="togglePaid(row, ($event.target as HTMLInputElement).checked)"
                                    class="h-4 w-4 rounded border-gray-300 text-primary-600 focus:ring-primary-500 cursor-pointer"
                                    title="Oznacz jako opłaconą / nieopłaconą"
                                />
                            </td>

                            <td class="px-2 py-1.5 text-center">
                                <a
                                    v-if="row.has_pdf"
                                    :href="route('crafter.ksef.invoices.pdf', row.id)"
                                    target="_blank"
                                    rel="noopener"
                                    class="inline-flex items-center justify-center rounded p-1 text-red-600 hover:bg-red-50"
                                    title="Otwórz PDF"
                                >
                                    <DocumentTextIcon class="w-5 h-5" />
                                </a>
                                <span v-else class="text-xs text-gray-300">—</span>
                            </td>

                            <!-- Kasowanie: tylko pozycje wpisane ręcznie (FV z KSeF wróciłaby przy imporcie) -->
                            <td class="px-2 py-1.5 text-center">
                                <button
                                    v-if="row.is_manual"
                                    type="button"
                                    @click="removeManual(row)"
                                    class="inline-flex items-center justify-center rounded p-1 text-gray-400 hover:bg-red-50 hover:text-red-600"
                                    title="Usuń pozycję"
                                >
                                    <TrashIcon class="w-4 h-4" />
                                </button>
                            </td>
                        </tr>

                        <tr v-if="rows.length === 0">
                            <td colspan="12" class="px-3 py-10 text-center text-sm text-gray-400">
                                Brak faktur dla wybranych filtrów. Kliknij „Import faktur", aby zaciągnąć FV z KSeF.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <datalist :id="'ksef-cats-' + company">
                <option v-for="c in cats" :key="c.id" :value="c.name" />
            </datalist>
        </div>

        <!-- ════════ TAB: USTAWIENIA ════════ -->
        <div v-show="tab === 'ustawienia'" class="max-w-2xl">
            <Card>
                <CardHeader>
                    <h2 class="text-lg font-semibold">Kategorie</h2>
                    <p class="text-sm text-gray-500">Kategorie używane przy fakturach firmy {{ companyLabel }} (edytowalne na liście FV).</p>
                </CardHeader>
                <CardContent>
                    <div class="space-y-2">
                        <div v-for="c in cats" :key="c.id" class="flex items-center gap-2">
                            <input
                                v-model="c.name"
                                @change="renameCategory(c)"
                                class="flex-1 rounded-md border-gray-300 text-sm focus:border-primary-500 focus:ring-primary-500"
                            />
                            <button
                                type="button"
                                @click="removeCategory(c)"
                                class="rounded p-1.5 text-red-500 hover:bg-red-50 hover:text-red-700"
                                title="Usuń kategorię"
                            >
                                <TrashIcon class="w-4 h-4" />
                            </button>
                        </div>
                        <p v-if="cats.length === 0" class="text-sm text-gray-400 py-2">Brak kategorii. Dodaj pierwszą poniżej.</p>
                    </div>

                    <div class="mt-4 flex items-center gap-2 border-t border-gray-100 pt-4">
                        <input
                            v-model="newCategory"
                            @keyup.enter="addCategory"
                            placeholder="Nazwa nowej kategorii…"
                            class="flex-1 rounded-md border-gray-300 text-sm focus:border-primary-500 focus:ring-primary-500"
                        />
                        <Button :leftIcon="PlusIcon" :disabled="!newCategory.trim()" @click.prevent="addCategory">
                            Dodaj
                        </Button>
                    </div>
                </CardContent>
            </Card>
        </div>
    </PageContent>

    <!-- Modal: Import faktur -->
    <!-- ════════ Dodaj koszt ręcznie: FV kosztowa / ZUS / VAT / PIT-4 / OSS ════════ -->
    <Modal :open="costOpen" externalOpen @toggleOpen="costOpen = false">
        <template #title>Dodaj koszt — {{ companyLabel }}</template>
        <template #content>
            <div class="space-y-4">
                <!-- Typ kosztu -->
                <div class="inline-flex flex-wrap gap-1 rounded-lg bg-gray-100 p-1">
                    <button
                        v-for="(label, key) in kinds"
                        :key="key"
                        type="button"
                        @click="setKind(key)"
                        :class="[
                            'rounded-md px-3 py-1.5 text-sm font-medium transition-colors',
                            costForm.kind === key ? 'bg-white text-primary-700 shadow-sm' : 'text-gray-500 hover:text-gray-700',
                        ]"
                    >
                        {{ label }}
                    </button>
                </div>

                <p class="rounded-md border border-blue-200 bg-blue-50 px-3 py-2 text-xs text-blue-800">
                    {{ kindHint }}
                </p>

                <!-- ── FV kosztowa ── -->
                <template v-if="isInvoiceKind">
                    <div class="grid grid-cols-3 gap-3">
                        <div class="col-span-2">
                            <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500">Kontrahent *</label>
                            <input v-model="costForm.contractor" type="text" placeholder="Nazwa firmy" class="block w-full rounded-md border-gray-300 text-sm" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500">NIP</label>
                            <input v-model="costForm.contractor_nip" type="text" placeholder="9252014791" class="block w-full rounded-md border-gray-300 text-sm" />
                        </div>
                    </div>

                    <div class="grid grid-cols-3 gap-3">
                        <div>
                            <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500">Nr FV *</label>
                            <input v-model="costForm.number" type="text" placeholder="FV 123/08/2026" class="block w-full rounded-md border-gray-300 text-sm" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500">Data wystawienia *</label>
                            <input v-model="costForm.issue_date" type="date" class="block w-full rounded-md border-gray-300 text-sm" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500">Termin płatności</label>
                            <input v-model="costForm.due_date" type="date" class="block w-full rounded-md border-gray-300 text-sm" />
                        </div>
                    </div>

                    <div class="grid grid-cols-3 gap-3">
                        <div>
                            <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500">Kwota brutto *</label>
                            <input v-model="costForm.amount" type="number" step="0.01" min="0" class="block w-full rounded-md border-gray-300 text-sm" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500">Waluta</label>
                            <select v-if="!fixedCurrency" v-model="costForm.currency" class="block w-full rounded-md border-gray-300 text-sm">
                                <option v-for="c in CURRENCIES" :key="c" :value="c">{{ c }}</option>
                            </select>
                            <div v-else class="rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-600">
                                {{ fixedCurrency }}
                                <span v-if="fixedCurrency !== 'PLN'" class="text-xs text-gray-400">— przeliczę na PLN</span>
                            </div>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500">Kategoria</label>
                            <input v-model="costForm.category" type="text" :list="'ksef-cats-' + company" placeholder="—" class="block w-full rounded-md border-gray-300 text-sm" />
                        </div>
                    </div>

                    <div>
                        <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500">Nr konta do przelewu</label>
                        <input v-model="costForm.bank_account" type="text" placeholder="PL00 0000 0000 0000 0000 0000 0000" class="block w-full rounded-md border-gray-300 font-mono text-sm" />
                    </div>
                </template>

                <!-- ── ZUS / VAT / PIT-4 / OSS ── -->
                <template v-else>
                    <div class="grid grid-cols-3 gap-3">
                        <div>
                            <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500">
                                Kwota * <span class="text-gray-400">({{ costForm.kind === 'oss' ? 'EUR' : 'PLN' }})</span>
                            </label>
                            <input v-model="costForm.amount" type="number" step="0.01" min="0" class="block w-full rounded-md border-gray-300 text-sm" />
                        </div>
                        <div v-if="isQuarterly">
                            <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500">Kwartał *</label>
                            <select v-model="costForm.period_quarter" @change="refreshDue" class="block w-full rounded-md border-gray-300 text-sm">
                                <option v-for="q in 4" :key="q" :value="q">Q{{ q }}</option>
                            </select>
                        </div>
                        <div v-else>
                            <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500">Miesiąc *</label>
                            <select v-model="costForm.period_month" @change="refreshDue" class="block w-full rounded-md border-gray-300 text-sm">
                                <option v-for="(m, i) in MONTHS" :key="i" :value="i + 1">{{ m }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500">Rok *</label>
                            <select v-model="costForm.period_year" @change="refreshDue" class="block w-full rounded-md border-gray-300 text-sm">
                                <option v-for="y in YEAR_CHOICES" :key="y" :value="y">{{ y }}</option>
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500">Termin płatności</label>
                            <input v-model="costForm.due_date" type="date" class="block w-full rounded-md border-gray-300 text-sm" />
                            <p v-if="costForm.kind === 'zus'" class="mt-1 text-xs text-gray-400">
                                Podpowiadam 20. — spółki z o.o. i S.A. płacą do 15., popraw jeśli trzeba.
                            </p>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500">Kategoria</label>
                            <input v-model="costForm.category" type="text" :list="'ksef-cats-' + company" :placeholder="kinds[costForm.kind]" class="block w-full rounded-md border-gray-300 text-sm" />
                        </div>
                    </div>
                </template>

                <div>
                    <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500">Opis / za co</label>
                    <textarea v-model="costForm.items_text" rows="2" class="block w-full rounded-md border-gray-300 text-sm" :placeholder="isInvoiceKind ? 'Pozycje z faktury' : 'Zostaw puste — wpiszę „' + kinds[costForm.kind] + ' za ' + periodLabelPreview + '”'"></textarea>
                </div>

                <p class="text-xs text-gray-500">
                    Zapisze się jako: <span class="font-medium text-gray-700">{{ numberPreview || '—' }}</span>
                    <template v-if="costForm.kind === 'oss'"> · kwota w EUR zostanie przeliczona na PLN kursem NBP z dnia roboczego przed końcem kwartału</template>
                    <template v-else-if="fixedCurrency && fixedCurrency !== 'PLN'"> · kwota w {{ fixedCurrency }} zostanie przeliczona na PLN kursem NBP z dnia roboczego przed datą wystawienia</template>
                </p>
            </div>
        </template>
        <template #buttons="{ setIsOpen }">
            <Button :loading="savingCost" @click.prevent="submitCost">Zapisz koszt</Button>
            <Button color="gray" variant="outline" @click.prevent="() => { setIsOpen(false); costOpen = false; }">
                Anuluj
            </Button>
        </template>
    </Modal>

    <Modal :open="importOpen" externalOpen @toggleOpen="importOpen = false">
        <template #title>Import faktur z KSeF — {{ companyLabel }}</template>
        <template #content>
            <div class="space-y-4">
                <p class="rounded-md bg-blue-50 border border-blue-200 px-3 py-2 text-xs text-blue-800">
                    Zaciąga REALNE faktury z KSeF (po dacie wystawienia). Zakres &gt; 3 mies. dzielony jest
                    automatycznie. Status „opłacone" i kategorie prowadzisz u siebie — ponowny import ich nie nadpisuje.
                </p>

                <div class="grid grid-cols-3 gap-3">
                    <div>
                        <label class="block text-xs font-medium uppercase tracking-wide text-gray-500 mb-1">Miesiąc</label>
                        <select v-model="importFilter.month" class="block w-full rounded-md border-gray-300 text-sm">
                            <option value="all">Wszystkie</option>
                            <option v-for="(m, i) in MONTHS" :key="i" :value="i + 1">{{ m }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium uppercase tracking-wide text-gray-500 mb-1">Kwartał</label>
                        <select v-model="importFilter.quarter" class="block w-full rounded-md border-gray-300 text-sm">
                            <option value="all">Wszystkie</option>
                            <option v-for="q in 4" :key="q" :value="q">Q{{ q }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium uppercase tracking-wide text-gray-500 mb-1">Rok</label>
                        <select v-model="importFilter.year" class="block w-full rounded-md border-gray-300 text-sm">
                            <option value="all">Wszystkie</option>
                            <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-medium uppercase tracking-wide text-gray-500 mb-1">Pokaż</label>
                    <div class="inline-flex rounded-lg bg-gray-100 p-1">
                        <button
                            v-for="opt in IMPORT_VIEWS"
                            :key="opt.key"
                            type="button"
                            @click="importView = opt.key"
                            :class="[
                                'rounded-md px-3 py-1.5 text-sm font-medium transition-colors',
                                importView === opt.key ? 'bg-white text-primary-700 shadow-sm' : 'text-gray-500 hover:text-gray-700',
                            ]"
                        >
                            {{ opt.label }}
                        </button>
                    </div>
                </div>

                <div class="rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-600">
                    Zaimportowane dotąd: <span class="font-semibold text-gray-900">{{ importMeta.imported }}</span> faktur.
                </div>
            </div>
        </template>
        <template #buttons="{ setIsOpen }">
            <Button :leftIcon="ArrowDownTrayIcon" :loading="importing" @click.prevent="pullAll">
                Zaciągnij wszystko
            </Button>
            <Button color="gray" variant="outline" @click.prevent="() => { setIsOpen(false); importOpen = false; }">
                Zamknij
            </Button>
        </template>
    </Modal>
</template>

<script setup lang="ts">
import { computed, reactive, ref, watch } from "vue";
import { router } from "@inertiajs/vue3";
import axios from "axios";
import { ArrowDownTrayIcon, DocumentTextIcon, XMarkIcon, TrashIcon, PlusIcon } from "@heroicons/vue/24/outline";
import { useToast } from "@brackets/vue-toastification";
import { PageHeader, PageContent, Button, Modal, Card, CardHeader, CardContent } from "crafter/Components";

interface BankAccount {
    nr: string;
    bank?: string;
    swift?: string;
    opis?: string;
}

interface Invoice {
    id: number;
    kind: string;
    kind_label: string;
    period_label: string | null;
    is_manual: boolean;
    issue_date: string | null;
    number: string;
    contractor: string | null;
    contractor_nip: string | null;
    bank_account: string | null;
    bank_accounts: BankAccount[];
    items_text: string | null;
    category: string | null;
    due_date: string | null;
    amount: number;
    currency: string;
    amount_pln: number | null;
    fx_rate: number | null;
    fx_date: string | null;
    status: string;
    has_pdf: boolean;
}

interface Category { id: number; name: string; }

interface Props {
    company: string;
    companyLabel: string;
    invoices: Invoice[];
    filters: { year: string | number; month: string | number; quarter: string | number; status: string };
    years: number[];
    categories: Category[];
    kinds: Record<string, string>;
    summary: { count: number; sum: number; sum_unpaid: number; missing_fx: number };
    importMeta: { imported: number };
}

const props = defineProps<Props>();
const toast = useToast();

const MONTHS = ["Styczeń", "Luty", "Marzec", "Kwiecień", "Maj", "Czerwiec", "Lipiec", "Sierpień", "Wrzesień", "Październik", "Listopad", "Grudzień"];
const STATUS_FILTERS = [
    { key: "paid", label: "Zapłacone" },
    { key: "unpaid", label: "Niezapłacone" },
    { key: "all", label: "Wszystkie" },
];
const IMPORT_VIEWS = [
    { key: "all", label: "Wszystkie" },
    { key: "imported", label: "Zaimportowane" },
    { key: "not_imported", label: "Niezaimportowane" },
];

const tab = ref<"faktury" | "ustawienia">("faktury");
const rows = ref<Invoice[]>([...props.invoices]);
const cats = ref<Category[]>([...props.categories]);
const newCategory = ref("");

// Re-sync lokalnych kopii, gdy serwer zwróci nowe propsy (np. po zmianie filtra).
watch(() => props.invoices, (v) => { rows.value = v.map((x) => ({ ...x })); });
watch(() => props.categories, (v) => { cats.value = [...v]; });

// Podsumowanie liczone NA ŻYWO z wierszy — reaguje na filtry i na „opłacone".
// Sumujemy WYŁĄCZNIE amount_pln: amount bywa w EUR/CZK i dodawanie go do złotówek zawyżało „razem".
// Pozycje bez kursu (amount_pln === null) świadomie wypadają z sum i są liczone osobno.
const liveSummary = computed(() => {
    const list = rows.value;
    const pln = (r: Invoice) => (r.amount_pln === null ? 0 : Number(r.amount_pln));
    const sum = list.reduce((s, r) => s + pln(r), 0);
    const sumUnpaid = list.filter((r) => r.status !== "paid").reduce((s, r) => s + pln(r), 0);
    const missingFx = list.filter((r) => r.amount_pln === null).length;
    return { count: list.length, sum, sum_unpaid: sumUnpaid, missing_fx: missingFx };
});

const local = reactive({
    year: props.filters.year ?? "all",
    month: props.filters.month ?? "all",
    quarter: props.filters.quarter ?? "all",
    status: props.filters.status ?? "all",
});

const hasActiveFilters = computed(() =>
    local.year !== "all" || local.month !== "all" || local.quarter !== "all" || local.status !== "all"
);

const importOpen = ref(false);
const importing = ref(false);
const importView = ref<string>("all");
const importFilter = reactive({ year: "all", month: "all", quarter: "all" });

// ── Dodaj koszt ręcznie: FV kosztowa / ZUS / VAT / PIT-4 / OSS ──
const CURRENCIES = ["PLN", "EUR", "CZK", "USD", "GBP", "HUF", "RON", "SEK", "DKK", "NOK"];
const QUARTERLY_KINDS = ["oss"];
const INVOICE_KINDS = ["invoice", "rumunia", "paxy"];
// Typy z walutą narzuconą — użytkownik jej nie wybiera (musi się zgadzać z KsefInvoice::FIXED_CURRENCY).
const FIXED_CURRENCY: Record<string, string> = { rumunia: "EUR", paxy: "PLN", oss: "EUR" };
const KIND_HINTS: Record<string, string> = {
    invoice: "Faktura kosztowa, której nie ma w KSeF (np. zagraniczna). Wpisujesz dane z ręki.",
    rumunia: "Faktura z Rumunii — kwota w EUR, przeliczę ją na PLN kursem średnim NBP. Trafia domyślnie do kategorii „Rumunia”.",
    paxy: "Koszt PAXY w PLN. Trafia domyślnie do kategorii „PAXY”, resztę wypełniasz jak przy fakturze.",
    zus: "Składki ZUS za wskazany miesiąc. Numer i odbiorcę uzupełnię sam; koszt wpada w miesiąc, KTÓREGO dotyczy, nie w ten, w którym płacisz.",
    vat: "VAT za wskazany miesiąc. Termin ustawowy: 25. następnego miesiąca.",
    pit4: "Zaliczka na PIT od wynagrodzeń (PIT-4) za wskazany miesiąc. Termin ustawowy: 20. następnego miesiąca.",
    oss: "VAT OSS za kwartał — kwota w EUR. Przeliczę ją na PLN kursem średnim NBP; na liście zobaczysz obie kwoty.",
};

const TODAY = new Date();
const YEAR_CHOICES = Array.from({ length: 6 }, (_, i) => TODAY.getFullYear() - 4 + i);

const costOpen = ref(false);
const savingCost = ref(false);
const costForm = reactive({
    kind: "invoice",
    contractor: "",
    contractor_nip: "",
    number: "",
    issue_date: "",
    due_date: "",
    amount: "" as string | number,
    currency: "PLN",
    category: "",
    items_text: "",
    bank_account: "",
    period_month: TODAY.getMonth() + 1,
    period_quarter: Math.floor(TODAY.getMonth() / 3) + 1,
    period_year: TODAY.getFullYear(),
});

const isQuarterly = computed(() => QUARTERLY_KINDS.includes(costForm.kind));
const isInvoiceKind = computed(() => INVOICE_KINDS.includes(costForm.kind));
const fixedCurrency = computed(() => FIXED_CURRENCY[costForm.kind] ?? null);
const kindHint = computed(() => KIND_HINTS[costForm.kind] ?? "");
const periodLabelPreview = computed(() =>
    isQuarterly.value
        ? `Q${costForm.period_quarter}/${costForm.period_year}`
        : `${String(costForm.period_month).padStart(2, "0")}/${costForm.period_year}`,
);
// Prefiks numeru bierzemy z etykiety typu, nie z klucza — inaczej „pit4" dałoby „PIT4 07/2026".
const numberPreview = computed(() =>
    isInvoiceKind.value ? costForm.number.trim() : `${props.kinds[costForm.kind]} ${periodLabelPreview.value}`,
);

function toYmd(d: Date): string {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
}

function openCost() {
    const today = new Date();
    Object.assign(costForm, {
        kind: "invoice",
        contractor: "",
        contractor_nip: "",
        number: "",
        issue_date: toYmd(today),
        due_date: "",
        amount: "",
        currency: "PLN",
        category: "",
        items_text: "",
        bank_account: "",
        period_month: today.getMonth() + 1,
        period_quarter: Math.floor(today.getMonth() / 3) + 1,
        period_year: today.getFullYear(),
    });
    costOpen.value = true;
}

function setKind(kind: string) {
    costForm.kind = kind;
    costForm.due_date = "";
    costForm.currency = FIXED_CURRENCY[kind] ?? "PLN";
    if (!INVOICE_KINDS.includes(kind)) refreshDue();
}

/** Termin ustawowy daniny — tylko podpowiedź, pole zostaje edytowalne. */
function refreshDue() {
    if (isInvoiceKind.value) return;
    const periodMonth = isQuarterly.value ? Number(costForm.period_quarter) * 3 : Number(costForm.period_month);
    let m = periodMonth + 1;
    let y = Number(costForm.period_year);
    if (m > 12) { m = 1; y += 1; }
    // OSS: koniec miesiąca po kwartale; VAT: 25.; ZUS i PIT-4: 20.
    const day = costForm.kind === "oss" ? new Date(y, m, 0).getDate() : costForm.kind === "vat" ? 25 : 20;
    costForm.due_date = `${y}-${String(m).padStart(2, "0")}-${String(day).padStart(2, "0")}`;
}

function submitCost() {
    const kind = costForm.kind;
    const payload: Record<string, unknown> = {
        kind,
        amount: costForm.amount,
        category: costForm.category || null,
        items_text: costForm.items_text || null,
        due_date: costForm.due_date || null,
    };

    if (INVOICE_KINDS.includes(kind)) {
        Object.assign(payload, {
            contractor: costForm.contractor,
            contractor_nip: costForm.contractor_nip || null,
            number: costForm.number,
            issue_date: costForm.issue_date,
            currency: costForm.currency,
            bank_account: costForm.bank_account || null,
        });
    } else {
        Object.assign(payload, {
            period_year: costForm.period_year,
            ...(isQuarterly.value ? { period_quarter: costForm.period_quarter } : { period_month: costForm.period_month }),
        });
    }

    savingCost.value = true;
    router.post(route("crafter.ksef.manual.store", props.company), payload, {
        preserveScroll: true,
        onSuccess: () => { costOpen.value = false; },
        onError: (errors) => {
            const first = Object.values(errors ?? {})[0];
            if (first) toast.error(String(first));
        },
        onFinish: () => { savingCost.value = false; },
    });
}

/** Kasować wolno tylko pozycje wpisane ręcznie — FV z KSeF wróciłaby przy następnym imporcie. */
async function removeManual(row: Invoice) {
    if (!window.confirm(`Usunąć „${row.number}" z listy kosztów?`)) return;
    try {
        await axios.delete(route("crafter.ksef.manual.destroy", row.id));
        rows.value = rows.value.filter((r) => r.id !== row.id);
    } catch {
        toast.error("Nie udało się usunąć pozycji.");
    }
}

const pageRoute = props.company === "bsp" ? "crafter.ksef.bsp" : "crafter.ksef.pareto";

function reload() {
    router.get(route(pageRoute), { ...local }, { preserveState: true, preserveScroll: true, replace: true });
}

function setStatus(key: string) {
    local.status = key;
    reload();
}

function clearFilters() {
    local.year = "all";
    local.month = "all";
    local.quarter = "all";
    local.status = "all";
    reload();
}

async function saveCategory(row: Invoice) {
    try {
        await axios.patch(route("crafter.ksef.invoices.category", row.id), { category: row.category || null });
    } catch {
        toast.error("Nie udało się zapisać kategorii.");
    }
}

async function togglePaid(row: Invoice, checked: boolean) {
    const status = checked ? "paid" : "unpaid";
    try {
        await axios.patch(route("crafter.ksef.invoices.status", row.id), { status });
        row.status = status;
        // Jeśli aktywny filtr statusu i wiersz już nie pasuje — usuń z widoku (spójnie z reload).
        if (local.status !== "all" && local.status !== status) {
            rows.value = rows.value.filter((r) => r.id !== row.id);
        }
    } catch {
        toast.error("Nie udało się zmienić statusu.");
    }
}

function pullAll() {
    importing.value = true;
    router.post(route("crafter.ksef.import", props.company), { ...importFilter }, {
        preserveScroll: true,
        onSuccess: () => { importOpen.value = false; },
        onFinish: () => { importing.value = false; },
    });
}

// ── Ustawienia → kategorie ──
async function addCategory() {
    const name = newCategory.value.trim();
    if (!name) return;
    try {
        const { data } = await axios.post(route("crafter.ksef.categories.store", props.company), { name });
        if (!cats.value.some((c) => c.id === data.category.id)) {
            cats.value.push(data.category);
        }
        newCategory.value = "";
    } catch {
        toast.error("Nie udało się dodać kategorii.");
    }
}

async function renameCategory(cat: Category) {
    const name = cat.name.trim();
    if (!name) return;
    try {
        await axios.patch(route("crafter.ksef.categories.update", cat.id), { name });
    } catch {
        toast.error("Nie udało się zmienić nazwy kategorii.");
    }
}

async function removeCategory(cat: Category) {
    if (!confirm(`Usunąć kategorię „${cat.name}"?`)) return;
    try {
        await axios.delete(route("crafter.ksef.categories.destroy", cat.id));
        cats.value = cats.value.filter((c) => c.id !== cat.id);
    } catch {
        toast.error("Nie udało się usunąć kategorii.");
    }
}

// ── nr konta ──
/** Czy warto pokazywać dymek: kilka rachunków albo nazwa banku / SWIFT / opis. */
function hasAccountDetails(row: Invoice): boolean {
    return row.bank_accounts.length > 1 || row.bank_accounts.some((a) => a.bank || a.swift || a.opis);
}

/**
 * NRB (26 cyfr) czytamy jak na przelewie: 2 + bloki po 4; IBAN z prefiksem kraju — bloki po 4.
 * Rachunki zagraniczne w innym formacie (np. czeskie „2008111003/5500") zostawiamy jak są —
 * dzielenie ich na czwórki tylko utrudnia odczyt.
 */
function formatAccount(nr: string | null): string {
    const v = (nr || "").replace(/\s+/g, "");
    if (/^\d{26}$/.test(v)) return (v.slice(0, 2) + " " + (v.slice(2).match(/.{1,4}/g) || []).join(" ")).trim();
    if (/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/.test(v)) return (v.match(/.{1,4}/g) || []).join(" ");
    return v;
}

/**
 * Kopiujemy numer CIĄGIEM (bez spacji) — tak wchodzi do formularza przelewu.
 * PIM chodzi po http, gdzie navigator.clipboard bywa niedostępny → najpierw execCommand.
 */
function copyAccount(nr: string | null) {
    const text = (nr || "").replace(/\s+/g, "");
    if (!text) return;

    const ta = document.createElement("textarea");
    ta.value = text;
    ta.setAttribute("readonly", "");
    ta.style.cssText = "position:fixed;top:-9999px;left:-9999px;opacity:0;";
    document.body.appendChild(ta);
    ta.select();
    let ok = false;
    try {
        ok = document.execCommand("copy");
    } catch {
        ok = false;
    }
    document.body.removeChild(ta);

    if (ok) {
        toast.success(`Skopiowano: ${text}`);
        return;
    }
    if (navigator.clipboard?.writeText) {
        navigator.clipboard.writeText(text).then(
            () => toast.success(`Skopiowano: ${text}`),
            () => toast.error("Nie udało się skopiować numeru konta."),
        );
        return;
    }
    toast.error("Nie udało się skopiować numeru konta.");
}

// ── formatowanie / dni ──
/** Sumy są w PLN, więc tu „zł" jest na miejscu. Do kwot w walucie obcej: formatNumber(). */
function formatAmount(n: number): string {
    return formatNumber(n) + " zł";
}

function formatNumber(n: number | null): string {
    return new Intl.NumberFormat("pl-PL", { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(n || 0));
}

/** Skąd wzięła się kwota w PLN — kurs i data tabeli NBP zapisane przy rekordzie. */
function fxTitle(row: Invoice): string {
    if (row.amount_pln === null) return "Brak kursu NBP — pozycja nie wchodzi do sum.";
    return `Kurs NBP ${row.fx_rate ?? "?"} z ${row.fx_date ?? "?"}`;
}

function formatDate(d: string | null): string {
    if (!d) return "—";
    const [y, m, day] = d.substring(0, 10).split("-");
    return `${day}.${m}.${y}`;
}

function daysRemaining(row: Invoice): number | null {
    if (!row.due_date) return null;
    const due = new Date(row.due_date.substring(0, 10) + "T00:00:00");
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    return Math.round((due.getTime() - today.getTime()) / 86400000);
}

function daysInfo(row: Invoice): string | null {
    const n = daysRemaining(row);
    if (n === null) return null;
    return n > 0 ? `+${n}` : String(n);
}

function daysPillClass(row: Invoice): string {
    if (row.status === "paid") return "bg-gray-100 text-gray-500";
    const n = daysRemaining(row);
    if (n === null) return "bg-gray-100 text-gray-500";
    return n < 0 ? "bg-red-100 text-red-800" : "bg-green-100 text-green-800";
}
</script>
