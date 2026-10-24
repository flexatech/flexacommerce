/**
 * Conversions between what the server stores and what the merchant types.
 * Money is an integer in the minor unit; weight is grams and dimensions are
 * millimetres, kept as decimal strings with at most three decimals.
 */

import type { FlexaCommerceGlobal } from "@/shared/types/global";

type WeightUnit = FlexaCommerceGlobal["units"]["weight"];
type DimensionUnit = FlexaCommerceGlobal["units"]["dimension"];

const GRAMS: Record<WeightUnit, number> = { g: 1, kg: 1000, lb: 453.59237, oz: 28.349523125 };
const MILLIMETRES: Record<DimensionUnit, number> = { mm: 1, cm: 10, m: 1000, in: 25.4, yd: 914.4 };

/** Accepts "12", "12.5" or "12,5"; returns null for an empty field and NaN for junk. */
export function parseDecimal(text: string): number | null {
    const clean = text.trim().replace(/\s/g, "").replace(",", ".");
    if (clean === "") {
        return null;
    }
    return /^\d+(\.\d+)?$/.test(clean) ? Number(clean) : Number.NaN;
}

function trimZeros(value: number, decimals: number): string {
    return value.toFixed(decimals).replace(/\.?0+$/, "");
}

export function minorToInput(minor: number | null, exponent: number): string {
    if (minor === null) {
        return "";
    }
    return exponent === 0 ? String(minor) : (minor / 10 ** exponent).toFixed(exponent);
}

/** Major-unit text to minor units; NaN when the text is not a valid amount. */
export function inputToMinor(text: string, exponent: number): number | null {
    const value = parseDecimal(text);
    if (value === null || Number.isNaN(value)) {
        return value;
    }
    const decimals = text.includes(".") || text.includes(",") ? text.trim().split(/[.,]/)[1]?.length ?? 0 : 0;
    if (decimals > exponent) {
        return Number.NaN;
    }
    return Math.round(value * 10 ** exponent);
}

export function formatMoney(minor: number | null, currency: FlexaCommerceGlobal["currency"]): string {
    if (minor === null) {
        return "";
    }
    const fixed = minorToInput(minor, currency.exponent);
    const [whole, fraction] = fixed.split(".");
    const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, currency.thousandSeparator);
    const amount = fraction ? grouped + currency.decimalSeparator + fraction : grouped;
    switch (currency.position) {
        case "right":
            return amount + currency.symbol;
        case "left_space":
            return currency.symbol + " " + amount;
        case "right_space":
            return amount + " " + currency.symbol;
        default:
            return currency.symbol + amount;
    }
}

export function gramsToInput(grams: string | null, unit: WeightUnit): string {
    return grams === null ? "" : trimZeros(Number(grams) / GRAMS[unit], 3);
}

export function inputToGrams(text: string, unit: WeightUnit): string | null | false {
    const value = parseDecimal(text);
    if (value === null) {
        return null;
    }
    if (Number.isNaN(value)) {
        return false;
    }
    return trimZeros(value * GRAMS[unit], 3) || "0";
}

export function millimetresToInput(mm: string | null, unit: DimensionUnit): string {
    return mm === null ? "" : trimZeros(Number(mm) / MILLIMETRES[unit], 3);
}

export function inputToMillimetres(text: string, unit: DimensionUnit): string | null | false {
    const value = parseDecimal(text);
    if (value === null) {
        return null;
    }
    if (Number.isNaN(value)) {
        return false;
    }
    return trimZeros(value * MILLIMETRES[unit], 3) || "0";
}

/** "2026-10-24 08:00:00" (UTC) to a `datetime-local` value in the browser's zone. */
export function utcToLocalInput(utc: string | null): string {
    if (!utc) {
        return "";
    }
    const date = new Date(utc.replace(" ", "T") + "Z");
    if (Number.isNaN(date.getTime())) {
        return "";
    }
    const pad = (n: number) => String(n).padStart(2, "0");
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

export function localInputToUtc(local: string): string | null {
    if (!local) {
        return null;
    }
    const date = new Date(local);
    if (Number.isNaN(date.getTime())) {
        return null;
    }
    return date.toISOString().slice(0, 19).replace("T", " ");
}
