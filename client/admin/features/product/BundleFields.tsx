import { keepPreviousData, useQuery } from "@tanstack/react-query";
import { ArrowDown, ArrowUp, X } from "lucide-react";
import { useState } from "react";

import { SearchBox, type Row } from "../simulator/SearchBox";
import { Button } from "@/shared/components/ui/button";
import { Input } from "@/shared/components/ui/input";
import { Select } from "@/shared/components/ui/select";
import { api } from "@/shared/lib/api";
import { __, sprintf } from "@/shared/lib/i18n";
import { useDebounced } from "@/shared/lib/useDebounced";
import { Field } from "./fields";
import type { FieldErrors } from "./RowFields";
import type { BundleData, BundleItem, BundleMode } from "./types";

const MAX_ITEMS = 20;
const MAX_QTY = 999;

/** A row of `admin/coupons/targets?kind=part`: a simple product's base row or one variation. */
interface Part {
    id: number;
    product_id: number;
    label: string;
    sku: string;
}

const MODES: { value: BundleMode; label: string }[] = [
    { value: "fixed", label: __("Its own price") },
    { value: "sum", label: __("The sum of its items") },
    { value: "discount", label: __("The sum of its items, less a discount") },
];

/**
 * What a bundle holds and how it is priced (ADR-071). Items are simple
 * products or single variations, each with the units one bundle takes. The
 * bundle keeps no stock: each sale takes stock from its items.
 */
