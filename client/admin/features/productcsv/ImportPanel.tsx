import { Upload } from "lucide-react";
import { useState } from "react";

import { showToast } from "@/shared/components/showToast";
import { Button } from "@/shared/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/shared/components/ui/card";
import { Label } from "@/shared/components/ui/label";
import { Select, type SelectOption } from "@/shared/components/ui/select";
import { Switch } from "@/shared/components/ui/switch";
import { __, sprintf } from "@/shared/lib/i18n";
import { decode, delimiterOf, parseCsv, type CsvRow } from "./csv";
import { ResultList } from "./ResultList";
import type { ImportResult, ImportSummary, MapResponse } from "./types";
import { productsUrl, useColumnMap, useImportRun } from "./useProductCsv";

type Step = "file" | "map" | "check" | "done";

interface Parsed {
    name: string;
    headers: string[];
    rows: CsvRow[];
}

interface Outcome {
    results: ImportResult[];
    summary: ImportSummary;
    stopped: boolean;
}

export function ImportPanel() {
    const [step, setStep] = useState<Step>("file");
    const [file, setFile] = useState<Parsed | null>(null);
    const [update, setUpdate] = useState(false);
    const [map, setMap] = useState<MapResponse | null>(null);
    const [mapping, setMapping] = useState<string[]>([]);
    const [check, setCheck] = useState<Outcome | null>(null);
    const [done, setDone] = useState<Outcome | null>(null);
    const columnMap = useColumnMap();
    const runner = useImportRun();

    const read = async (picked: File | undefined) => {
        setFile(null);
        if (!picked) {
            return;
        }
        const text = decode(await picked.arrayBuffer());
        const [header, ...rows] = parseCsv(text, delimiterOf(text));
        if (!header || rows.length === 0) {
            showToast.error(__("The file has no product rows."));
            return;
        }
        setFile({ name: picked.name, headers: header.cells.map((h) => h.trim()), rows });
    };

    const toMapping = () => {
        if (!file) {
            return;
        }
        columnMap.mutate(file.headers, {
            onSuccess: (response) => {
                setMap(response);
                setMapping(response.columns.map((c) => (c.key.startsWith("unsupported:") ? "" : c.key)));
                setStep("map");
            },
            onError: (e) => showToast.error(e.message),
        });
    };

    const send = async (dryRun: boolean) => {
        if (!file) {
            return;
        }
        try {
            const outcome = await runner.run({ headers: file.headers, mapping, rows: file.rows, update, dryRun });
            if (dryRun) {
                setCheck(outcome);
                setStep("check");
            } else {
                setDone(outcome);
                setStep("done");
            }
        } catch (e) {
            showToast.error(e instanceof Error ? e.message : String(e));
        }
    };

    const restart = () => {
        setStep("file");
        setFile(null);
        setMap(null);
        setCheck(null);
        setDone(null);
    };

    const progress = runner.progress && (
        <div className="fxc:flex fxc:items-center fxc:gap-3 fxc:text-sm">
            <span>{sprintf(__("%1$d of %2$d rows"), runner.progress.done, runner.progress.total)}</span>
            <Button variant="ghost" size="sm" onClick={runner.cancel}>
                {__("Stop")}
            </Button>
        </div>
    );

    if (step === "file") {
        return (
            <Card>
                <CardHeader>
                    <CardTitle>{__("Choose a CSV file")}</CardTitle>
                    <CardDescription>{__("Commas or semicolons, UTF-8 or a file saved by Excel. Grouped and external products are not imported.")}</CardDescription>
                </CardHeader>
                <CardContent className="fxc:space-y-4">
                    <div>
                        <Label htmlFor="fxc-product-csv" className="fxc:mb-2 fxc:block">
                            {__("CSV file")}
                        </Label>
                        <input id="fxc-product-csv" type="file" accept=".csv,text/csv" onChange={(e) => void read(e.target.files?.[0])} />
                        {file && <p className="fxc:text-muted-foreground fxc:mt-1 fxc:text-xs">{sprintf(__("%1$s: %2$d rows"), file.name, file.rows.length)}</p>}
                    </div>
                    <div className="fxc:flex fxc:items-start fxc:gap-3">
                        <Switch id="fxc-csv-update" checked={update} onCheckedChange={setUpdate} className="fxc:mt-0.5" />
                        <div>
                            <Label htmlFor="fxc-csv-update">{__("Update existing products")}</Label>
                            <p className="fxc:text-muted-foreground fxc:text-xs">
                                {__("A row whose SKU or ID matches a product changes that product. When off, such rows are skipped. An empty cell clears the field; an empty Categories, Tags, Images, attribute or download cell keeps what the product has.")}
                            </p>
                        </div>
                    </div>
                    <Button onClick={toMapping} disabled={!file || columnMap.isPending}>
                        {columnMap.isPending ? __("Reading…") : __("Continue")}
                    </Button>
                </CardContent>
            </Card>
        );
    }

    if (step === "map" && map && file) {
        const base: SelectOption[] = [{ value: "", label: __("Do not import") }, ...map.fields.map((f) => ({ value: f.key, label: f.label }))];
        return (
            <Card>
                <CardHeader>
                    <CardTitle>{__("Match the columns")}</CardTitle>
                    <CardDescription>{__("Each column of the file goes into the field shown. Columns from a WooCommerce export are matched already.")}</CardDescription>
                </CardHeader>
                <CardContent className="fxc:space-y-4">
                    <div className="fxc:max-h-[32rem] fxc:overflow-y-auto fxc:rounded-md fxc:border">
                        <table className="fxc:w-full fxc:text-sm">
                            <thead className="fxc:bg-muted fxc:sticky fxc:top-0 fxc:z-10">
                                <tr className="fxc:text-left">
                                    <th className="fxc:px-3 fxc:py-2 fxc:font-medium">{__("Column")}</th>
                                    <th className="fxc:px-3 fxc:py-2 fxc:font-medium">{__("First row")}</th>
                                    <th className="fxc:px-3 fxc:py-2 fxc:font-medium">{__("Import as")}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {map.columns.map((column, i) => {
                                    const unsupported = column.key.startsWith("unsupported:");
                                    const options =
                                        column.key !== "" && !unsupported && !base.some((o) => o.value === column.key) ? [...base, { value: column.key, label: column.label }] : base;
                                    return (
                                        <tr key={`${i}-${column.header}`} className="fxc:border-t fxc:align-top">
                                            <td className="fxc:px-3 fxc:py-2 fxc:font-medium">{column.header || sprintf(__("Column %d"), i + 1)}</td>
                                            <td className="fxc:text-muted-foreground fxc:max-w-56 fxc:truncate fxc:px-3 fxc:py-2">{file.rows[0]?.cells[i] ?? ""}</td>
                                            <td className="fxc:px-3 fxc:py-2">
                                                <Select
                                                    aria-label={sprintf(__("Import %s as"), column.header)}
                                                    className="fxc:w-full"
                                                    options={options}
                                                    value={mapping[i] ?? ""}
                                                    onChange={(e) => setMapping((m) => m.map((key, j) => (j === i ? e.target.value : key)))}
                                                />
                                                {unsupported && <p className="fxc:text-muted-foreground fxc:mt-1 fxc:text-xs">{column.label}</p>}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                    <div className="fxc:flex fxc:flex-wrap fxc:items-center fxc:gap-3">
                        <Button variant="ghost" onClick={() => setStep("file")} disabled={runner.running}>
                            {__("Back")}
                        </Button>
                        <Button onClick={() => void send(true)} disabled={runner.running || mapping.every((key) => key === "")}>
                            {runner.running ? __("Checking…") : __("Check the file")}
                        </Button>
                        {progress}
                    </div>
                    <p className="fxc:text-muted-foreground fxc:text-xs">{__("The check reads every row and reports problems without changing the store.")}</p>
                </CardContent>
            </Card>
        );
    }

    if (step === "check" && check) {
        const s = check.summary;
        return (
            <Card>
                <CardHeader>
                    <CardTitle>{__("Check result")}</CardTitle>
                    <CardDescription>
                        {sprintf(__("New: %1$d. Updated: %2$d. Skipped: %3$d. With errors: %4$d."), s.created, s.updated, s.skipped, s.failed)}
                    </CardDescription>
                </CardHeader>
                <CardContent className="fxc:space-y-4">
                    {check.stopped && <p className="fxc:text-sm">{__("The check was stopped before the end of the file.")}</p>}
                    <ResultList results={check.results} />
                    {s.failed > 0 && <p className="fxc:text-sm">{__("Rows with errors are left out of the import; the other rows are imported.")}</p>}
                    <div className="fxc:flex fxc:flex-wrap fxc:items-center fxc:gap-3">
                        <Button variant="ghost" onClick={() => setStep("map")} disabled={runner.running}>
                            {__("Back")}
                        </Button>
                        <Button onClick={() => void send(false)} disabled={runner.running || s.created + s.updated === 0}>
                            <Upload aria-hidden className="fxc:size-4" />
                            {runner.running ? __("Importing…") : __("Import products")}
                        </Button>
                        {progress}
                    </div>
                </CardContent>
            </Card>
        );
    }

    if (step === "done" && done) {
        const s = done.summary;
        return (
            <Card>
                <CardHeader>
                    <CardTitle>{done.stopped ? __("Import stopped") : __("Import finished")}</CardTitle>
                    <CardDescription>
                        {sprintf(__("New: %1$d. Updated: %2$d. Skipped: %3$d. With errors: %4$d."), s.created, s.updated, s.skipped, s.failed)}
                    </CardDescription>
                </CardHeader>
                <CardContent className="fxc:space-y-4">
                    {done.stopped && <p className="fxc:text-sm">{__("Rows before the stop were saved; the rest of the file was not read.")}</p>}
                    <ResultList results={done.results} />
                    <div className="fxc:flex fxc:flex-wrap fxc:items-center fxc:gap-3">
                        <Button asChild>
                            <a href={productsUrl()}>{__("View products")}</a>
                        </Button>
                        <Button variant="outline" onClick={restart}>
                            {__("Import another file")}
                        </Button>
                    </div>
                </CardContent>
            </Card>
        );
    }

    return null;
}
