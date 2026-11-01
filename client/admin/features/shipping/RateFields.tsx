import { useEffect, useState } from "react";
import { ArrowDown, ArrowUp, Plus, Trash2 } from "lucide-react";

import { Button } from "@/shared/components/ui/button";
import { Input } from "@/shared/components/ui/input";
import { Label } from "@/shared/components/ui/label";
import { Select } from "@/shared/components/ui/select";
import { getPluginGlobal } from "@/shared/lib/api";
import { __, sprintf } from "@/shared/lib/i18n";
import { MoneyInput } from "../../components/MoneyInput";
import type { ShippingClass } from "./useShipping";

/** PHP RateTables::class_costs; '' is "no shipping class". */
export interface ClassCosts {
    calculation: "per_class" | "per_order";
    costs: Record<string, { cost: number; per_item: number }>;
}

export type Condition = "none" | "weight" | "items" | "subtotal";

/** One row of a table rate (PHP RateTables::rows). `class` is "*" for any class, "" for none. */
export interface RateRow {
    class: string;
    condition: Condition;
    min: number | null;
    max: number | null;
    cost: number;
    item_cost: number;
    weight_cost: number;
    percent: number;
    abort: boolean;
}

const exponent = () => getPluginGlobal().currency.exponent;

export function readClassCosts(value: unknown): ClassCosts {
    const v = (value ?? {}) as Partial<ClassCosts>;
    const costs = v.costs && !Array.isArray(v.costs) ? v.costs : {};
    return { calculation: v.calculation === "per_order" ? "per_order" : "per_class", costs };
}

