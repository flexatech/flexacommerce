import { Badge } from "@/shared/components/ui/badge";
import { Button } from "@/shared/components/ui/button";
import { __, sprintf } from "@/shared/lib/i18n";
import { download } from "./csv";
import type { ImportAction, ImportResult } from "./types";

/** At most this many rows are listed; the report file has them all. */
const SHOWN = 100;

const TONES: Record<ImportAction, "success" | "brand" | "muted" | "destructive"> = {
    create: "success",
    update: "brand",
    skip: "muted",
    error: "destructive",
};

const LABELS: Record<ImportAction, string> = {
    create: __("New"),
    update: __("Updated"),
    skip: __("Skipped"),
    error: __("Error"),
};

/** Rows that need a look: errors, skips and rows with warnings. */
export function problems(results: ImportResult[]): ImportResult[] {
    return results.filter((r) => r.action === "error" || r.action === "skip" || r.warnings.length > 0);
}

function quote(cell: string): string {
    return /[",\r\n]/.test(cell) ? `"${cell.replace(/"/g, '""')}"` : cell;
}

function saveReport(rows: ImportResult[]) {
    const lines = rows.map((r) => [String(r.line), LABELS[r.action], r.sku ?? "", r.name, [r.message, ...r.warnings].filter(Boolean).join(" ")].map(quote).join(","));
    download("product-import-report.csv", ["﻿", ["Line", "Result", "SKU", "Name", "Message"].join(",") + "\n", lines.join("\n") + "\n"]);
}

export function ResultList({ results }: { results: ImportResult[] }) {
    const rows = problems(results);
    if (rows.length === 0) {
        return null;
    }
    return (
        <div className="fxc:space-y-2">
            <div className="fxc:flex fxc:items-center fxc:justify-between fxc:gap-3">
                <p className="fxc:text-sm fxc:font-medium">{sprintf(__("%d rows to look at"), rows.length)}</p>
                <Button variant="outline" size="sm" onClick={() => saveReport(rows)}>
                    {__("Download report")}
                </Button>
            </div>
            <div className="fxc:max-h-96 fxc:overflow-y-auto fxc:rounded-md fxc:border">
                <table className="fxc:w-full fxc:text-sm">
                    <thead className="fxc:bg-muted fxc:sticky fxc:top-0">
                        <tr className="fxc:text-left">
                            <th className="fxc:px-3 fxc:py-2 fxc:font-medium">{__("Line")}</th>
                            <th className="fxc:px-3 fxc:py-2 fxc:font-medium">{__("Product")}</th>
                            <th className="fxc:px-3 fxc:py-2 fxc:font-medium">{__("Result")}</th>
                            <th className="fxc:px-3 fxc:py-2 fxc:font-medium">{__("Message")}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.slice(0, SHOWN).map((r) => (
                            <tr key={r.line} className="fxc:border-t fxc:align-top">
                                <td className="fxc:px-3 fxc:py-2 fxc:tabular-nums">{r.line}</td>
                                <td className="fxc:px-3 fxc:py-2">
                                    <span className="fxc:block">{r.name || "-"}</span>
                                    {r.sku && <span className="fxc:text-muted-foreground fxc:text-xs">{r.sku}</span>}
                                </td>
                                <td className="fxc:px-3 fxc:py-2">
                                    <Badge variant={TONES[r.action]}>{LABELS[r.action]}</Badge>
                                </td>
                                <td className="fxc:px-3 fxc:py-2">
                                    {r.message && <span className="fxc:block">{r.message}</span>}
                                    {r.warnings.map((w) => (
                                        <span key={w} className="fxc:text-muted-foreground fxc:block fxc:text-xs">
                                            {w}
                                        </span>
                                    ))}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            {rows.length > SHOWN && <p className="fxc:text-muted-foreground fxc:text-xs">{sprintf(__("The first %d are shown; the report has them all."), SHOWN)}</p>}
        </div>
    );
}
