export type Direction = "wc_to_fxc" | "fxc_to_wc";

export type Stage = "settings" | "taxes" | "shipping" | "attributes" | "terms" | "products" | "options" | "customers" | "orders" | "coupons";

export type JobStatus =
    | "draft"
    | "ready"
    | "running"
    | "paused"
    | "failed"
    | "cancelled"
    | "verifying"
    | "completed"
    | "completed_with_warnings";

export type CounterName = "created" | "updated" | "unchanged" | "skipped" | "rejected" | "warnings";

export type Counters = Partial<Record<CounterName, number>>;

export interface JobOptions {
    stages: Stage[];
    target_modified: "skip" | "source_wins";
    duplicate_sku: "null" | "skip" | "suffix";
    order_number_conflict: "prefix" | "skip";
    orders_from: string | null;
    orders_to: string | null;
    backup_confirmed: boolean;
    media: "download" | "skip";
    [key: string]: unknown;
}

export interface Problem {
    level: "error" | "warning" | "info";
    code: string;
    message: string;
    context: Record<string, unknown>;
}

export interface Mismatch {
    entity: string;
    source_id: string;
    target_id: number;
    field: string;
    expected: unknown;
    actual: unknown;
}

export interface JobReport {
    preflight?: Problem[];
    checked_at?: string;
    stages?: Partial<Record<Stage, Counters & { source: number; mapped: number }>>;
    checked?: number;
    mismatches?: Mismatch[];
    verified_at?: string;
}

export interface Job {
    id: number;
    direction: Direction;
    status: JobStatus;
    dry_run: boolean;
    options: JobOptions;
    stage: string;
    /** Not in the list payload. */
    cursor?: Record<string, { last_id: number; upper_id: number; done?: boolean }>;
    counters: Partial<Record<Stage, Counters>>;
    totals: Counters;
    /** Not in the list payload. */
    report?: JobReport;
    /** Only on a job that reads an export file. */
    file?: JobFile;
    created_by: number;
    created_at: string;
    updated_at: string;
    started_at: string | null;
    finished_at: string | null;
}

export interface JobFile {
    name: string;
    size: number;
    exists: boolean;
    /** Uploaded through this screen, so the screen may delete it. */
    removable: boolean;
}

export interface MigrationsIndex {
    jobs: Job[];
    woocommerce: boolean;
    directions: Direction[];
    stages: Stage[];
    defaults: JobOptions;
}

export interface BatchResult {
    job: Job;
    processed: number;
    done: boolean;
    locked: boolean;
}

export interface LogRow {
    id: number;
    job_id: number;
    entity: string;
    source_id: string;
    level: "info" | "warning" | "error";
    code: string;
    message: string;
    context: Record<string, unknown>;
    created_at: string;
}

export type PurgeResult = Record<string, { deleted: number; kept: number }>;

export type CutoverStepKey = "freeze" | "verify" | "bases" | "pages" | "deactivate" | "urls" | "redirects" | "reopen";

export type BaseKey = "product" | "shop" | "category" | "tag";

export interface CutoverStatus {
    woocommerce: boolean;
    steps: { key: CutoverStepKey; done: boolean; at: string | null }[];
    job_id: number | null;
    bases: Record<BaseKey, { wc: string; fxc: string; identical: boolean }>;
    pages: ({ mode: "flexacommerce" | "woocommerce" } & Record<string, number | string>) | null;
    urls: {
        checked: number;
        failed: number;
        checked_at: string;
        results: { type: "product" | "category"; id: number; url: string; status: number; ok: boolean }[];
    } | null;
    finished: boolean;
}
