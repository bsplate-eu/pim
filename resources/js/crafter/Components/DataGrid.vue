<template>
  <div ref="wrapEl" class="revo-grid-wrap" :style="{ height: heightStyle }">
    <VGrid
      :source="visibleSource"
      :columns="effectiveColumns"
      :theme="theme"
      :range="true"
      :resize="true"
      :useClipboard="true"
      :applyOnClose="true"
      :frameSize="effectiveFrameSize"
      :rowSize="rowSize"
      @beforeedit="onBeforeChange"
      @beforerangeedit="onBeforeChange"
      @afteredit="onAfterEdit"
    />
  </div>
</template>

<script setup lang="ts">
import { shallowRef, computed, ref, onMounted, onBeforeUnmount } from "vue";
import { VGrid } from "@revolist/vue3-datagrid";

type Row = Record<string, any>;

interface Props {
  modelValue: Row[];
  columns: any[];
  /**
   * Wysokosc kontenera. Przyjmuje:
   * - dowolna wartosc CSS (np. "60vh", "500px", "calc(100vh - 240px)")
   * - "auto" -> grid sam liczy wysokosc na podstawie liczby wierszy widocznych (po filtrze)
   *   i strona scrolluje sie zamiast suwaka wewnatrz gridu.
   */
  height?: string;
  theme?: string;
  // Stabilny klucz wiersza (np. "product_id"). Gdy ustawiony, edycje dopasowuja sie
  // po wartosci klucza zamiast po rowIndex (krytyczne przy sortowaniu).
  keyField?: string;
  // Funkcja filtrujaca wiersze do wyswietlenia (true = widoczny). Brak/undefined = wszystko.
  filter?: ((row: Row) => boolean) | null;
  // Wlacza kolumne z checkboxami selekcji + checkbox "zaznacz wszystko" w naglowku.
  // Wymaga keyField.
  selectable?: boolean;
  // Wysokosc pojedynczego wiersza w pikselach (default RevoGrid 30).
  rowSize?: number;
  // Po kazdej edycji inline odswieza CALY widoczny grid (re-render). Potrzebne, gdy sa
  // kolumny pochodne (cellTemplate liczacy z innych komorek, np. zysk/marza z ceny).
  refreshAfterEdit?: boolean;
  // Ile wierszy poza viewportem ma byc wyrenderowanych. Dla height="auto" liczone tak,
  // zeby wszystkie wiersze byly w DOM (page scroll, zero suwaka w gridzie).
  frameSize?: number;
}

const props = withDefaults(defineProps<Props>(), {
  height: "70vh",
  theme: "compact",
  keyField: undefined,
  filter: null,
  selectable: false,
  rowSize: 32,
  frameSize: undefined,
  refreshAfterEdit: false,
});

const emit = defineEmits<{
  (e: "update:modelValue", rows: Row[]): void;
  (e: "update:selection", ids: any[]): void;
}>();

// Element nadrzedny gridu — do odczytu zakresu/widocznego zrodla i podpiecia klawiatury.
const wrapEl = ref<HTMLElement | null>(null);

// === HISTORIA (undo/redo) ===
// Jedna akcja uzytkownika (edycja / wklejenie / czyszczenie Del) = jeden wpis (batch zmian).
// Trzymamy referencje do obiektow wierszy, wiec dziala niezaleznie od keyField i sortowania.
type Change = { row: Row; prop: string | number; oldValue: any; newValue: any };
const undoStack = shallowRef<Change[][]>([]);
const redoStack = shallowRef<Change[][]>([]);
const MAX_HISTORY = 100;

// shallowRef: mutacje wlasciwosci wierszy NIE wywoluja reaktywnosci.
// Computed visibleSource przelicza sie tylko gdy zmieni sie filter prop albo source reference.
const source = shallowRef<Row[]>(props.modelValue.map((row) => ({ ...row })));

const visibleSource = computed<Row[]>(() =>
  props.filter ? source.value.filter(props.filter) : source.value
);

