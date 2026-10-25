import { useEffect, useMemo, useState } from "react";
import { Download, Plus, Search, Sparkles, Trash2, Undo2, Upload } from "lucide-react";

import { showToast } from "@/shared/components/showToast";
import { Badge } from "@/shared/components/ui/badge";
import { Button } from "@/shared/components/ui/button";
import { Card, CardDescription, CardHeader, CardTitle } from "@/shared/components/ui/card";
import { Input } from "@/shared/components/ui/input";
import { ApiError } from "@/shared/lib/api";
import { cn } from "@/shared/lib/cn";
import { __, sprintf } from "@/shared/lib/i18n";
import { useDebounced } from "@/shared/lib/useDebounced";
import { Tabs } from "../../components/Tabs";
import { download } from "./csv";
import { ImportDialog } from "./ImportDialog";
import { PresetDialog } from "./PresetDialog";
import { exportRates, PER_PAGE, useSaveRates, useTaxRates, type TaxOverview, type TaxRateRow } from "./useTaxes";

/** A row in the editor. Postcodes and cities are edited as `;`-separated text, like the CSV. */
interface DraftRate {
    key: string;
    id: number;
    country: string;
    state: string;
    postcodes: string;
    cities: string;
    rate: string;
    name: string;
    priority: string;
    compound: boolean;
    shipping: boolean;
    rate_order: number;
    source: string;
}

let nextKey = 0;

function toDraft(row: TaxRateRow): DraftRate {
    return {
        key: `r${row.id}`,
        id: row.id,
        country: row.country,
        state: row.state,
        postcodes: row.postcodes.join("; "),
        cities: row.cities.join("; "),
        rate: row.rate,
        name: row.name,
        priority: String(row.priority),
        compound: row.compound,
        shipping: row.shipping,
        rate_order: row.rate_order,
        source: row.source,
    };
}

function blank(order: number): DraftRate {
    return {
        key: `n${++nextKey}`,
        id: 0,
        country: "",
        state: "",
        postcodes: "",
        cities: "",
        rate: "",
        name: "",
        priority: "1",
        compound: false,
        shipping: true,
        rate_order: order,
        source: "manual",
    };
}

function toInput(row: DraftRate, taxClass: string): Record<string, unknown> {
    return {
        id: row.id,
        tax_class: taxClass,
        country: row.country,
        state: row.state,
        postcodes: row.postcodes,
        cities: row.cities,
        rate: row.rate,
        name: row.name,
        priority: Number.parseInt(row.priority, 10) || 0,
        compound: row.compound,
        shipping: row.shipping,
        rate_order: row.rate_order,
    };
}

function sourceLabel(source: string): string {
    if (source.startsWith("preset:")) {
        return sprintf(__("Preset %s"), source.slice(7).toUpperCase());
    }
    return source === "import" ? __("Imported") : __("Manual");
}

