import { getPluginGlobal } from "@/shared/lib/api";
import { __ } from "@/shared/lib/i18n";
import { formatMoney } from "@/shared/lib/units";
import type { OrderStatus, PaymentStatus } from "./types";

function locale(): string {
    return getPluginGlobal().locale.replace("_", "-");
}

/**
 * Amounts are in the order's own currency. The store format applies when it
 * matches; an order in another currency (an import) falls back to Intl.
 */
export function orderMoney(minor: number, code: string): string {
    const { currency } = getPluginGlobal();
    if (code === "" || code === currency.code) {
        return formatMoney(minor, currency);
    }
    try {
        const format = new Intl.NumberFormat(locale(), { style: "currency", currency: code });
        const digits = format.resolvedOptions().maximumFractionDigits ?? 2;
        return format.format(minor / 10 ** digits);
    } catch {
        return `${minor} ${code}`;
    }
}

/** The minor-unit exponent of an order's currency. */
export function currencyExponent(code: string): number {
    const { currency } = getPluginGlobal();
    if (code === "" || code === currency.code) {
        return currency.exponent;
    }
    try {
        return new Intl.NumberFormat("en", { style: "currency", currency: code }).resolvedOptions().maximumFractionDigits ?? 2;
    } catch {
        return 2;
    }
}

export function formatDate(iso: string | null, withTime = true): string {
    if (iso === null) {
        return "";
    }
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) {
        return iso;
    }
    return new Intl.DateTimeFormat(locale(), withTime ? { dateStyle: "medium", timeStyle: "short" } : { dateStyle: "medium" }).format(date);
}

export const STATUS_LABEL: Record<OrderStatus, string> = {
    pending: __("Pending payment"),
    "on-hold": __("On hold"),
    processing: __("Processing"),
    completed: __("Completed"),
    cancelled: __("Cancelled"),
    failed: __("Failed"),
    refunded: __("Refunded"),
};

export const STATUS_TONE: Record<OrderStatus, "muted" | "brand" | "success" | "destructive"> = {
    pending: "muted",
    "on-hold": "muted",
    processing: "brand",
    completed: "success",
    cancelled: "muted",
    failed: "destructive",
    refunded: "muted",
};

export const PAYMENT_LABEL: Record<PaymentStatus, string> = {
    unpaid: __("Unpaid"),
    partially_paid: __("Partially paid"),
    paid: __("Paid"),
    partially_refunded: __("Partially refunded"),
    refunded: __("Refunded"),
};

export const STATUSES: OrderStatus[] = ["pending", "on-hold", "processing", "completed", "cancelled", "failed", "refunded"];