// Wysokosc: jesli "auto" -> liczymy na podstawie liczby widocznych wierszy + header + bufor.
const HEADER_PX = 40;
const BUFFER_PX = 24;
const heightStyle = computed<string>(() => {
  if (props.height === "auto") {
    const count = visibleSource.value.length;
    return `${count * props.rowSize + HEADER_PX + BUFFER_PX}px`;
  }
  return props.height;
});

// frameSize: dla "auto" trzeba miec WSZYSTKO w DOM (zero internal scroll).
// Dla fixed-height zostawiamy default RevoGrid (z props.frameSize lub undefined).
const effectiveFrameSize = computed<number | undefined>(() => {
  if (props.height === "auto") {
    return Math.max(visibleSource.value.length + 100, 200);
  }
  return props.frameSize;
});

// === SELEKCJA ===
const selection = shallowRef<Set<any>>(new Set());

function toggleRow(key: any, checked: boolean): void {
  const next = new Set(selection.value);
  if (checked) next.add(key);
  else next.delete(key);
  selection.value = next;
  forceRefresh();
  emit("update:selection", Array.from(next));
}

function selectAllVisible(): void {
  if (!props.keyField) return;
  const visible = props.filter ? source.value.filter(props.filter) : source.value;
  const next = new Set<any>(visible.map((r) => r[props.keyField!]));
  selection.value = next;
  forceRefresh();
  emit("update:selection", Array.from(next));
}

function clearSelection(): void {
  selection.value = new Set();
  forceRefresh();
  emit("update:selection", []);
}

// RevoGrid nie re-renderuje na zmiane zewnetrznego state. Podmiana referencji source
// wymusza re-render i odswiezenie checkboxow.
function forceRefresh(): void {
  source.value = [...source.value];
}

// Definicja kolumny selekcji (cell + header checkbox). HyperFunc 'h' to Stencil h(tag, attrs, ...children).
const checkboxColumn = computed<any | null>(() => {
  if (!props.selectable || !props.keyField) return null;
  return {
    prop: "__select__",
    name: "",
    size: 44,
    pin: "colPinStart",
    readonly: true,
    sortable: false,
    cellTemplate: (h: any, p: any) => {
      const key = p.model?.[props.keyField!];
      const checked = selection.value.has(key);
      return h(
        "div",
        { class: "revo-checkbox-cell" },
        h("input", {
          type: "checkbox",
          checked,
          onClick: (e: any) => e.stopPropagation(),
          onChange: (e: any) => toggleRow(key, !!(e.target as HTMLInputElement).checked),
        })
      );
    },
    columnTemplate: (h: any) => {
      const visible = props.filter ? source.value.filter(props.filter) : source.value;
      const keys = visible.map((r: any) => r[props.keyField!]);
      const allSelected = keys.length > 0 && keys.every((k: any) => selection.value.has(k));
      return h(
        "div",
        { class: "revo-checkbox-cell" },
        h("input", {
          type: "checkbox",
          checked: allSelected,
          onClick: (e: any) => {
            e.stopPropagation();
            const ck = !!(e.target as HTMLInputElement).checked;
            if (ck) selectAllVisible();
            else clearSelection();
          },
        })
      );
    },
  };
});

const effectiveColumns = computed<any[]>(() =>
  checkboxColumn.value ? [checkboxColumn.value, ...props.columns] : props.columns
);

// === API DLA PARENTA ===
defineExpose({
  getSource(): Row[] {
    return source.value;
  },
  setSource(rows: Row[]): void {
    source.value = rows;
    // Pelna podmiana danych -> historia undo/redo nie ma juz sensu (stare referencje znikaja).
    undoStack.value = [];
    redoStack.value = [];
    emit("update:modelValue", source.value);
  },
  getSelection(): any[] {
    return Array.from(selection.value);
  },
  getSelectedRows(): Row[] {
    if (!props.keyField) return [];
    return source.value.filter((r) => selection.value.has(r[props.keyField!]));
  },
  clearSelection,
  selectAllVisible,
  undo,
  redo,
  canUndo: () => undoStack.value.length > 0,
  canRedo: () => redoStack.value.length > 0,
});

