import type { badgeVariants } from "@/shared/components/ui/badge";
import { __ } from "@/shared/lib/i18n";
import type { VariantProps } from "class-variance-authority";
import type { CounterName, Direction, JobStatus, Stage } from "./types";

type Tone = NonNullable<VariantProps<typeof badgeVariants>["variant"]>;

export const DIRECTION_LABELS: Record<Direction, string> = {
    wc_to_fxc: __("WooCommerce to FlexaCommerce"),
    fxc_to_wc: __("FlexaCommerce to WooCommerce"),
};

export const STAGE_LABELS: Record<Stage, string> = {
    settings: __("Settings"),
    taxes: __("Tax rates"),
    shipping: __("Shipping zones"),
    attributes: __("Attributes"),
    terms: __("Categories, tags and values"),
    products: __("Products"),
    customers: __("Customers"),
    orders: __("Orders"),
};

export const STATUS_LABELS: Record<JobStatus, string> = {
    draft: __("Blocked"),
    ready: __("Ready"),
    running: __("Running"),
    paused: __("Paused"),
    failed: __("Failed"),
    cancelled: __("Cancelled"),
    verifying: __("Verifying"),
    completed: __("Completed"),
    completed_with_warnings: __("Completed with warnings"),
};

export const STATUS_TONES: Record<JobStatus, Tone> = {
    draft: "destructive",
    ready: "brand",
    running: "brand",
    paused: "muted",
    failed: "destructive",
    cancelled: "muted",
    verifying: "brand",
    completed: "success",
    completed_with_warnings: "success",
};

export const COUNTERS: CounterName[] = ["created", "updated", "unchanged", "skipped", "rejected", "warnings"];

export const COUNTER_LABELS: Record<CounterName, string> = {
    created: __("Created"),
    updated: __("Updated"),
    unchanged: __("Unchanged"),
    skipped: __("Skipped"),
    rejected: __("Rejected"),
    warnings: __("Warnings"),
};

/** Dry runs write nothing, so their counters read as intent. */
export const DRY_RUN_COUNTER_LABELS: Record<CounterName, string> = {
    created: __("Would create"),
    updated: __("Would update"),
    unchanged: __("Unchanged"),
    skipped: __("Would skip"),
    rejected: __("Would reject"),
    warnings: __("Warnings"),
};

/** Bytes as B, KB, MB or GB, one decimal above KB. */
export function formatBytes(bytes: number): string {
    const units = ["B", "KB", "MB", "GB"];
    let value = bytes;
    let unit = 0;
    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit++;
    }
    return `${unit < 2 ? Math.round(value) : value.toFixed(1)} ${units[unit]}`;
}

export function formatValue(value: unknown): string {
    return typeof value === "string" ? value : JSON.stringify(value);
}

/** Saves a JSON copy of a report through a temporary link. */
export function download(name: string, data: unknown): void {
    const url = URL.createObjectURL(new Blob([JSON.stringify(data, null, 2)], { type: "application/json" }));
    const link = document.createElement("a");
    link.href = url;
    link.download = name;
    link.click();
    URL.revokeObjectURL(url);
}
