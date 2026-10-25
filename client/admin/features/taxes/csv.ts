/**
 * RFC 4180 CSV for the tax rate import. The browser parses the file so the
 * server only ever sees batches of rows (Api\Admin\TaxesEndpoint::import).
 */
export function parseCsv(text: string): string[][] {
    const rows: string[][] = [];
    let row: string[] = [];
    let cell = "";
    let quoted = false;
    const src = text.replace(/^﻿/, "");

    for (let i = 0; i < src.length; i++) {
        const ch = src[i];
        if (quoted) {
            if (ch === '"') {
                if (src[i + 1] === '"') {
                    cell += '"';
                    i++;
                } else {
                    quoted = false;
                }
            } else {
                cell += ch;
            }
            continue;
        }
        if (ch === '"' && cell === "") {
            quoted = true;
        } else if (ch === ",") {
            row.push(cell);
            cell = "";
        } else if (ch === "\n" || ch === "\r") {
            if (ch === "\r" && src[i + 1] === "\n") {
                i++;
            }
            row.push(cell);
            rows.push(row);
            row = [];
            cell = "";
        } else {
            cell += ch;
        }
    }
    if (cell !== "" || row.length > 0) {
        row.push(cell);
        rows.push(row);
    }

    return rows
        .filter((r) => r.some((c) => c.trim() !== ""))
        .map((r) => r.map(unguard));
}

/** The export prefixes `'` to cells that a spreadsheet would run as a formula. */
function unguard(cell: string): string {
    return cell.length > 1 && cell[0] === "'" && "=+-@".includes(cell[1]) ? cell.slice(1) : cell;
}

/** Whether the first row is the column header (ours or WooCommerce's, same order). */
export function isHeader(row: string[] | undefined): boolean {
    return row !== undefined && row[0]?.trim().toLowerCase() === "country code";
}

export function download(filename: string, csv: string): void {
    const url = URL.createObjectURL(new Blob([csv], { type: "text/csv;charset=utf-8" }));
    const link = document.createElement("a");
    link.href = url;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
}