function findRow(model: any, fallbackIndex: number): Row | undefined {
  if (props.keyField && model && model[props.keyField] !== undefined) {
    const key = model[props.keyField];
    const found = source.value.find((r) => r[props.keyField!] === key);
    if (found) return found;
  }
  return source.value[fallbackIndex];
}

function isBlank(v: any): boolean {
  return v === null || v === undefined || v === "";
}

// Iteruje po zmianach z eventu edycji. RevoGrid: pojedyncza komorka ma `prop`/`val`/
// `rowIndex`/`model`; zakres (wklejanie/autofill/czyszczenie Del) ma
// `data = { [rowIndex]: { [prop]: val } }` i `models = { [rowIndex]: rowModel }`.
// Wiersz dopasowujemy po `keyField` (odpornie na sortowanie).
function forEachEditChange(
  detail: any,
  cb: (row: Row | undefined, prop: string | number, value: any) => void
) {
  if (detail.prop !== undefined) {
    cb(findRow(detail.model, detail.rowIndex), detail.prop, detail.val);
  } else if (detail.data) {
    for (const rowIndex of Object.keys(detail.data)) {
      const changes = detail.data[rowIndex] ?? {};
      const model = detail.models?.[rowIndex];
      for (const prop of Object.keys(changes)) {
        cb(findRow(model, Number(rowIndex)), prop, changes[prop]);
      }
    }
  }
}

// PRZED zapisem (beforeedit/beforerangeedit): RevoGrid mutuje nasze obiekty wierszy
// W MIEJSCU dopiero przy zapisie, wiec TERAZ `row[prop]` to jeszcze STARA wartosc —
// to jedyny moment, w ktorym mozemy ja zlapac do historii undo.
function onBeforeChange(e: CustomEvent) {
  const detail: any = (e as any)?.detail ?? e;
  if (!detail) return;

  const batch: Change[] = [];
  forEachEditChange(detail, (row, prop, value) => {
    if (!row) return;
    const oldValue = row[prop];
    // Pomijamy realne no-opy (np. Del na pustej komorce) — bez wpisu w historii.
    if (oldValue === value || (isBlank(oldValue) && isBlank(value))) return;
    batch.push({ row, prop, oldValue, newValue: value });
  });

  if (batch.length) {
    const next = undoStack.value.slice(-(MAX_HISTORY - 1));
    next.push(batch);
    undoStack.value = next;
    redoStack.value = []; // nowa edycja kasuje stos redo
  }
}

// PO zapisie: RevoGrid juz zmutowal nasze wiersze w miejscu; ustawiamy wartosc
// defensywnie (na wypadek innej sciezki) i propagujemy stan do rodzica.
function onAfterEdit(e: CustomEvent) {
  const detail: any = (e as any)?.detail ?? e;
  if (detail) {
    forEachEditChange(detail, (row, prop, value) => {
      if (row) row[prop] = value;
    });
  }
  // Kolumny pochodne (zysk/marza) licza z innej komorki — RevoGrid nie odswiezy ich sam.
  if (props.refreshAfterEdit) {
    try {
      gridEl()?.refresh?.();
    } catch {
      /* noop */
    }
  }
  emit("update:modelValue", source.value);
}

// Cofniecie/ponowienie: przywracamy wartosci wprost na obiektach wierszy (RevoGrid
// wspoldzieli te referencje, wiec jego store tez sie cofa) i wymuszamy re-render:
// forceRefresh (podmiana referencji source -> dataSourceChanged) + jawny grid.refresh().
function revertBatch(batch: Change[], useOld: boolean) {
  for (const ch of batch) {
    ch.row[ch.prop] = useOld ? ch.oldValue : ch.newValue;
  }
  forceRefresh();
  try {
    gridEl()?.refresh?.();
  } catch {
    /* noop */
  }
  emit("update:modelValue", source.value);
}

