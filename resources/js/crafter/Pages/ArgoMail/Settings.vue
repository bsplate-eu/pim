<template>
    <PageHeader title="Argo Mail — Ustawienia">
        <div class="flex gap-2">
            <Link :href="route('crafter.argo-mail.index')">
                <Button variant="outline" color="gray">← Skrzynka</Button>
            </Link>
        </div>
    </PageHeader>

    <PageContent fluid>
        <!-- Taby -->
        <div class="mb-4 border-b border-gray-200">
            <nav class="-mb-px flex gap-4">
                <button type="button" @click="tab = 'catalogs'" :class="tabClass('catalogs')">Katalogi</button>
                <button type="button" @click="tab = 'filters'" :class="tabClass('filters')">Filtry</button>
                <button type="button" @click="tab = 'categories'" :class="tabClass('categories')">Kategorie</button>
                <button type="button" @click="tab = 'users'" :class="tabClass('users')">Osoby</button>
                <button type="button" @click="tab = 'spam'" :class="tabClass('spam')">Spam</button>
                <button type="button" @click="tab = 'nogroup'" :class="tabClass('nogroup')">Bez grupowania</button>
            </nav>
        </div>

        <!-- KATALOGI -->
        <div v-show="tab === 'catalogs'" class="w-full">
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-5">
                <h2 class="text-lg font-semibold text-gray-900 mb-1">Katalogi (drzewo do sortowania maili)</h2>
                <p class="text-sm text-gray-500 mb-4">
                    Buduj własną strukturę folderów. Mail możesz przypisać do jednego katalogu (w podglądzie lub — wkrótce — prawym klikiem).
                </p>

                <!-- Dodawanie -->
                <div class="flex flex-wrap items-end gap-2 mb-5 p-3 rounded-md bg-gray-50 border border-gray-200">
                    <div>
                        <label class="block text-xs font-medium text-gray-500 mb-1">Nazwa katalogu</label>
                        <input v-model="newCatalog.name" type="text" maxlength="80" placeholder="np. Sklep / Reklamacje" class="block w-56 rounded-md border-gray-300 text-sm" @keyup.enter="addCatalog" />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-500 mb-1">Katalog nadrzędny</label>
                        <select v-model="newCatalog.parent_id" class="block w-56 rounded-md border-gray-300 text-sm">
                            <option :value="null">— katalog główny —</option>
                            <option v-for="c in catalogs" :key="c.id" :value="c.id">{{ indent(c.depth) + c.name }}</option>
                        </select>
                    </div>
                    <Button type="button" :leftIcon="PlusIcon" @click="addCatalog">Dodaj</Button>
                </div>

                <!-- Lista drzewa -->
                <div v-if="catalogList.length === 0" class="text-sm text-gray-400 py-4">Brak katalogów — dodaj pierwszy powyżej.</div>
                <draggable v-else v-model="catalogList" item-key="id" tag="ul" handle=".cat-drag" class="divide-y divide-gray-100" @end="saveOrder">
                    <template #item="{ element: c }">
                        <li class="flex items-center gap-2 py-2" :style="{ paddingLeft: (c.depth * 18) + 'px' }">
                            <Bars3Icon class="cat-drag h-4 w-4 text-gray-300 hover:text-gray-600 cursor-move shrink-0" title="Przeciągnij, aby zmienić kolejność" />
                            <FolderIcon class="h-4 w-4 text-gray-400 shrink-0" />
                            <span class="text-sm" :class="[catalogDepthClass(c.depth), c.unread > 0 ? 'font-bold' : '']">{{ c.name }}</span>
                            <span class="text-xs text-gray-400">({{ c.total }} maili<span v-if="c.unread > 0">, {{ c.unread }} nieprzecz.</span>)</span>
                            <div class="ml-auto flex items-center gap-2">
                                <select @change="moveCatalog(c, $event)" class="text-xs rounded-md border-gray-300 py-1 max-w-[150px]" title="Przenieś katalog do innego folderu">
                                    <option value="">Przenieś do…</option>
                                    <option value="root">— katalog główny —</option>
                                    <option v-for="t in moveTargets(c)" :key="t.id" :value="t.id">{{ indent(t.depth) + t.name }}</option>
                                </select>
                                <button type="button" @click="addSub(c)" class="text-xs text-primary-600 hover:text-primary-700">+ podkatalog</button>
                                <button type="button" @click="rename(c)" class="text-gray-400 hover:text-gray-700" title="Zmień nazwę"><PencilSquareIcon class="h-4 w-4" /></button>
                                <button type="button" @click="removeCatalog(c)" class="text-red-500 hover:text-red-700" title="Usuń"><TrashIcon class="h-4 w-4" /></button>
                            </div>
                        </li>
                    </template>
                </draggable>
            </div>
        </div>

        <!-- KATEGORIE -->
        <div v-show="tab === 'categories'" class="w-full">
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-5">
                <h2 class="text-lg font-semibold text-gray-900 mb-1">Kategorie (etykiety AI)</h2>
                <p class="text-sm text-gray-500 mb-4">
                    Kategorie, do których AI przypisuje maile (Narzędzia AI → Mail → Administrator → „Kategoryzuj AI").
                </p>

                <div class="flex flex-wrap items-end gap-2 mb-5 p-3 rounded-md bg-gray-50 border border-gray-200">
                    <input v-model="newCategory.color" type="color" class="h-[38px] w-12 rounded-md border border-gray-300 p-1" />
                    <div>
                        <label class="block text-xs font-medium text-gray-500 mb-1">Nazwa kategorii</label>
                        <input v-model="newCategory.name" type="text" maxlength="60" placeholder="np. Wsparcie techniczne" class="block w-56 rounded-md border-gray-300 text-sm" @keyup.enter="addCategory" />
                    </div>
                    <Button type="button" :leftIcon="PlusIcon" @click="addCategory">Dodaj</Button>
                </div>

                <ul class="divide-y divide-gray-100">
                    <li v-for="c in categories" :key="c.id" class="flex items-center gap-2 py-2">
                        <span class="h-3 w-3 rounded-full shrink-0" :style="{ backgroundColor: c.color }"></span>
                        <span class="text-sm text-gray-800">{{ c.name }}</span>
                        <span class="text-xs text-gray-400">({{ c.messages_count }} maili)</span>
                        <button type="button" @click="removeCategory(c)" class="ml-auto text-red-500 hover:text-red-700" title="Usuń"><TrashIcon class="h-4 w-4" /></button>
                    </li>
                </ul>
            </div>
        </div>

        <!-- OSOBY -->
        <div v-show="tab === 'users'" class="w-full">
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-5">
                <h2 class="text-lg font-semibold text-gray-900 mb-1">Osoby obsługujące pocztę</h2>
                <p class="text-sm text-gray-500 mb-4">
                    Wskaż konta systemu PIM, które obsługują maile. Pojawią się jako taby „Osoby" w panelu; możesz przypisywać im maile (prawy-klik) i nadać kolor etykiety.
                </p>

                <div class="flex flex-wrap items-end gap-2 mb-5 p-3 rounded-md bg-gray-50 border border-gray-200">
                    <input v-model="newMailUser.color" type="color" class="h-[38px] w-12 rounded-md border border-gray-300 p-1" />
                    <div>
                        <label class="block text-xs font-medium text-gray-500 mb-1">Użytkownik systemu</label>
                        <select v-model="newMailUser.admin_user_id" class="block w-72 rounded-md border-gray-300 text-sm">
                            <option :value="null">— wybierz osobę —</option>
                            <option v-for="u in availableUsers" :key="u.id" :value="u.id">{{ u.name }} ({{ u.email }})</option>
                        </select>
                    </div>
                    <Button type="button" :leftIcon="PlusIcon" @click="addMailUser" :disabled="!newMailUser.admin_user_id">Dodaj</Button>
                </div>

                <ul v-if="users.length" class="divide-y divide-gray-100">
                    <li v-for="u in users" :key="u.id" class="flex items-center gap-2 py-2">
                        <span class="h-3 w-3 rounded-full shrink-0" :style="{ backgroundColor: u.color || '#9ca3af' }"></span>
                        <span class="text-sm text-gray-800">{{ u.name }}</span>
                        <span class="text-xs text-gray-400">{{ u.email }}</span>
                        <button type="button" @click="removeMailUser(u)" class="ml-auto text-red-500 hover:text-red-700" title="Usuń"><TrashIcon class="h-4 w-4" /></button>
                    </li>
                </ul>
                <div v-else class="text-sm text-gray-400 py-2">Brak osób — dodaj powyżej.</div>
            </div>
        </div>

        <!-- FILTRY (reguły domena/adres → katalog + wykluczenia) -->
        <div v-show="tab === 'filters'" class="w-full">
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-5">
                <h2 class="text-lg font-semibold text-gray-900 mb-1">Filtry — kierowanie maili do katalogów</h2>
                <p class="text-sm text-gray-500 mb-4">
                    <b>Reguła ogólna:</b> wpisz <b>domenę</b> (<code>@domena.pl</code>) → każdy mail z tej domeny trafi do wybranego katalogu.<br />
                    <b>Wykluczenie</b> (ma pierwszeństwo nad regułą ogólną i nad spamem): podaj <b>konkretny adres</b> (<code>info@domena.pl</code>)
                    albo <b>domenę + słowo z tematu</b> (np. <code>@domena.pl</code> + „faktura") → ten mail trafi do osobnego katalogu, mimo że reszta domeny jest gdzie indziej / w spamie.
                </p>

                <div class="flex flex-wrap items-end gap-2 mb-5 p-3 rounded-md bg-gray-50 border border-gray-200">
                    <div>
                        <label class="block text-xs font-medium text-gray-500 mb-1">Adres lub domena</label>
                        <input v-model="newRule.from_email" type="text" maxlength="255" placeholder="@domena.pl lub info@domena.pl" class="block w-64 rounded-md border-gray-300 text-sm" @keyup.enter="addRule" />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-500 mb-1">Słowo w temacie <span class="text-gray-400">(opcjonalnie = wykluczenie)</span></label>
                        <input v-model="newRule.subject_contains" type="text" maxlength="190" placeholder="np. faktura" class="block w-48 rounded-md border-gray-300 text-sm" @keyup.enter="addRule" />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-500 mb-1">Katalog</label>
                        <select v-model="newRule.catalog_id" class="block w-56 rounded-md border-gray-300 text-sm">
                            <option :value="null">— wybierz katalog —</option>
                            <option v-for="c in catalogs" :key="c.id" :value="c.id">{{ indent(c.depth) + c.name }}</option>
                        </select>
                    </div>
                    <Button type="button" :leftIcon="PlusIcon" @click="addRule">Dodaj regułę</Button>
                </div>

                <div v-if="senderRules.length === 0" class="text-sm text-gray-400 py-4">Brak reguł — dodaj pierwszą powyżej.</div>
                <ul v-else class="divide-y divide-gray-100">
                    <li v-for="r in senderRules" :key="r.id" class="flex items-center gap-2 py-2">
                        <span class="text-sm font-medium text-gray-800">{{ r.from_email }}</span>
                        <span v-if="r.subject_contains" class="text-xs rounded-full bg-amber-100 text-amber-700 px-2 py-0.5">temat zawiera: „{{ r.subject_contains }}"</span>
                        <span class="text-gray-400 text-xs">→</span>
                        <span v-if="r.catalog" class="inline-flex items-center gap-1 text-xs rounded-full bg-gray-100 text-gray-700 px-2 py-0.5">
                            <FolderIcon class="h-3 w-3" /> {{ r.catalog.name }}
                        </span>
                        <span v-else class="text-xs text-gray-400">(katalog usunięty)</span>
                        <button type="button" @click="removeRule(r)" class="ml-auto text-red-500 hover:text-red-700" title="Usuń regułę"><TrashIcon class="h-4 w-4" /></button>
                    </li>
                </ul>
            </div>
        </div>

        <!-- SPAM -->
        <div v-show="tab === 'spam'" class="w-full">
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-5">
                <h2 class="text-lg font-semibold text-gray-900 mb-1">Spam — zablokowani nadawcy</h2>
                <p class="text-sm text-gray-500 mb-4">
                    Maile od tych nadawców są ukryte z głównej skrzynki (widać je w folderze „Spam"). Kolejne maile od nich
                    trafiają do spamu automatycznie. Możesz zablokować pojedynczy adres (<code>spam@x.com</code>) albo
                    <b>całą domenę</b> (<code>@selections.aliexpress.com</code> — wtedy KAŻDY mail z tej domeny). Usuń wpis, aby maile wróciły.
                </p>

                <div class="flex flex-wrap items-end gap-2 mb-5 p-3 rounded-md bg-gray-50 border border-gray-200">
                    <div>
                        <label class="block text-xs font-medium text-gray-500 mb-1">Adres e-mail lub domena nadawcy</label>
                        <input v-model="newSpam" type="text" placeholder="spam@example.com  lub  @selections.aliexpress.com" class="block w-96 rounded-md border-gray-300 text-sm" @keyup.enter="addSpamSender" />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-500 mb-1">Tytuł zawiera <span class="text-gray-400">(opcjonalnie)</span></label>
                        <input v-model="newSpamSubject" type="text" maxlength="255" placeholder="np. Dyskusja — puste = wszystkie maile" class="block w-64 rounded-md border-gray-300 text-sm" @keyup.enter="addSpamSender" />
                    </div>
                    <Button type="button" :leftIcon="PlusIcon" @click="addSpamSender">Dodaj do spamu</Button>
                </div>

                <ul v-if="spamSenders.length" class="divide-y divide-gray-100">
                    <li v-for="s in spamSenders" :key="s.id" class="flex items-center gap-2 py-2">
                        <NoSymbolIcon class="h-4 w-4 text-orange-500 shrink-0" />
                        <span class="text-sm text-gray-800">{{ s.from_email }}</span>
                        <span v-if="s.subject_contains" class="text-xs rounded-full bg-amber-100 text-amber-700 px-2 py-0.5">temat zawiera: „{{ s.subject_contains }}"</span>
                        <span class="text-xs text-gray-400">({{ s.count }} maili)</span>
                        <button type="button" @click="removeSpamSender(s)" class="ml-auto text-xs font-medium text-green-600 hover:text-green-700">Przywróć (nie spam)</button>
                    </li>
                </ul>
                <div v-else class="text-sm text-gray-400 py-2">Lista spamu jest pusta.</div>
            </div>
        </div>

        <div v-show="tab === 'nogroup'" class="w-full">
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-5">
                <h2 class="text-lg font-semibold text-gray-900 mb-1">Bez grupowania (wątkowania)</h2>
                <p class="text-sm text-gray-500 mb-4">
                    Maile od tych nadawców <b>nie są zwijane w wątek</b> — każdy stoi osobno na liście. Przydatne dla
                    <b>zamówień z Allegro/Amazon</b>: lecą z jednego adresu z podobnym tematem, więc bez tego zlepiają się
                    w jedną rozmowę. Wskaż adres, <b>całą domenę</b> (<code>@allegro.pl</code>) albo zawęź
                    <b>fragmentem tytułu</b>. Działa na nowych i istniejących mailach.
                </p>

                <div class="flex flex-wrap items-end gap-2 mb-5 p-3 rounded-md bg-gray-50 border border-gray-200">
                    <div>
                        <label class="block text-xs font-medium text-gray-500 mb-1">Adres e-mail lub domena nadawcy</label>
                        <input v-model="newExclude" type="text" placeholder="zamowienia@allegro.pl  lub  @allegro.pl" class="block w-96 rounded-md border-gray-300 text-sm" @keyup.enter="addThreadExclude" />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-500 mb-1">Tytuł zawiera <span class="text-gray-400">(opcjonalnie)</span></label>
                        <input v-model="newExcludeSubject" type="text" maxlength="255" placeholder="np. zamówienie — puste = wszystkie maile" class="block w-64 rounded-md border-gray-300 text-sm" @keyup.enter="addThreadExclude" />
                    </div>
                    <Button type="button" :leftIcon="PlusIcon" @click="addThreadExclude">Dodaj wykluczenie</Button>
                </div>

                <ul v-if="threadExcludes.length" class="divide-y divide-gray-100">
                    <li v-for="x in threadExcludes" :key="x.id" class="flex items-center gap-2 py-2">
                        <span class="text-sm text-gray-800">{{ x.from_email }}</span>
                        <span v-if="x.subject_contains" class="text-xs rounded-full bg-amber-100 text-amber-700 px-2 py-0.5">temat zawiera: „{{ x.subject_contains }}"</span>
                        <span class="text-xs text-gray-400">({{ x.count }} maili)</span>
                        <button type="button" @click="removeThreadExclude(x)" class="ml-auto text-xs font-medium text-green-600 hover:text-green-700">Usuń (grupuj znów)</button>
                    </li>
                </ul>
                <div v-else class="text-sm text-gray-400 py-2">Brak wykluczeń — wszystko grupuje się normalnie.</div>
            </div>
        </div>
    </PageContent>
</template>

<script setup lang="ts">
import { reactive, ref, nextTick, watch } from "vue";
import { Link, router } from "@inertiajs/vue3";
import { useToast } from "@brackets/vue-toastification";
import { PageHeader, PageContent, Button } from "crafter/Components";
import { PlusIcon, PencilSquareIcon, NoSymbolIcon, Bars3Icon } from "@heroicons/vue/24/outline";
import { TrashIcon, FolderIcon } from "@heroicons/vue/24/solid";
import draggable from "vuedraggable";

const props = defineProps<{
    catalogs: Array<{ id: number; name: string; color: string | null; parent_id: number | null; depth: number; unread: number; total: number }>;
    categories: Array<{ id: number; name: string; color: string; is_system: boolean; messages_count: number }>;
    users: Array<{ id: number; admin_user_id: number; name: string; email: string | null; color: string | null }>;
    availableUsers: Array<{ id: number; name: string; email: string | null }>;
    spamSenders: Array<{ id: number; from_email: string; subject_contains: string | null; count: number }>;
    threadExcludes: Array<{ id: number; from_email: string; subject_contains: string | null; count: number }>;
    senderRules: Array<{ id: number; from_email: string; subject_contains: string | null; catalog: { id: number; name: string; color: string | null } | null }>;
}>();

const toast = useToast();
const tab = ref<"catalogs" | "filters" | "categories" | "users" | "spam" | "nogroup">("catalogs");
const newSpam = ref("");
const newSpamSubject = ref("");
const newExclude = ref("");
const newExcludeSubject = ref("");

const newCatalog = reactive<{ name: string; parent_id: number | null }>({ name: "", parent_id: null });
const newCategory = reactive({ name: "", color: "#16a34a" });
const newRule = reactive<{ from_email: string; subject_contains: string; catalog_id: number | null }>({ from_email: "", subject_contains: "", catalog_id: null });

// Lista katalogów dla drag&drop (lokalna kopia; resetowana po przeładowaniu propsów z serwera).
const catalogList = ref([...props.catalogs]);
watch(() => props.catalogs, (v) => { catalogList.value = [...v]; });

// Zapis nowej kolejności (sort) po przeciągnięciu — backend sortuje w obrębie rodzeństwa.
function saveOrder() {
    router.post(
        route("crafter.argo-mail.catalogs.reorder"),
        { ids: catalogList.value.map((c) => c.id) },
        { preserveScroll: true, onSuccess: () => toast.success("Kolejność zapisana.") }
    );
}

function tabClass(t: string) {
    return [
        "px-1 py-2 text-sm font-medium border-b-2 -mb-px",
        tab.value === t ? "border-primary-500 text-primary-600" : "border-transparent text-gray-500 hover:text-gray-700",
    ];
}
function indent(depth: number): string {
    return "   ".repeat(depth);
}

// Kolor nazwy katalogu wg poziomu zagnieżdżenia: 1=czarny … 4+=najjaśniejszy szary.
function catalogDepthClass(depth: number): string {
    return depth >= 0 ? "text-gray-900" : "text-gray-900"; // czarna czcionka na każdym poziomie (czytelność)
}

function addCatalog() {
    if (!newCatalog.name.trim()) return;
    router.post(
        route("crafter.argo-mail.catalogs.store"),
        { name: newCatalog.name.trim(), parent_id: newCatalog.parent_id || null },
        {
            preserveScroll: true,
            onSuccess: () => { toast.success("Katalog dodany."); newCatalog.name = ""; },
            onError: (e: Record<string, string>) => toast.error(Object.values(e)[0] ?? "Błąd."),
        }
    );
}
function addSub(c: { id: number; name: string }) {
    newCatalog.parent_id = c.id;
    nextTick(() => toast.info(`Nowy katalog trafi pod „${c.name}". Wpisz nazwę i Dodaj.`));
}
function rename(c: { id: number; name: string; color: string | null }) {
    const n = window.prompt("Nazwa katalogu:", c.name);
    if (!n || !n.trim()) return;
    router.put(
        route("crafter.argo-mail.catalogs.update", c.id),
        { name: n.trim(), color: c.color },
        { preserveScroll: true, onSuccess: () => toast.success("Zmieniono nazwę.") }
    );
}
function removeCatalog(c: { id: number; name: string }) {
    if (!window.confirm(`Usunąć katalog „${c.name}" wraz z podkatalogami? Maile stracą przypisanie.`)) return;
    router.delete(route("crafter.argo-mail.catalogs.destroy", c.id), {
        preserveScroll: true,
        onSuccess: () => toast.success("Katalog usunięty."),
    });
}

// „Przenieś do…" — cele bez samego katalogu i jego podkatalogów (blokada cyklu).
function descendantIds(id: number): number[] {
    const byParent: Record<number, number[]> = {};
    props.catalogs.forEach((c) => { (byParent[c.parent_id ?? 0] ||= []).push(c.id); });
    const out: number[] = [];
    const stack = [...(byParent[id] || [])];
    while (stack.length) { const x = stack.pop() as number; out.push(x); (byParent[x] || []).forEach((ch) => stack.push(ch)); }
    return out;
}
function moveTargets(c: { id: number }) {
    const bad = new Set<number>([c.id, ...descendantIds(c.id)]);
    return props.catalogs.filter((t) => !bad.has(t.id));
}
function moveCatalog(c: { id: number; name: string }, e: Event) {
    const sel = e.target as HTMLSelectElement;
    const val = sel.value;
    sel.value = ""; // reset, by dało się wybrać ponownie
    if (val === "") return;
    const parentId = val === "root" ? null : Number(val);
    router.post(
        route("crafter.argo-mail.catalogs.move", c.id),
        { parent_id: parentId },
        {
            preserveScroll: true,
            onSuccess: () => toast.success(`Przeniesiono „${c.name}".`),
            onError: (err: Record<string, string>) => toast.error(Object.values(err)[0] ?? "Nie udało się przenieść."),
        }
    );
}

function addRule() {
    const email = newRule.from_email.trim();
    if (!email || !newRule.catalog_id) {
        toast.error("Podaj adres/domenę i wybierz katalog.");
        return;
    }
    router.post(
        route("crafter.argo-mail.rules.store"),
        { from_email: email, subject_contains: newRule.subject_contains.trim() || null, catalog_id: newRule.catalog_id },
        {
            preserveScroll: true,
            onSuccess: () => { toast.success("Reguła zapisana."); newRule.from_email = ""; newRule.subject_contains = ""; },
            onError: (e: Record<string, string>) => toast.error(Object.values(e)[0] ?? "Błąd."),
        }
    );
}
function removeRule(r: { id: number }) {
    if (!window.confirm("Usunąć tę regułę?")) return;
    router.delete(route("crafter.argo-mail.rules.destroy", r.id), {
        preserveScroll: true,
        onSuccess: () => toast.success("Reguła usunięta."),
    });
}

function addCategory() {
    if (!newCategory.name.trim()) return;
    router.post(
        route("crafter.ai-tools.mail.categories.store"),
        { name: newCategory.name.trim(), color: newCategory.color },
        {
            preserveScroll: true,
            onSuccess: () => { toast.success("Kategoria dodana."); newCategory.name = ""; },
            onError: (e: Record<string, string>) => toast.error(Object.values(e)[0] ?? "Błąd."),
        }
    );
}
function removeCategory(c: { id: number; name: string }) {
    if (!window.confirm(`Usunąć kategorię „${c.name}"?`)) return;
    router.delete(route("crafter.ai-tools.mail.categories.destroy", c.id), {
        preserveScroll: true,
        onSuccess: () => toast.success("Kategoria usunięta."),
    });
}

const newMailUser = reactive<{ admin_user_id: number | null; color: string }>({ admin_user_id: null, color: "#2563eb" });
function addMailUser() {
    if (!newMailUser.admin_user_id) return;
    router.post(
        route("crafter.argo-mail.mail-users.store"),
        { admin_user_id: newMailUser.admin_user_id, color: newMailUser.color },
        {
            preserveScroll: true,
            onSuccess: () => { toast.success("Dodano osobę."); newMailUser.admin_user_id = null; },
            onError: (e: Record<string, string>) => toast.error(Object.values(e)[0] ?? "Błąd."),
        }
    );
}
function removeMailUser(u: { id: number; name: string }) {
    if (!window.confirm(`Usunąć „${u.name}" z obsługi poczty? (maile zostają, znika tylko tab Osoby)`)) return;
    router.delete(route("crafter.argo-mail.mail-users.destroy", u.id), {
        preserveScroll: true,
        onSuccess: () => toast.success("Usunięto."),
    });
}

function addSpamSender() {
    if (!newSpam.value.trim()) return;
    router.post(
        route("crafter.argo-mail.spam.store"),
        { from_email: newSpam.value.trim(), subject_contains: newSpamSubject.value.trim() || null },
        {
            preserveScroll: true,
            onSuccess: () => { toast.success("Dodano do spamu."); newSpam.value = ""; newSpamSubject.value = ""; },
            onError: (e: Record<string, string>) => toast.error(Object.values(e)[0] ?? "Błąd."),
        }
    );
}
function removeSpamSender(s: { id: number; from_email: string }) {
    if (!window.confirm(`Przywrócić maile od „${s.from_email}"? Nadawca zniknie z listy spamu.`)) return;
    router.delete(route("crafter.argo-mail.spam.destroy", s.id), {
        preserveScroll: true,
        onSuccess: () => toast.success("Przywrócono nadawcę."),
    });
}
function addThreadExclude() {
    if (!newExclude.value.trim()) return;
    router.post(
        route("crafter.argo-mail.thread-excludes.store"),
        { from_email: newExclude.value.trim(), subject_contains: newExcludeSubject.value.trim() || null },
        {
            preserveScroll: true,
            onSuccess: () => { toast.success("Dodano wykluczenie — maile rozgrupowane."); newExclude.value = ""; newExcludeSubject.value = ""; },
            onError: (e: Record<string, string>) => toast.error(Object.values(e)[0] ?? "Błąd."),
        }
    );
}
function removeThreadExclude(x: { id: number; from_email: string }) {
    if (!window.confirm(`Usunąć wykluczenie dla „${x.from_email}"? Maile znów będą się grupować w wątki.`)) return;
    router.delete(route("crafter.argo-mail.thread-excludes.destroy", x.id), {
        preserveScroll: true,
        onSuccess: () => toast.success("Usunięto wykluczenie."),
    });
}
</script>