export function RatesCard({ overview }: { overview: TaxOverview }) {
    const [taxClass, setTaxClass] = useState("");
    const [search, setSearch] = useState("");
    const [page, setPage] = useState(1);
    const debounced = useDebounced(search.trim());
    const query = useTaxRates(taxClass, debounced, page);
    const save = useSaveRates();

    const [edits, setEdits] = useState<Record<string, DraftRate>>({});
    const [added, setAdded] = useState<DraftRate[]>([]);
    const [deleted, setDeleted] = useState<Set<number>>(new Set());
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [dialog, setDialog] = useState<"import" | "preset" | null>(null);

    const dirty = Object.keys(edits).length > 0 || added.length > 0 || deleted.size > 0;

    const reset = () => {
        setEdits({});
        setAdded([]);
        setDeleted(new Set());
        setErrors({});
    };

    // A new search starts at the first page.
    useEffect(() => setPage(1), [debounced]);

    const rows = useMemo(() => (query.data?.rows ?? []).map((r) => edits[`r${r.id}`] ?? toDraft(r)), [query.data, edits]);

    const leave = (go: () => void) => {
        if (dirty && !window.confirm(__("Discard the unsaved rate changes?"))) {
            return;
        }
        reset();
        go();
    };

    const change = (row: DraftRate, patch: Partial<DraftRate>) => {
        const next = { ...row, ...patch };
        if (row.id === 0) {
            setAdded(added.map((r) => (r.key === row.key ? next : r)));
        } else {
            setEdits({ ...edits, [row.key]: next });
        }
    };

    const toggleDelete = (row: DraftRate) => {
        if (row.id === 0) {
            setAdded(added.filter((r) => r.key !== row.key));
            return;
        }
        const next = new Set(deleted);
        if (next.has(row.id)) {
            next.delete(row.id);
        } else {
            next.add(row.id);
        }
        setDeleted(next);
    };

    const submit = () => {
        const sent = [...Object.values(edits).filter((r) => !deleted.has(r.id)), ...added];
        setErrors({});
        save.mutate(
            { rows: sent.map((r) => toInput(r, taxClass)), deleted: [...deleted] },
            {
                onSuccess: () => {
                    showToast.success(__("Tax rates saved."));
                    reset();
                },
                onError: (e) => {
                    const list = e instanceof ApiError && Array.isArray(e.data.errors) ? (e.data.errors as { index: number; message: string }[]) : [];
                    setErrors(Object.fromEntries(list.filter((x) => sent[x.index]).map((x) => [sent[x.index].key, x.message])));
                    showToast.error(e.message);
                },
            },
        );
    };

    const exportCsv = async (all: boolean) => {
        try {
            const file = await exportRates(all ? null : taxClass);
            download(file.filename, file.csv);
        } catch (e) {
            showToast.error(e instanceof Error ? e.message : String(e));
        }
    };

    const total = query.data?.total ?? 0;
    const pages = Math.max(1, Math.ceil(total / PER_PAGE));
    const className = overview.classes.find((c) => c.slug === taxClass)?.name ?? __("Standard");

    return (
        <Card>
            <CardHeader>
                <CardTitle>{__("Tax rates")}</CardTitle>
                <CardDescription>
                    {__(
                        "For each priority the customer pays the one rate that matches the address best, so use priorities 1, 2, 3 to stack a state and a local rate. An empty country, state, postcode or city matches all. Postcodes take plain codes, 902* and 90210...90299, separated by semicolons.",
                    )}
                </CardDescription>
            </CardHeader>

            <div className="fxc:px-5">
                <Tabs
                    value={taxClass}
                    onChange={(next) =>
                        leave(() => {
                            setTaxClass(next);
                            setPage(1);
                        })
                    }
                    items={overview.classes.map((c) => ({ value: c.slug, label: `${c.name} (${c.rates})` }))}
                />
            </div>

            <div className="fxc:flex fxc:flex-wrap fxc:items-center fxc:gap-2 fxc:px-5 fxc:py-3">
                <div className="fxc:relative fxc:w-64">
                    <Search aria-hidden className="fxc:text-muted-foreground fxc:pointer-events-none fxc:absolute fxc:top-1/2 fxc:left-3 fxc:size-4 fxc:-translate-y-1/2" />
                    <Input
                        aria-label={__("Search rates")}
                        placeholder={__("Country, state, postcode or name")}
                        className="fxc:w-full fxc:pl-9"
                        value={search}
                        onChange={(e) => {
                            if (!dirty || window.confirm(__("Discard the unsaved rate changes?"))) {
                                reset();
                                setSearch(e.target.value);
                            }
                        }}
                    />
                </div>
                <div className="fxc:ml-auto fxc:flex fxc:flex-wrap fxc:items-center fxc:gap-2">
                    <Button variant="outline" size="sm" onClick={() => leave(() => setDialog("preset"))} disabled={overview.presets.length === 0}>
                        <Sparkles aria-hidden />
                        {__("Apply a preset")}
                    </Button>
                    <Button variant="outline" size="sm" onClick={() => leave(() => setDialog("import"))}>
                        <Upload aria-hidden />
                        {__("Import CSV")}
                    </Button>
                    <Button variant="outline" size="sm" onClick={() => void exportCsv(false)}>
                        <Download aria-hidden />
                        {sprintf(__("Export %s"), className)}
                    </Button>
                    <Button variant="ghost" size="sm" onClick={() => void exportCsv(true)}>
                        {__("Export all classes")}
                    </Button>
                </div>
            </div>

            <div className="fxc:overflow-x-auto fxc:border-t">
                <table className="fxc:w-full fxc:text-sm">
                    <thead>
                        <tr className="fxc:text-muted-foreground fxc:border-b fxc:text-left fxc:text-xs">
                            <th className="fxc:px-3 fxc:py-2 fxc:pl-5 fxc:font-medium">{__("Country")}</th>
                            <th className="fxc:px-2 fxc:py-2 fxc:font-medium">{__("State")}</th>
                            <th className="fxc:px-2 fxc:py-2 fxc:font-medium">{__("Postcodes")}</th>
                            <th className="fxc:px-2 fxc:py-2 fxc:font-medium">{__("Cities")}</th>
                            <th className="fxc:px-2 fxc:py-2 fxc:font-medium">{__("Rate %")}</th>
                            <th className="fxc:px-2 fxc:py-2 fxc:font-medium">{__("Name")}</th>
                            <th className="fxc:px-2 fxc:py-2 fxc:font-medium">{__("Priority")}</th>
                            <th className="fxc:px-2 fxc:py-2 fxc:font-medium">{__("Compound")}</th>
                            <th className="fxc:px-2 fxc:py-2 fxc:font-medium">{__("Shipping")}</th>
                            <th className="fxc:px-2 fxc:py-2 fxc:font-medium">{__("Source")}</th>
                            <th className="fxc:px-2 fxc:py-2 fxc:pr-5">
                                <span className="fxc:sr-only">{__("Actions")}</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {[...rows, ...added].map((row) => (
                            <RateLine
                                key={row.key}
                                row={row}
                                removed={deleted.has(row.id)}
                                error={errors[row.key]}
                                onChange={(patch) => change(row, patch)}
                                onRemove={() => toggleDelete(row)}
                            />
                        ))}
                    </tbody>
                </table>
                {query.isPending ? (
                    <p className="fxc:text-muted-foreground fxc:px-5 fxc:py-6 fxc:text-center">{__("Loading rates…")}</p>
                ) : query.isError ? (
                    <p className="fxc:text-destructive fxc:px-5 fxc:py-4">{query.error.message}</p>
                ) : (
                    rows.length === 0 &&
                    added.length === 0 && (
                        <p className="fxc:text-muted-foreground fxc:px-5 fxc:py-6 fxc:text-center">
                            {debounced !== "" ? __("No rates match.") : __("No rates in this class yet. Add one, import a CSV, or apply a preset.")}
                        </p>
                    )
                )}
            </div>

            <div className="fxc:flex fxc:flex-wrap fxc:items-center fxc:gap-3 fxc:border-t fxc:px-5 fxc:py-3">
                <Button variant="outline" size="sm" onClick={() => setAdded([...added, blank(total + added.length)])}>
                    <Plus aria-hidden />
                    {__("Add rate")}
                </Button>
                {pages > 1 && (
                    <div className="fxc:flex fxc:items-center fxc:gap-2 fxc:text-sm">
                        <span className="fxc:text-muted-foreground">{sprintf(__("Page %1$d of %2$d"), page, pages)}</span>
                        <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => leave(() => setPage(page - 1))}>
                            {__("Previous")}
                        </Button>
                        <Button variant="outline" size="sm" disabled={page >= pages} onClick={() => leave(() => setPage(page + 1))}>
                            {__("Next")}
                        </Button>
                    </div>
                )}
                <div className="fxc:ml-auto fxc:flex fxc:items-center fxc:gap-2">
                    {dirty && (
                        <Button variant="ghost" onClick={reset} disabled={save.isPending}>
                            {__("Discard")}
                        </Button>
                    )}
                    <Button onClick={submit} disabled={!dirty || save.isPending}>
                        {save.isPending ? __("Saving…") : __("Save rates")}
                    </Button>
                </div>
            </div>

            {dialog === "import" && <ImportDialog overview={overview} taxClass={taxClass} onClose={() => setDialog(null)} />}
            {dialog === "preset" && <PresetDialog overview={overview} onClose={() => setDialog(null)} />}
        </Card>
    );
}