function undo() {
  const stack = undoStack.value;
  if (!stack.length) return;
  const batch = stack[stack.length - 1];
  undoStack.value = stack.slice(0, -1);
  revertBatch(batch, true);
  redoStack.value = [...redoStack.value, batch];
}

function redo() {
  const stack = redoStack.value;
  if (!stack.length) return;
  const batch = stack[stack.length - 1];
  redoStack.value = stack.slice(0, -1);
  revertBatch(batch, false);
  undoStack.value = [...undoStack.value, batch];
}

// === KOPIOWANIE (Ctrl/Cmd+C) ===
// RevoGrid liczy na to, ze przegladarka sama wystawi natywny event `copy` przy Ctrl+C nad
// zakresem gridu. Selekcja gridu NIE jest natywna selekcja DOM, wiec event czesto sie nie
// odpala (= "nie kopiuje"). Przejmujemy skrot sami i budujemy TSV z zaznaczonego zakresu.
function gridEl(): any | null {
  return wrapEl.value?.querySelector("revo-grid") ?? null;
}

async function copySelection(): Promise<void> {
  const grid = gridEl();
  if (!grid?.getSelectedRange) return;

  let range: any = null;
  try {
    range = await grid.getSelectedRange();
  } catch {
    /* noop */
  }
  // Brak zakresu (pojedyncza komorka) -> budujemy 1x1 z fokusu.
  if (!range) {
    try {
      const f = await grid.getFocused?.();
      if (f?.cell) range = { x: f.cell.x, y: f.cell.y, x1: f.cell.x, y1: f.cell.y };
    } catch {
      /* noop */
    }
  }
  if (!range) return;

  let visible: Row[] = visibleSource.value;
  try {
    visible = (await grid.getVisibleSource?.()) ?? visibleSource.value;
  } catch {
    /* fallback: widoczne zrodlo z wrappera */
  }

  // `x` indeksuje kolumny srodkowego viewportu = props.columns (kolumna checkboxa jest
  // przypieta osobno, wiec jej tu nie ma). `y` to indeks w widocznej kolejnosci wierszy.
  const cols = props.columns;
  const x0 = Math.min(range.x, range.x1);
  const x1 = Math.max(range.x, range.x1);
  const y0 = Math.min(range.y, range.y1);
  const y1 = Math.max(range.y, range.y1);

  const lines: string[] = [];
  for (let y = y0; y <= y1; y++) {
    const rowData: any = visible[y] ?? {};
    const cells: string[] = [];
    for (let x = x0; x <= x1; x++) {
      const col = cols[x];
      const v = col ? rowData[col.prop] : "";
      cells.push(v === null || v === undefined ? "" : String(v));
    }
    lines.push(cells.join("\t"));
  }
  writeClipboard(lines.join("\n"));
}

// Zapis do schowka dzialajacy takze w niezabezpieczonym kontekscie (http://pim.test, gdzie
// navigator.clipboard jest niedostepny): ukryty <textarea> + execCommand('copy'). Guard w
// fazie capture gwarantuje, ze trafi DOKLADNIE nasz tekst i clipboard RevoGrid go nie nadpisze.
let pendingCopyText: string | null = null;

function onDocCopyCapture(e: ClipboardEvent) {
  if (pendingCopyText == null) return;
  try {
    e.clipboardData?.setData("text/plain", pendingCopyText);
    e.preventDefault();
    e.stopImmediatePropagation();
  } catch {
    /* noop */
  }
}

function writeClipboard(text: string): void {
  const prevActive = document.activeElement as HTMLElement | null;
  pendingCopyText = text;
  const ta = document.createElement("textarea");
  ta.value = text;
  ta.setAttribute("readonly", "");
  ta.style.cssText = "position:fixed;top:-9999px;left:-9999px;opacity:0;";
  document.body.appendChild(ta);
  ta.focus();
  ta.select();
  let ok = false;
  try {
    ok = document.execCommand("copy");
  } catch {
    ok = false;
  }
  document.body.removeChild(ta);
  pendingCopyText = null;
  // W bezpiecznym kontekscie (https/localhost) gdyby execCommand zawiodl — async API.
  if (!ok && navigator.clipboard?.writeText) {
    navigator.clipboard.writeText(text).catch(() => {});
  }
  // Wracamy fokus do gridu, zeby klawiatura dzialala dalej.
  if (prevActive && prevActive !== document.body) {
    try {
      prevActive.focus({ preventScroll: true } as any);
    } catch {
      /* noop */
    }
  }
}

