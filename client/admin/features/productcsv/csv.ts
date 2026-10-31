/**
 * Reading and writing the product CSV in the browser (ADR-059). The server
 * only sees rows a batch at a time.
 */

export interface CsvRow {
    /** File line the row starts on, counting the header as line 1. */
    line: number;
    cells: string[];
}

/** UTF-8 when the bytes are valid UTF-8, else Windows-1252 (a file saved by Excel). */
export function decode(buffer: ArrayBuffer): string {
    let text: string;
    try {
        text = new TextDecoder("utf-8", { fatal: true }).decode(buffer);
    } catch {
        text = new TextDecoder("windows-1252").decode(buffer);
    }
    return text.replace(/^﻿/, "");
}

/** Comma, or semicolon when the header line has more of them (a European spreadsheet). */
export function delimiterOf(text: string): "," | ";" {
    let commas = 0;
    let semicolons = 0;
    let quoted = false;
    for (const ch of text) {
        if (ch === '"') {
            quoted = !quoted;
        } else if (!quoted && (ch === "\n" || ch === "\r")) {
            break;
        } else if (!quoted && ch === ",") {
            commas++;
        } else if (!quoted && ch === ";") {
            semicolons++;
        }
    }
    return semicolons > commas ? ";" : ",";
}

/** RFC 4180; rows with only empty cells are dropped. */
export function parseCsv(text: string, delimiter: "," | ";"): CsvRow[] {
    const rows: CsvRow[] = [];
    let row: string[] = [];
    let cell = "";
    let quoted = false;
    let line = 1;
    let start = 1;

    const end = () => {
        row.push(cell);
        if (row.some((c) => c.trim() !== "")) {
            rows.push({ line: start, cells: row });
        }
        row = [];
        cell = "";
    };

    for (let i = 0; i < text.length; i++) {
        const ch = text[i];
        if (quoted) {
            if (ch === '"') {
                if (text[i + 1] === '"') {
                    cell += '"';
                    i++;
                } else {
                    quoted = false;
                }
            } else {
                if (ch === "\n") {
                    line++;
                }
                cell += ch;
            }
            continue;
        }
        if (ch === '"' && cell === "") {
            quoted = true;
        } else if (ch === delimiter) {
            row.push(cell);
            cell = "";
        } else if (ch === "\n" || ch === "\r") {
            if (ch === "\r" && text[i + 1] === "\n") {
                i++;
            }
            end();
            line++;
            start = line;
        } else {
            cell += ch;
        }
    }
    if (cell !== "" || row.length > 0) {
        end();
    }
    return rows;
}

export function download(filename: string, parts: string[]): void {
    const url = URL.createObjectURL(new Blob(parts, { type: "text/csv;charset=utf-8" }));
    const link = document.createElement("a");
    link.href = url;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
}