const CELL = "fxc:h-8 fxc:px-2 fxc:text-xs";

function RateLine({
    row,
    removed,
    error,
    onChange,
    onRemove,
}: {
    row: DraftRate;
    removed: boolean;
    error?: string;
    onChange: (patch: Partial<DraftRate>) => void;
    onRemove: () => void;
}) {
    const label = (text: string) => `${text} ${row.name || row.country || ""}`.trim();
    return (
        <>
            <tr className={cn("fxc:border-b", removed && "fxc:opacity-50 fxc:line-through", error && "fxc:bg-destructive/5")}>
                <td className="fxc:px-2 fxc:py-1.5 fxc:pl-5">
                    <Input
                        aria-label={label(__("Country"))}
                        className={cn(CELL, "fxc:w-14 fxc:uppercase")}
                        placeholder="*"
                        maxLength={2}
                        disabled={removed}
                        value={row.country}
                        onChange={(e) => onChange({ country: e.target.value.toUpperCase() })}
                    />
                </td>
                <td className="fxc:px-2 fxc:py-1.5">
                    <Input
                        aria-label={label(__("State"))}
                        className={cn(CELL, "fxc:w-16 fxc:uppercase")}
                        placeholder="*"
                        maxLength={20}
                        disabled={removed}
                        value={row.state}
                        onChange={(e) => onChange({ state: e.target.value.toUpperCase() })}
                    />
                </td>
                <td className="fxc:px-2 fxc:py-1.5">
                    <Input
                        aria-label={label(__("Postcodes"))}
                        className={cn(CELL, "fxc:w-36")}
                        placeholder="*"
                        disabled={removed}
                        value={row.postcodes}
                        onChange={(e) => onChange({ postcodes: e.target.value })}
                    />
                </td>
                <td className="fxc:px-2 fxc:py-1.5">
                    <Input
                        aria-label={label(__("Cities"))}
                        className={cn(CELL, "fxc:w-32")}
                        placeholder="*"
                        disabled={removed}
                        value={row.cities}
                        onChange={(e) => onChange({ cities: e.target.value })}
                    />
                </td>
                <td className="fxc:px-2 fxc:py-1.5">
                    <Input
                        aria-label={label(__("Rate %"))}
                        className={cn(CELL, "fxc:w-20 fxc:text-right fxc:tabular-nums")}
                        inputMode="decimal"
                        placeholder="0"
                        disabled={removed}
                        value={row.rate}
                        onChange={(e) => onChange({ rate: e.target.value })}
                    />
                </td>
                <td className="fxc:px-2 fxc:py-1.5">
                    <Input
                        aria-label={label(__("Name"))}
                        className={cn(CELL, "fxc:w-32")}
                        placeholder={__("Tax")}
                        maxLength={200}
                        disabled={removed}
                        value={row.name}
                        onChange={(e) => onChange({ name: e.target.value })}
                    />
                </td>
                <td className="fxc:px-2 fxc:py-1.5">
                    <Input
                        aria-label={label(__("Priority"))}
                        className={cn(CELL, "fxc:w-14 fxc:text-right")}
                        type="number"
                        min={1}
                        disabled={removed}
                        value={row.priority}
                        onChange={(e) => onChange({ priority: e.target.value })}
                    />
                </td>
                <td className="fxc:px-2 fxc:py-1.5 fxc:text-center">
                    <input
                        type="checkbox"
                        className="flexacommerce-check"
                        aria-label={label(__("Compound"))}
                        disabled={removed}
                        checked={row.compound}
                        onChange={(e) => onChange({ compound: e.target.checked })}
                    />
                </td>
                <td className="fxc:px-2 fxc:py-1.5 fxc:text-center">
                    <input
                        type="checkbox"
                        className="flexacommerce-check"
                        aria-label={label(__("Shipping"))}
                        disabled={removed}
                        checked={row.shipping}
                        onChange={(e) => onChange({ shipping: e.target.checked })}
                    />
                </td>
                <td className="fxc:px-2 fxc:py-1.5 fxc:whitespace-nowrap">
                    {row.id === 0 ? <Badge variant="brand">{__("New")}</Badge> : <Badge>{sourceLabel(row.source)}</Badge>}
                </td>
                <td className="fxc:px-2 fxc:py-1.5 fxc:pr-5">
                    <Button variant="ghost" size="icon" aria-label={removed ? __("Keep this rate") : __("Delete this rate")} onClick={onRemove}>
                        {removed ? <Undo2 aria-hidden /> : <Trash2 aria-hidden />}
                    </Button>
                </td>
            </tr>
            {error && (
                <tr className="fxc:bg-destructive/5 fxc:border-b">
                    <td colSpan={11} className="fxc:text-destructive fxc:px-5 fxc:py-1.5 fxc:text-xs">
                        {error}
                    </td>
                </tr>
            )}
        </>
    );
}