// === KLAWIATURA ===
function eventRealTarget(e: Event): HTMLElement | null {
  // composedPath()[0] zwraca realny element nawet zza granicy shadow DOM.
  const path = (e as any).composedPath?.();
  const t = (path && path[0]) || e.target;
  return t instanceof HTMLElement ? t : null;
}

function isEditingTarget(t: HTMLElement | null): boolean {
  if (!t) return false;
  const tag = t.tagName;
  return tag === "INPUT" || tag === "TEXTAREA" || tag === "SELECT" || t.isContentEditable;
}

function onKeyDown(e: KeyboardEvent): void {
  if (!(e.ctrlKey || e.metaKey)) return;
  // W otwartym edytorze komorki zostawiamy natywne zachowanie (kopiowanie/undo tekstu).
  if (isEditingTarget(eventRealTarget(e))) return;

  const code = e.code;
  // Kopiowanie: Ctrl/Cmd+C lub Ctrl+Insert.
  if ((code === "KeyC" && !e.altKey) || (code === "Insert" && !e.shiftKey && !e.altKey)) {
    e.preventDefault();
    e.stopPropagation();
    void copySelection();
    return;
  }
  // Ponowienie: Ctrl+Y lub Ctrl+Shift+Z.
  if ((code === "KeyY" && !e.shiftKey) || (code === "KeyZ" && e.shiftKey)) {
    e.preventDefault();
    e.stopPropagation();
    redo();
    return;
  }
  // Cofniecie: Ctrl/Cmd+Z.
  if (code === "KeyZ" && !e.shiftKey && !e.altKey) {
    e.preventDefault();
    e.stopPropagation();
    undo();
    return;
  }
}

onMounted(() => {
  // capture: przejmujemy skroty PRZED wewnetrzna obsluga RevoGrid.
  wrapEl.value?.addEventListener("keydown", onKeyDown, true);
  // capture: nasz guard biegnie przed (bubble) listenerem clipboardu RevoGrid na document.
  document.addEventListener("copy", onDocCopyCapture, true);
});

onBeforeUnmount(() => {
  wrapEl.value?.removeEventListener("keydown", onKeyDown, true);
  document.removeEventListener("copy", onDocCopyCapture, true);
});
</script>

<style scoped>
.revo-grid-wrap {
  width: 100%;
}
.revo-grid-wrap :deep(revo-grid) {
  height: 100%;
  display: block;
}

/* Strzalka "⇅" na KAZDEJ sortowalnej kolumnie (RevoGrid renderuje <i class="sort-off">,
   ale domyslnie nie ma dla niej content). Po klikniecu RevoGrid podmienia klase na
   "asc"/"desc" i wlasne reguly content "↑"/"↓" przejmuja stylizacje. */
.revo-grid-wrap :deep(revogr-header .rgHeaderCell.sortable .sort-indicator) {
  margin-left: 4px;
}
.revo-grid-wrap :deep(revogr-header .rgHeaderCell.sortable .sort-indicator i.sort-off::after) {
  content: "⇅";
  font-size: 13px;
  opacity: 0.35;
}

/* Checkbox cell — wycentrowany w komorce i naglowku. */
.revo-grid-wrap :deep(.revo-checkbox-cell) {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  height: 100%;
}
.revo-grid-wrap :deep(.revo-checkbox-cell input[type="checkbox"]) {
  width: 16px;
  height: 16px;
  cursor: pointer;
  accent-color: #b91c1c;
}
</style>