export function BundleFields({ bundle, productId, onChange, errors }: { bundle: BundleData; productId: number; onChange: (bundle: BundleData) => void; errors: FieldErrors }) {
    const [text, setText] = useState("");
    const [open, setOpen] = useState(false);
    const search = useDebounced(text.trim(), 250);
    const full = bundle.items.length >= MAX_ITEMS;
    const results = useQuery({
        queryKey: ["products", "bundle-search", search],
        queryFn: () => api.get<Part[]>("admin/coupons/targets", { kind: "part", search }),
        enabled: open && !full,
        placeholderData: keepPreviousData,
        staleTime: 30_000,
    });
    const taken = new Set(bundle.items.map((item) => item.variant_id));
    const found = (results.data ?? []).filter((part) => part.product_id !== productId && !taken.has(part.id));
    const rows: Row[] = found.map((part) => ({ key: String(part.id), label: part.label, hint: part.sku }));

    const setItems = (items: BundleItem[]) => onChange({ ...bundle, items });
    const setItem = (index: number, patch: Partial<BundleItem>) => setItems(bundle.items.map((item, i) => (i === index ? { ...item, ...patch } : item)));
    const move = (index: number, by: number) => {
        const next = [...bundle.items];
        const [item] = next.splice(index, 1);
        next.splice(index + by, 0, item);
        setItems(next);
    };

    return (
        <div className="fxc:space-y-4">
            <p className="fxc:text-muted-foreground fxc:text-sm">
                {__("A bundle sells several products as one. It has no stock of its own: each sale takes stock from the items, and the bundle is out of stock when one of them runs out.")}
            </p>
            <div className="fxc:grid fxc:gap-4 fxc:sm:grid-cols-2">
                <Field label={__("Price")} error={errors["bundle.mode"]}>
                    {(id) => (
                        <Select
                            id={id}
                            value={bundle.mode}
                            options={MODES}
                            onChange={(event) => {
                                const mode = event.target.value as BundleMode;
                                onChange({ ...bundle, mode, discount: mode === "discount" ? bundle.discount || 10 : 0 });
                            }}
                        />
                    )}
                </Field>
                {bundle.mode === "discount" && (
                    <Field label={__("Discount (%)")} help={__("Taken off the items' sum, from 1 to 99 percent.")} error={errors["bundle.discount"]}>
                        {(id) => (
                            <Input
                                id={id}
                                type="number"
                                min={1}
                                max={99}
                                className="fxc:w-28"
                                value={bundle.discount}
                                onChange={(e) => onChange({ ...bundle, discount: Number.parseInt(e.target.value, 10) || 0 })}
                            />
                        )}
                    </Field>
                )}
            </div>
            {bundle.mode !== "fixed" && (
                <p className="fxc:text-muted-foreground fxc:text-sm">
                    {__("The price follows the items: a sale on an item lowers the bundle too. Prices are worked out when the product is saved and when an item changes.")}
                </p>
            )}
            <SearchBox
                id="fxc-bundle-products"
                label={__("Add a product to the bundle")}
                placeholder={full ? sprintf(__("At most %d items"), MAX_ITEMS) : __("Search products by name or SKU")}
                text={text}
                onText={setText}
                rows={rows}
                fetching={results.isFetching}
                onPick={(row) => {
                    const picked = found.find((part) => String(part.id) === row.key);
                    if (picked) {
                        setItems([...bundle.items, { product_id: picked.product_id, variant_id: picked.id, quantity: 1, label: picked.label, sku: picked.sku, missing: false }]);
                    }
                }}
                open={open && !full}
                onOpen={setOpen}
            />
            {errors.bundle && <p className="fxc:text-destructive fxc:text-sm" role="alert">{errors.bundle}</p>}
            {errors["bundle.items"] && <p className="fxc:text-destructive fxc:text-sm" role="alert">{errors["bundle.items"]}</p>}
            {bundle.items.length === 0 ? (
                <p className="fxc:text-muted-foreground fxc:text-sm">{__("No items in this bundle yet. Shoppers cannot buy it until it holds one.")}</p>
            ) : (
                <ol className="fxc:border-border fxc:divide-border fxc:divide-y fxc:rounded-md fxc:border" aria-label={__("Items in this bundle")}>
                    {bundle.items.map((item, index) => (
                        <li key={item.variant_id} className="fxc:flex fxc:flex-wrap fxc:items-center fxc:gap-3 fxc:px-3 fxc:py-2 fxc:text-sm">
                            <span className="fxc:min-w-0 fxc:flex-1">
                                <span className={item.missing ? "fxc:text-destructive" : undefined}>{item.label}</span>
                                {item.sku !== "" && <span className="fxc:text-muted-foreground fxc:text-xs"> · {item.sku}</span>}
                                {errors[`bundle.items.${index}.quantity`] && (
                                    <span className="fxc:text-destructive fxc:block fxc:text-xs" role="alert">
                                        {errors[`bundle.items.${index}.quantity`]}
                                    </span>
                                )}
                            </span>
                            <Input
                                type="number"
                                min={1}
                                max={MAX_QTY}
                                className="fxc:w-20"
                                aria-label={sprintf(__("Units of %s in one bundle"), item.label)}
                                value={item.quantity}
                                onChange={(e) => setItem(index, { quantity: Math.min(MAX_QTY, Math.max(1, Number.parseInt(e.target.value, 10) || 1)) })}
                            />
                            <Button variant="ghost" size="icon" className="fxc:size-7" disabled={index === 0} aria-label={sprintf(__("Move %s up"), item.label)} onClick={() => move(index, -1)}>
                                <ArrowUp aria-hidden className="fxc:size-4" />
                            </Button>
                            <Button variant="ghost" size="icon" className="fxc:size-7" disabled={index === bundle.items.length - 1} aria-label={sprintf(__("Move %s down"), item.label)} onClick={() => move(index, 1)}>
                                <ArrowDown aria-hidden className="fxc:size-4" />
                            </Button>
                            <Button variant="ghost" size="icon" className="fxc:size-7" aria-label={sprintf(__("Remove %s"), item.label)} onClick={() => setItems(bundle.items.filter((_, i) => i !== index))}>
                                <X aria-hidden className="fxc:size-4" />
                            </Button>
                        </li>
                    ))}
                </ol>
            )}
        </div>
    );
}
