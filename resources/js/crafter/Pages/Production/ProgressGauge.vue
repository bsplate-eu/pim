<!--
  Barometr wykonania projektow — polokrag w naglowku Produkcji.

  100% = wszystkie kody, domyslnie czerwone. Zielony wycinek to kody, ktore
  weszly do „Gotowe". Wskazowka pokazuje biezacy stan.

  Liczby stoja OBOK tarczy, a nie pod nia: naglowek jest przyklejony do gory,
  wiec kazdy piksel jego wysokosci placi sie przez cale przewijanie 700 wierszy.
  Tekst w srodku polokregu odpada — tam pracuje wskazowka i przy ~50% wchodzilaby
  na cyfry.
-->
<template>
    <div class="flex items-center gap-3">
        <svg :width="W" :height="H" :viewBox="`0 0 ${W} ${H}`" class="flex-none">
            <!-- tlo: caly zakres na czerwono -->
            <path :d="arcPath(0, 1)" :stroke="RED" :stroke-width="w" fill="none" stroke-linecap="round" />
            <!-- wykonane -->
            <path
                v-if="ratio > 0"
                :d="arcPath(0, ratio)"
                :stroke="GREEN"
                :stroke-width="w"
                fill="none"
                stroke-linecap="round"
            />

            <g v-for="tick in ticks" :key="tick.value">
                <line
                    :x1="tick.x1" :y1="tick.y1" :x2="tick.x2" :y2="tick.y2"
                    stroke="#d1d5db" stroke-width="1.5"
                />
                <text
                    :x="tick.lx" :y="tick.ly"
                    font-size="9" fill="#9ca3af"
                    text-anchor="middle" dominant-baseline="middle"
                >{{ tick.value }}</text>
            </g>

            <line
                :x1="hub.x" :y1="hub.y" :x2="needle.x" :y2="needle.y"
                stroke="#111827" stroke-width="2.5" stroke-linecap="round"
            />
            <circle :cx="cx" :cy="cy" r="4.5" fill="#111827" />
        </svg>

        <div class="leading-tight">
            <div class="text-xl font-semibold text-gray-900">{{ percentLabel }}</div>
            <div class="text-xs text-gray-500">{{ done }} / {{ total }} gotowych</div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { computed } from "vue";

interface Props {
    done: number;
    total: number;
}

const props = defineProps<Props>();

const RED = "#dc2626";
const GREEN = "#16a34a";

// Geometria dobrana tak, zeby skrajne etykiety (0 i 100) miescily sie w viewBox:
// promien etykiet + polowa szerokosci napisu <= W/2.
const W = 140;
const H = 78;
const cx = W / 2;
const cy = 66;
const r = 38;
const w = 12;
const LABEL_R = r + w / 2 + 14;

// Polokrag: 180 stopni (lewo) -> 360 (prawo), zgodnie z ruchem wskazowek.
const START = 180;
const SWEEP = 180;

const ratio = computed<number>(() => {
    if (!props.total) return 0;
    return Math.min(Math.max(props.done / props.total, 0), 1);
});

const polar = (radius: number, deg: number) => {
    const rad = (deg * Math.PI) / 180;
    return { x: cx + radius * Math.cos(rad), y: cy + radius * Math.sin(rad) };
};

function arcPath(from: number, to: number): string {
    const a1 = START + SWEEP * from;
    const a2 = START + SWEEP * to;
    const s = polar(r, a1);
    const e = polar(r, a2);
    const large = a2 - a1 > 180 ? 1 : 0;

    return `M ${s.x.toFixed(2)} ${s.y.toFixed(2)} A ${r} ${r} 0 ${large} 1 ${e.x.toFixed(2)} ${e.y.toFixed(2)}`;
}

const ticks = computed(() =>
    [0, 25, 50, 75, 100].map((value) => {
        const deg = START + (SWEEP * value) / 100;
        const inner = polar(r + w / 2 + 2, deg);
        const outer = polar(r + w / 2 + 6, deg);
        const label = polar(LABEL_R, deg);

        return {
            value,
            x1: inner.x, y1: inner.y,
            x2: outer.x, y2: outer.y,
            lx: label.x, ly: label.y,
        };
    })
);

const needle = computed(() => polar(r - 3, START + SWEEP * ratio.value));
const hub = computed(() => polar(6, START + SWEEP * ratio.value + 180));

const formatter = new Intl.NumberFormat("pl-PL", {
    minimumFractionDigits: 1,
    maximumFractionDigits: 1,
});
const percentLabel = computed<string>(() => formatter.format(ratio.value * 100) + "%");
</script>
