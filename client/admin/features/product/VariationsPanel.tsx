import { useState } from "react";
import { ChevronDown, ChevronRight, ImagePlus, Plus, Trash2, Wand2 } from "lucide-react";

import { Badge } from "@/shared/components/ui/badge";
import { Button } from "@/shared/components/ui/button";
import { Select } from "@/shared/components/ui/select";
import { getPluginGlobal } from "@/shared/lib/api";
import { cn } from "@/shared/lib/cn";
import { __, _n, sprintf } from "@/shared/lib/i18n";
import { formatMoney } from "@/shared/lib/units";
import { canPickImage, pickImage } from "../../components/pickImage";
import { combinationLabel, newVariation } from "./draft";
import { Field, IntInput, MoneyInput, Toggle } from "./fields";
import { InventoryFields, PricingFields, ShippingFields, TaxFields, type FieldErrors } from "./RowFields";
import type { ProductAttribute, VariantRow } from "./types";

type BulkAction = "" | "regular_price" | "sale_price" | "stock_quantity" | "enable" | "disable" | "delete";

export function VariationsPanel({
    base,
    rows,
    axes,
    stale,
    busy,
    errors,
    onChange,
    onGenerate,
    onSaveAttributes,
}: {
    base: VariantRow;
    rows: VariantRow[];
    /** Attributes marked "used for variations", with their options. */
    axes: ProductAttribute[];
    /** True while attribute edits are unsaved, so the options here are out of date. */
    stale: boolean;
    busy: boolean;
    errors: FieldErrors;
    onChange: (rows: VariantRow[]) => void;
    onGenerate: () => void;
    onSaveAttributes: () => void;
}) {
    const [open, setOpen] = useState<number | null>(null);
    const [selected, setSelected] = useState<Set<number>>(new Set());
    const { currency } = getPluginGlobal();
    const anyLabel = __("Any");

    if (stale) {
        return (
            <div className="fxc:bg-muted fxc:space-y-3 fxc:rounded-lg fxc:p-4">
                <p className="fxc:text-sm">{__("Save the attributes before editing variations, so their values are available here.")}</p>
                <Button onClick={onSaveAttributes} disabled={busy}>
                    {__("Save attributes")}
                </Button>
            </div>
        );
    }

    if (axes.length === 0) {
        return (
            <p className="fxc:text-muted-foreground fxc:text-sm">
                {__("Add an attribute on the Attributes tab and tick “Used for variations” to create variations.")}
            </p>
        );
    }

    const update = (index: number, patch: Partial<VariantRow>) => onChange(rows.map((row, i) => (i === index ? { ...row, ...patch } : row)));

    const toggleSelected = (index: number, on: boolean) => {
        const next = new Set(selected);
        if (on) {
            next.add(index);
        } else {
            next.delete(index);
        }
        setSelected(next);
    };

    const applyBulk = (patch: Partial<VariantRow> | "delete") => {
        if (patch === "delete") {
            onChange(rows.filter((_, i) => !selected.has(i)));
            setSelected(new Set());
            setOpen(null);
            return;
        }
        onChange(rows.map((row, i) => (selected.has(i) ? { ...row, ...patch } : row)));
    };

    const allSelected = rows.length > 0 && selected.size === rows.length;

    return (
        <div className="fxc:space-y-4">
            <div className="fxc:flex fxc:flex-wrap fxc:items-center fxc:gap-2">
                <Button variant="outline" onClick={onGenerate} disabled={busy}>
                    <Wand2 aria-hidden />
                    {__("Generate variations")}
                </Button>
                <Button
                    variant="ghost"
                    onClick={() => {
                        onChange([...rows, newVariation(base, axes)]);
                        setOpen(rows.length);
                    }}
                >
                    <Plus aria-hidden />
                    {__("Add manually")}
                </Button>
                <span className="fxc:text-muted-foreground fxc:ml-auto fxc:text-xs">
                    {sprintf(_n("%d variation", "%d variations", rows.length), rows.length)}
                </span>
            </div>

            {rows.length > 0 && (
                <BulkBar
                    count={selected.size}
                    allSelected={allSelected}
                    onSelectAll={(on) => setSelected(on ? new Set(rows.map((_, i) => i)) : new Set())}
                    onApply={applyBulk}
                />
            )}

            <ul className="fxc:border-border fxc:divide-border fxc:divide-y fxc:rounded-lg fxc:border">
                {rows.map((row, index) => {
                    const expanded = open === index;
                    const path = `variations.${index}`;
                    const rowHasError = Object.keys(errors).some((key) => key === path || key.startsWith(`${path}.`));
                    return (
                        <li key={row.id > 0 ? row.id : `new-${index}`} className={cn(!row.enabled && "fxc:bg-muted/60")}>
                            <div className="fxc:flex fxc:items-center fxc:gap-3 fxc:px-3 fxc:py-2">
                                <input
                                    type="checkbox"
                                    className="flexacommerce-check"
                                    aria-label={__("Select variation")}
                                    checked={selected.has(index)}
                                    onChange={(event) => toggleSelected(index, event.target.checked)}
                                />
                                <button
                                    type="button"
                                    className="fxc:flex fxc:min-w-0 fxc:flex-1 fxc:cursor-pointer fxc:items-center fxc:gap-2 fxc:bg-transparent fxc:text-left"
                                    aria-expanded={expanded}
                                    onClick={() => setOpen(expanded ? null : index)}
                                >
                                    {expanded ? <ChevronDown aria-hidden className="fxc:size-4 fxc:shrink-0" /> : <ChevronRight aria-hidden className="fxc:size-4 fxc:shrink-0" />}
                                    <span className="fxc:truncate fxc:text-sm fxc:font-medium">{combinationLabel(row, axes, anyLabel)}</span>
                                </button>
                                {rowHasError && <Badge variant="destructive">{__("Check this variation")}</Badge>}
                                {!row.enabled && <Badge>{__("Disabled")}</Badge>}
                                {row.sku && <span className="fxc:text-muted-foreground fxc:hidden fxc:text-xs fxc:sm:inline">{row.sku}</span>}
                                <span className="fxc:w-24 fxc:text-right fxc:text-sm">
                                    {row.regular_price === null ? <span className="fxc:text-muted-foreground">{__("No price")}</span> : formatMoney(row.sale_price ?? row.regular_price, currency)}
                                </span>
                            </div>
                            {expanded && (
                                <VariationEditor
                                    row={row}
                                    axes={axes}
                                    path={path}
                                    errors={errors}
                                    baseManagesStock={base.manage_stock}
                                    onChange={(patch) => update(index, patch)}
                                    onRemove={() => {
                                        onChange(rows.filter((_, i) => i !== index));
                                        setOpen(null);
                                        setSelected(new Set());
                                    }}
                                />
                            )}
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}

function BulkBar({
    count,
    allSelected,
    onSelectAll,
    onApply,
}: {
    count: number;
    allSelected: boolean;
    onSelectAll: (on: boolean) => void;
    onApply: (patch: Partial<VariantRow> | "delete") => void;
}) {
    const [action, setAction] = useState<BulkAction>("");
    const [amount, setAmount] = useState<number | null>(null);

    const options: { value: BulkAction; label: string }[] = [
        { value: "", label: __("Bulk actions") },
        { value: "regular_price", label: __("Set regular price") },
        { value: "sale_price", label: __("Set sale price") },
        { value: "stock_quantity", label: __("Set stock quantity") },
        { value: "enable", label: __("Enable") },
        { value: "disable", label: __("Disable") },
        { value: "delete", label: __("Delete") },
    ];

    const apply = () => {
        switch (action) {
            case "regular_price":
                onApply({ regular_price: amount });
                break;
            case "sale_price":
                onApply({ sale_price: amount });
                break;
            case "stock_quantity":
                onApply({ manage_stock: true, stock_from_base: false, stock_quantity: amount });
                break;
            case "enable":
                onApply({ enabled: true });
                break;
            case "disable":
                onApply({ enabled: false });
                break;
            case "delete":
                onApply("delete");
                break;
        }
    };

    const needsAmount = action === "regular_price" || action === "sale_price" || action === "stock_quantity";

    return (
        <div className="fxc:flex fxc:flex-wrap fxc:items-center fxc:gap-2">
            <input
                type="checkbox"
                className="flexacommerce-check fxc:ml-3"
                aria-label={__("Select all variations")}
                checked={allSelected}
                onChange={(event) => onSelectAll(event.target.checked)}
            />
            <Select options={options} value={action} onChange={(event) => setAction(event.target.value as BulkAction)} aria-label={__("Bulk action")} />
            {needsAmount && (
                <div className="fxc:w-36">
                    {action === "stock_quantity" ? (
                        <IntInput id="flexacommerce-bulk-amount" allowNegative value={amount} onChange={setAmount} />
                    ) : (
                        <MoneyInput id="flexacommerce-bulk-amount" value={amount} onChange={setAmount} />
                    )}
                </div>
            )}
            <Button variant="outline" size="sm" className="fxc:h-9" disabled={action === "" || count === 0} onClick={apply}>
                {count > 0 ? sprintf(_n("Apply to %d variation", "Apply to %d variations", count), count) : __("Apply")}
            </Button>
        </div>
    );
}

function VariationEditor({
    row,
    axes,
    path,
    errors,
    baseManagesStock,
    onChange,
    onRemove,
}: {
    row: VariantRow;
    axes: ProductAttribute[];
    path: string;
    errors: FieldErrors;
    baseManagesStock: boolean;
    onChange: (patch: Partial<VariantRow>) => void;
    onRemove: () => void;
}) {
    return (
        <div className="fxc:bg-muted/40 fxc:space-y-5 fxc:border-t fxc:px-4 fxc:py-4">
            <div className="fxc:grid fxc:gap-4 fxc:sm:grid-cols-3">
                {axes.map((axis) => (
                    <Field key={axis.key} label={axis.name} error={errors[`${path}.attributes`]}>
                        {(id) => (
                            <Select
                                id={id}
                                className="fxc:w-full"
                                value={row.attributes[axis.key] ?? "*"}
                                options={[{ value: "*", label: sprintf(__("Any %s"), axis.name) }, ...axis.options.map((o) => ({ value: o.key, label: o.label }))]}
                                onChange={(event) => onChange({ attributes: { ...row.attributes, [axis.key]: event.target.value } })}
                            />
                        )}
                    </Field>
                ))}
            </div>
            {errors[path] && <p className="fxc:text-destructive fxc:text-xs">{errors[path]}</p>}

            <div className="fxc:flex fxc:flex-wrap fxc:items-center fxc:gap-6">
                <Toggle checked={row.enabled} onChange={(enabled) => onChange({ enabled })} label={__("Enabled")} help={__("Disabled variations cannot be bought.")} />
                <ImagePicker imageId={row.image_id} onChange={(image_id) => onChange({ image_id })} />
            </div>

            <PricingFields row={row} onChange={onChange} errors={errors} path={path} />
            <InventoryFields row={row} onChange={onChange} errors={errors} path={path} baseManagesStock={baseManagesStock} />
            <ShippingFields row={row} onChange={onChange} errors={errors} path={path} />
            <TaxFields row={row} onChange={onChange} errors={errors} path={path} />

            <Field label={__("Description")}>
                {(id) => (
                    <textarea
                        id={id}
                        rows={3}
                        className="flexacommerce-control fxc:w-full fxc:py-2 fxc:text-sm"
                        value={row.description ?? ""}
                        onChange={(event) => onChange({ description: event.target.value === "" ? null : event.target.value })}
                    />
                )}
            </Field>

            <div className="fxc:flex fxc:justify-end">
                <Button variant="ghost" size="sm" className="fxc:text-destructive" onClick={onRemove}>
                    <Trash2 aria-hidden />
                    {__("Remove variation")}
                </Button>
            </div>
        </div>
    );
}

function ImagePicker({ imageId, onChange }: { imageId: number | null; onChange: (id: number | null) => void }) {
    const [preview, setPreview] = useState<string | null>(null);
    if (!canPickImage()) {
        return null;
    }

    const choose = () =>
        pickImage(__("Variation image"), (image) => {
            setPreview(image.url);
            onChange(image.id);
        });

    return (
        <div className="fxc:flex fxc:items-center fxc:gap-2">
            {preview && <img src={preview} alt="" className="fxc:size-9 fxc:rounded fxc:object-cover" />}
            <Button variant="outline" size="sm" onClick={choose}>
                <ImagePlus aria-hidden />
                {imageId ? __("Change image") : __("Add image")}
            </Button>
            {imageId !== null && (
                <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => {
                        setPreview(null);
                        onChange(null);
                    }}
                >
                    {__("Remove image")}
                </Button>
            )}
        </div>
    );
}
