import { useState } from "react";

import { showToast } from "@/shared/components/showToast";
import { Button } from "@/shared/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/shared/components/ui/dialog";
import { Label } from "@/shared/components/ui/label";
import { ApiError } from "@/shared/lib/api";
import { __, sprintf } from "@/shared/lib/i18n";
import { isHeader, parseCsv } from "./csv";
import { importBatch, useRefreshTaxes, type ImportResult, type TaxOverview } from "./useTaxes";

type LineError = ImportResult["errors"][number];

/** At most this many errors are listed; the count says how many there were. */
const SHOWN_ERRORS = 50;

/**
 * Reads the CSV in the browser and sends it in batches. The whole file is
 * checked first (validate_only), so a file with a bad row writes nothing.
 */
export function ImportDialog({ overview, taxClass, onClose }: { overview: TaxOverview; taxClass: string; onClose: () => void }) {
    const refresh = useRefreshTaxes();
    const [rows, setRows] = useState<string[][] | null>(null);
    const [fileName, setFileName] = useState("");
    const [mode, setMode] = useState<"add" | "replace">("add");
    const [errors, setErrors] = useState<LineError[]>([]);
    const [progress, setProgress] = useState<string>("");
    const [busy, setBusy] = useState(false);
    /** File line of the first data row: 2 after a header, else 1. */
    const [firstLine, setFirstLine] = useState(1);

    const className = overview.classes.find((c) => c.slug === taxClass)?.name ?? __("Standard");

    const read = async (file: File | undefined) => {
        setErrors([]);
        setProgress("");
        if (!file) {
            setRows(null);
            return;
        }
        const parsed = parseCsv(await file.text());
        setFileName(file.name);
        setFirstLine(isHeader(parsed[0]) ? 2 : 1);
        setRows(isHeader(parsed[0]) ? parsed.slice(1) : parsed);
    };

    const batches = (list: string[][]) => {
        const out: { offset: number; rows: string[][] }[] = [];
        for (let offset = 0; offset < list.length; offset += overview.batch) {
            out.push({ offset, rows: list.slice(offset, offset + overview.batch) });
        }
        return out;
    };

    const run = async () => {
        if (!rows || rows.length === 0) {
            return;
        }
        setBusy(true);
        setErrors([]);
        const parts = batches(rows);
        let writing = false;
        try {
            const found: LineError[] = [];
            for (const [i, part] of parts.entries()) {
                setProgress(sprintf(__("Checking rows: batch %1$d of %2$d"), i + 1, parts.length));
                try {
                    await importBatch({ rows: part.rows, mode, tax_class: taxClass, offset: part.offset, first_line: firstLine + part.offset, validate_only: true });
                } catch (e) {
                    if (e instanceof ApiError && Array.isArray(e.data.errors)) {
                        found.push(...(e.data.errors as LineError[]));
                    } else {
                        throw e;
                    }
                }
            }
            if (found.length > 0) {
                setErrors(found);
                setProgress("");
                return;
            }

            let written = 0;
            writing = true;
            for (const [i, part] of parts.entries()) {
                setProgress(sprintf(__("Importing: batch %1$d of %2$d"), i + 1, parts.length));
                const result = await importBatch({
                    rows: part.rows,
                    mode,
                    tax_class: taxClass,
                    offset: part.offset,
                    first_line: firstLine + part.offset,
                    start: i === 0,
                    last: i === parts.length - 1,
                });
                written += result.written;
            }
            showToast.success(sprintf(__("%d tax rates imported."), written));
            onClose();
        } catch (e) {
            showToast.error(e instanceof Error ? e.message : String(e));
            // A failure after the first written batch leaves part of the file in; say so.
            setProgress(writing ? __("The import stopped. Rows from the batches before the error were saved.") : "");
        } finally {
            setBusy(false);
            void refresh();
        }
    };

    return (
        <Dialog open onOpenChange={(open) => !open && !busy && onClose()}>
            <DialogContent className="fxc:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{__("Import tax rates")}</DialogTitle>
                    <DialogDescription>
                        {__("A CSV in WooCommerce's column order: Country code, State code, Postcode / ZIP, City, Rate %, Tax name, Priority, Compound, Shipping, Tax class. A file exported from WooCommerce works as is.")}
                    </DialogDescription>
                </DialogHeader>

                <div className="fxc:space-y-4">
                    <div>
                        <Label htmlFor="fxc-tax-csv" className="fxc:mb-2 fxc:block">
                            {__("CSV file")}
                        </Label>
                        <input id="fxc-tax-csv" type="file" accept=".csv,text/csv" disabled={busy} onChange={(e) => void read(e.target.files?.[0])} />
                        {rows && (
                            <p className="fxc:text-muted-foreground fxc:mt-1 fxc:text-xs">
                                {sprintf(__("%1$s: %2$d rows"), fileName, rows.length)}
                            </p>
                        )}
                    </div>

                    <fieldset className="fxc:space-y-2">
                        <legend className="fxc:mb-2 fxc:text-sm fxc:font-medium">{__("Existing rates")}</legend>
                        <label className="fxc:flex fxc:items-start fxc:gap-2 fxc:text-sm">
                            <input type="radio" className="flexacommerce-check fxc:mt-0.5" name="fxc-import-mode" checked={mode === "add"} disabled={busy} onChange={() => setMode("add")} />
                            <span>{__("Keep them and add the file's rates")}</span>
                        </label>
                        <label className="fxc:flex fxc:items-start fxc:gap-2 fxc:text-sm">
                            <input
                                type="radio"
                                className="flexacommerce-check fxc:mt-0.5"
                                name="fxc-import-mode"
                                checked={mode === "replace"}
                                disabled={busy}
                                onChange={() => setMode("replace")}
                            />
                            <span>
                                {sprintf(__("Replace every rate in %s with the file"), className)}
                                <span className="fxc:text-muted-foreground fxc:block fxc:text-xs">{__("Every row of the file must belong to this class.")}</span>
                            </span>
                        </label>
                    </fieldset>

                    {progress !== "" && <p className="fxc:text-sm">{progress}</p>}

                    {errors.length > 0 && (
                        <div className="fxc:border-destructive/40 fxc:bg-destructive/5 fxc:max-h-56 fxc:overflow-y-auto fxc:rounded-md fxc:border fxc:p-3">
                            <p className="fxc:text-destructive fxc:mb-2 fxc:text-sm fxc:font-medium">
                                {sprintf(__("Nothing was imported. %d rows need fixing:"), errors.length)}
                            </p>
                            <ul className="fxc:space-y-1 fxc:text-xs">
                                {errors.slice(0, SHOWN_ERRORS).map((e) => (
                                    <li key={`${e.line}-${e.message}`}>{sprintf(__("Line %1$d: %2$s"), e.line, e.message)}</li>
                                ))}
                            </ul>
                        </div>
                    )}
                </div>

                <DialogFooter>
                    <Button variant="ghost" onClick={onClose} disabled={busy}>
                        {__("Cancel")}
                    </Button>
                    <Button onClick={() => void run()} disabled={busy || !rows || rows.length === 0}>
                        {busy ? __("Working…") : __("Import")}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