export function ClassCostsField({ id, value, classes, onChange }: { id: string; value: unknown; classes: ShippingClass[]; onChange: (value: ClassCosts) => void }) {
    const current = readClassCosts(value);
    if (classes.length === 0) {
        return <p className="fxc:text-muted-foreground fxc:text-xs">{__("Add shipping classes below to charge more for some products.")}</p>;
    }
    const set = (slug: string, key: "cost" | "per_item", amount: number) => {
        const entry = { ...(current.costs[slug] ?? { cost: 0, per_item: 0 }), [key]: amount };
        onChange({ ...current, costs: { ...current.costs, [slug]: entry } });
    };
    const rows = [...classes.map((c) => ({ slug: c.slug, name: c.name })), { slug: "", name: __("No shipping class") }];

    return (
        <div className="fxc:space-y-3">
            <div className="fxc:overflow-x-auto fxc:rounded-md fxc:border">
                <table className="fxc:w-full fxc:text-sm">
                    <thead className="fxc:bg-muted/50 fxc:text-left fxc:text-xs">
                        <tr>
                            <th className="fxc:px-3 fxc:py-2 fxc:font-medium">{__("Class")}</th>
                            <th className="fxc:px-3 fxc:py-2 fxc:font-medium">{__("Cost")}</th>
                            <th className="fxc:px-3 fxc:py-2 fxc:font-medium">{__("Cost per item")}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((row) => (
                            <tr key={row.slug} className="fxc:border-t">
                                <td className="fxc:px-3 fxc:py-2">{row.name}</td>
                                <td className="fxc:px-3 fxc:py-2">
                                    <MoneyInput
                                        className="fxc:w-28"
                                        aria-label={sprintf(__("%s: cost"), row.name)}
                                        value={current.costs[row.slug]?.cost ?? 0}
                                        exponent={exponent()}
                                        onChange={(amount) => set(row.slug, "cost", amount)}
                                    />
                                </td>
                                <td className="fxc:px-3 fxc:py-2">
                                    <MoneyInput
                                        className="fxc:w-28"
                                        aria-label={sprintf(__("%s: cost per item"), row.name)}
                                        value={current.costs[row.slug]?.per_item ?? 0}
                                        exponent={exponent()}
                                        onChange={(amount) => set(row.slug, "per_item", amount)}
                                    />
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <div className="fxc:space-y-1.5">
                <Label htmlFor={`${id}-calculation`} className="fxc:block">
                    {__("With items of several classes")}
                </Label>
                <Select
                    id={`${id}-calculation`}
                    value={current.calculation}
                    onChange={(e) => onChange({ ...current, calculation: e.target.value === "per_order" ? "per_order" : "per_class" })}
                    options={[
                        { value: "per_class", label: __("Add the cost of every class in the cart") },
                        { value: "per_order", label: __("Charge only the most expensive class") },
                    ]}
                />
            </div>
        </div>
    );
}

/** A decimal typed as text, reported as a number or null when empty. */
function NumberInput({ value, onChange, whole, ...rest }: { value: number | null; onChange: (value: number | null) => void; whole?: boolean; "aria-label": string; className?: string }) {
    const [text, setText] = useState(value === null ? "" : String(value));
    useEffect(() => {
        setText((current) => (current.trim() !== "" && Number(current) === value ? current : value === null ? "" : String(value)));
    }, [value]);
    return (
        <Input
            inputMode={whole ? "numeric" : "decimal"}
            placeholder="∞"
            className={rest.className}
            aria-label={rest["aria-label"]}
            value={text}
            onChange={(e) => {
                setText(e.target.value);
                const n = Number(e.target.value.replace(",", "."));
                onChange(e.target.value.trim() === "" || !Number.isFinite(n) ? null : Math.max(0, whole ? Math.round(n) : n));
            }}
        />
    );
}

const newRow = (): RateRow => ({ class: "*", condition: "weight", min: null, max: null, cost: 0, item_cost: 0, weight_cost: 0, percent: 0, abort: false });

export function RateTableField({ value, classes, unit, onChange }: { value: unknown; classes: ShippingClass[]; unit: string; onChange: (rows: RateRow[]) => void }) {
    const rows = Array.isArray(value) ? (value as RateRow[]) : [];
    const set = (index: number, patch: Partial<RateRow>) => onChange(rows.map((r, i) => (i === index ? { ...r, ...patch } : r)));
    const move = (index: number, by: number) => {
        const next = [...rows];
        const [row] = next.splice(index, 1);
        next.splice(index + by, 0, row);
        onChange(next);
    };
    const classOptions = [
        { value: "*", label: __("Any class") },
        ...classes.map((c) => ({ value: c.slug, label: c.name })),
        { value: "", label: __("No shipping class") },
    ];
    const conditions = [
        { value: "weight", label: sprintf(__("Weight (%s)"), unit) },
        { value: "items", label: __("Item count") },
        { value: "subtotal", label: __("Subtotal") },
        { value: "none", label: __("Always") },
    ];

    return (
        <div className="fxc:space-y-2">
            {rows.length === 0 && <p className="fxc:text-muted-foreground fxc:text-xs">{__("No rows yet. Without a matching row this method is not offered.")}</p>}
            {rows.map((row, index) => {
                const n = index + 1;
                const bound = (key: "min" | "max", label: string) =>
                    row.condition === "subtotal" ? (
                        <MoneyInput
                            className="fxc:w-full"
                            aria-label={sprintf(label, n)}
                            value={row[key] ?? 0}
                            exponent={exponent()}
                            onChange={(amount) => set(index, { [key]: amount === 0 && key === "max" ? null : amount })}
                        />
                    ) : (
                        <NumberInput className="fxc:w-full" whole={row.condition === "items"} aria-label={sprintf(label, n)} value={row[key]} onChange={(v) => set(index, { [key]: v })} />
                    );
                return (
                    <div key={index} className="fxc:bg-background fxc:space-y-3 fxc:rounded-md fxc:border fxc:p-3" data-testid="rate-row">
                        <div className="fxc:flex fxc:items-center fxc:gap-2">
                            <span className="fxc:text-muted-foreground fxc:text-xs fxc:font-medium">{sprintf(__("Row %d"), n)}</span>
                            <div className="fxc:ml-auto fxc:flex fxc:items-center fxc:gap-1">
                                <Button variant="ghost" size="icon" aria-label={sprintf(__("Move row %d up"), n)} disabled={index === 0} onClick={() => move(index, -1)}>
                                    <ArrowUp aria-hidden />
                                </Button>
                                <Button variant="ghost" size="icon" aria-label={sprintf(__("Move row %d down"), n)} disabled={index === rows.length - 1} onClick={() => move(index, 1)}>
                                    <ArrowDown aria-hidden />
                                </Button>
                                <Button variant="ghost" size="icon" aria-label={sprintf(__("Delete row %d"), n)} onClick={() => onChange(rows.filter((_, i) => i !== index))}>
                                    <Trash2 aria-hidden />
                                </Button>
                            </div>
                        </div>
                        <div className="fxc:grid fxc:gap-3 fxc:sm:grid-cols-4">
                            <Cell label={__("Class")}>
                                <Select className="fxc:w-full" aria-label={sprintf(__("Row %d: class"), n)} value={row.class} options={classOptions} onChange={(e) => set(index, { class: e.target.value })} />
                            </Cell>
                            <Cell label={__("When")}>
                                <Select
                                    className="fxc:w-full"
                                    aria-label={sprintf(__("Row %d: condition"), n)}
                                    value={row.condition}
                                    options={conditions}
                                    onChange={(e) => set(index, { condition: e.target.value as Condition, min: null, max: null })}
                                />
                            </Cell>
                            {row.condition !== "none" && (
                                <>
                                    <Cell label={__("From")}>{bound("min", __("Row %d: from"))}</Cell>
                                    <Cell label={__("Up to")}>{bound("max", __("Row %d: up to"))}</Cell>
                                </>
                            )}
                        </div>
                        <label className="fxc:flex fxc:items-center fxc:gap-2 fxc:text-sm">
                            <input type="checkbox" className="flexacommerce-check" checked={row.abort} onChange={(e) => set(index, { abort: e.target.checked })} />
                            {__("Do not ship when this row matches")}
                        </label>
                        {!row.abort && (
                            <div className="fxc:grid fxc:gap-3 fxc:sm:grid-cols-4">
                                <Cell label={__("Cost")}>
                                    <MoneyInput className="fxc:w-full" aria-label={sprintf(__("Row %d: cost"), n)} value={row.cost} exponent={exponent()} onChange={(cost) => set(index, { cost })} />
                                </Cell>
                                <Cell label={__("Per item")}>
                                    <MoneyInput
                                        className="fxc:w-full"
                                        aria-label={sprintf(__("Row %d: cost per item"), n)}
                                        value={row.item_cost}
                                        exponent={exponent()}
                                        onChange={(item_cost) => set(index, { item_cost })}
                                    />
                                </Cell>
                                <Cell label={sprintf(__("Per %s"), unit)}>
                                    <MoneyInput
                                        className="fxc:w-full"
                                        aria-label={sprintf(__("Row %d: cost per weight unit"), n)}
                                        value={row.weight_cost}
                                        exponent={exponent()}
                                        onChange={(weight_cost) => set(index, { weight_cost })}
                                    />
                                </Cell>
                                <Cell label={__("% of subtotal")}>
                                    <NumberInput
                                        className="fxc:w-full"
                                        aria-label={sprintf(__("Row %d: percent of subtotal"), n)}
                                        value={row.percent === 0 ? null : row.percent}
                                        onChange={(v) => set(index, { percent: Math.min(100, v ?? 0) })}
                                    />
                                </Cell>
                            </div>
                        )}
                    </div>
                );
            })}
            <Button variant="outline" size="sm" onClick={() => onChange([...rows, newRow()])}>
                <Plus aria-hidden />
                {__("Add row")}
            </Button>
        </div>
    );
}

function Cell({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div className="fxc:min-w-0 fxc:space-y-1">
            <div aria-hidden className="fxc:text-muted-foreground fxc:text-xs">
                {label}
            </div>
            {children}
        </div>
    );
}
