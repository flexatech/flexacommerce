import { useMutation } from "@tanstack/react-query";
import { useCallback, useEffect, useRef, useState } from "react";

import { api, getPluginGlobal } from "@/shared/lib/api";
import { download, type CsvRow } from "./csv";
import type { ExportPage, ImportResult, ImportSummary, MapResponse } from "./types";

export function productsUrl(): string {
    return getPluginGlobal().adminUrl.replace(/admin\.php$/, "edit.php?post_type=fxc_product");
}

/** Rows per import request; a product's variations stay in its batch. */
const BATCH = 10;
const MAX_BATCH = 200;

export interface Progress {
    done: number;
    total: number;
}

/** Fetches the export a page at a time and saves the file. */
export function useExport() {
    const [progress, setProgress] = useState<Progress | null>(null);

    const mutation = useMutation({
        mutationFn: async (meta: boolean) => {
            const first = await api.get<ExportPage>("admin/products/csv/export", { page: 1, meta: meta ? 1 : 0 });
            const parts = ["﻿", first.header ?? "", first.lines];
            let rows = first.rows;
            setProgress({ done: 1, total: first.pages });
            const layout = JSON.stringify(first.layout);
            for (let page = 2; page <= first.pages; page++) {
                const next = await api.get<ExportPage>("admin/products/csv/export", { page, layout });
                parts.push(next.lines);
                rows += next.rows;
                setProgress({ done: page, total: first.pages });
            }
            download(first.name ?? "products.csv", parts);
            return { rows, products: first.total };
        },
        onSettled: () => setProgress(null),
    });

    return { ...mutation, progress };
}

export function useColumnMap() {
    return useMutation({
        mutationFn: (headers: string[]) => api.post<MapResponse>("admin/products/csv/map", { headers }),
    });
}

/**
 * Splits rows into requests of about BATCH rows. A batch grows while the
 * next row is a variation, so a variable product and its variations are
 * saved together.
 */
export function batches(rows: CsvRow[], mapping: string[]): CsvRow[][] {
    const type = mapping.indexOf("type");
    const parent = mapping.indexOf("parent_id");
    const isVariation = (row: CsvRow) =>
        (type >= 0 && (row.cells[type] ?? "").toLowerCase().includes("variation")) || (parent >= 0 && (row.cells[parent] ?? "").trim() !== "");

    const out: CsvRow[][] = [];
    let batch: CsvRow[] = [];
    for (const row of rows) {
        if (batch.length >= MAX_BATCH || (batch.length >= BATCH && !isVariation(row))) {
            out.push(batch);
            batch = [];
        }
        batch.push(row);
    }
    if (batch.length > 0) {
        out.push(batch);
    }
    return out;
}

export interface ImportRun {
    headers: string[];
    mapping: string[];
    rows: CsvRow[];
    update: boolean;
    dryRun: boolean;
}

/** crypto.randomUUID needs a secure context; an admin on plain http has only getRandomValues. */
function sessionId(): string {
    return Array.from(crypto.getRandomValues(new Uint8Array(16)), (byte) => byte.toString(16).padStart(2, "0")).join("");
}

/** Sends the rows batch by batch; `cancel` stops before the next batch. */
export function useImportRun() {
    const [progress, setProgress] = useState<Progress | null>(null);
    const [running, setRunning] = useState(false);
    const cancelled = useRef(false);

    useEffect(
        () => () => {
            cancelled.current = true;
        },
        [],
    );

    const run = useCallback(async ({ headers, mapping, rows, update, dryRun }: ImportRun): Promise<{ results: ImportResult[]; summary: ImportSummary; stopped: boolean }> => {
        const parts = batches(rows, mapping);
        const session = sessionId();
        const results: ImportResult[] = [];
        cancelled.current = false;
        setRunning(true);
        setProgress({ done: 0, total: rows.length });
        let sent = 0;
        try {
            for (const part of parts) {
                if (cancelled.current) {
                    break;
                }
                const response = await api.post<{ results: ImportResult[] }>("admin/products/csv/import", {
                    session,
                    headers,
                    mapping,
                    rows: part.map((row) => ({ line: row.line, cells: row.cells })),
                    update_existing: update,
                    dry_run: dryRun,
                });
                results.push(...response.results);
                sent += part.length;
                setProgress({ done: sent, total: rows.length });
            }
        } finally {
            setRunning(false);
            setProgress(null);
        }
        return { results, summary: summarize(results), stopped: sent < rows.length };
    }, []);

    const cancel = useCallback(() => {
        cancelled.current = true;
    }, []);

    return { run, cancel, running, progress };
}

export function summarize(results: ImportResult[]): ImportSummary {
    const summary: ImportSummary = { created: 0, updated: 0, skipped: 0, failed: 0 };
    for (const result of results) {
        if (result.action === "create") {
            summary.created++;
        } else if (result.action === "update") {
            summary.updated++;
        } else if (result.action === "skip") {
            summary.skipped++;
        } else {
            summary.failed++;
        }
    }
    return summary;
}
