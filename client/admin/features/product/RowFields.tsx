import { useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { Input } from "@/shared/components/ui/input";
import { Select } from "@/shared/components/ui/select";
import { __ } from "@/shared/lib/i18n";
import { localInputToUtc, utcToLocalInput } from "@/shared/lib/units";
import { DimensionInput, Field, IntInput, MoneyInput, Toggle, WeightInput } from "./fields";
import type { Backorders, StockStatus, VariantRow } from "./types";

export type RowChange = (patch: Partial<VariantRow>) => void;

/** Server errors point at a field path such as `base.sale_price` or `variations.2.sku`. */
export type FieldErrors = Record<string, string>;

const STOCK_STATUS_OPTIONS: { value: StockStatus; label: string }[] = [
    { value: "instock", label: __("In stock") },
    { value: "outofstock", label: __("Out of stock") },
    { value: "onbackorder", label: __("On backorder") },
];

const BACKORDER_OPTIONS: { value: Backorders; label: string }[] = [
    { value: "no", label: __("Do not allow") },
    { value: "notify", label: __("Allow, but notify the customer") },
    { value: "yes", label: __("Allow") },
];

const GRID = "fxc:grid fxc:gap-4 fxc:sm:grid-cols-2";

export function PricingFields({ row, onChange, errors, path }: { row: VariantRow; onChange: RowChange; errors: FieldErrors; path: string }) {
    const [schedule, setSchedule] = useState(row.sale_from !== null || row.sale_to !== null);

    return (
        <div className="fxc:space-y-4">
            <div className={GRID}>
                <Field label={__("Regular price")} error={errors[`${path}.regular_price`]}>
                    {(id) => <MoneyInput id={id} value={row.regular_price} onChange={(regular_price) => onChange({ regular_price })} />}
                </Field>
                <Field label={__("Sale price")} error={errors[`${path}.sale_price`]}>
                    {(id) => <MoneyInput id={id} value={row.sale_price} onChange={(sale_price) => onChange({ sale_price })} />}
                </Field>
            </div>
            {schedule ? (
                <div className={GRID}>
                    <Field label={__("Sale starts")} error={errors[`${path}.sale_from`]}>
                        {(id) => (
                            <Input
                                id={id}
                                type="datetime-local"
                                value={utcToLocalInput(row.sale_from)}
                                onChange={(event) => onChange({ sale_from: localInputToUtc(event.target.value) })}
                            />
                        )}
                    </Field>
                    <Field label={__("Sale ends")} error={errors[`${path}.sale_to`]}>
                        {(id) => (
                            <Input
                                id={id}
                                type="datetime-local"
                                value={utcToLocalInput(row.sale_to)}
                                onChange={(event) => onChange({ sale_to: localInputToUtc(event.target.value) })}
                            />
                        )}
                    </Field>
                    <div className="fxc:sm:col-span-2">
                        <Button
                            variant="link"
                            size="sm"
                            className="fxc:px-0"
                            onClick={() => {
                                setSchedule(false);
                                onChange({ sale_from: null, sale_to: null });
                            }}
                        >
                            {__("Remove schedule")}
                        </Button>
                    </div>
                </div>
            ) : (
                <Button variant="link" size="sm" className="fxc:px-0" onClick={() => setSchedule(true)}>
                    {__("Schedule the sale")}
                </Button>
            )}
        </div>
    );
}

export function InventoryFields({
    row,
    onChange,
    errors,
    path,
    baseManagesStock = false,
}: {
    row: VariantRow;
    onChange: RowChange;
    errors: FieldErrors;
    path: string;
    /** Set on variations whose product tracks stock, to offer the shared pool. */
    baseManagesStock?: boolean;
}) {
    const sharesBaseStock = !row.is_base && row.stock_from_base;

    return (
        <div className="fxc:space-y-4">
            <Field label={__("SKU")} error={errors[`${path}.sku`]} help={__("Unique across the store. Leave empty if you do not use SKUs.")}>
                {(id) => (
                    <Input
                        id={id}
                        value={row.sku ?? ""}
                        maxLength={100}
                        onChange={(event) => onChange({ sku: event.target.value === "" ? null : event.target.value })}
                    />
                )}
            </Field>

            {!row.is_base && baseManagesStock && (
                <Toggle
                    checked={row.stock_from_base}
                    onChange={(stock_from_base) => onChange({ stock_from_base, manage_stock: stock_from_base ? false : row.manage_stock })}
                    label={__("Use the product's stock")}
                    help={__("Sales of this variation count against the stock set on the Inventory tab.")}
                />
            )}

            {!sharesBaseStock && (
                <Toggle
                    checked={row.manage_stock}
                    onChange={(manage_stock) => onChange({ manage_stock })}
                    label={__("Track stock quantity")}
                />
            )}

            {!sharesBaseStock &&
                (row.manage_stock ? (
                    <div className={GRID}>
                        <Field label={__("Quantity")} error={errors[`${path}.stock_quantity`]}>
                            {(id) => (
                                <IntInput id={id} allowNegative value={row.stock_quantity} onChange={(stock_quantity) => onChange({ stock_quantity })} />
                            )}
                        </Field>
                        <Field label={__("Low stock threshold")} error={errors[`${path}.low_stock_threshold`]}>
                            {(id) => (
                                <IntInput
                                    id={id}
                                    value={row.low_stock_threshold}
                                    placeholder={__("Store default")}
                                    onChange={(low_stock_threshold) => onChange({ low_stock_threshold })}
                                />
                            )}
                        </Field>
                        <Field label={__("Backorders")} className="fxc:sm:col-span-2">
                            {(id) => (
                                <Select
                                    id={id}
                                    className="fxc:w-full"
                                    options={BACKORDER_OPTIONS}
                                    value={row.backorders}
                                    onChange={(event) => onChange({ backorders: event.target.value as Backorders })}
                                />
                            )}
                        </Field>
                    </div>
                ) : (
                    <Field label={__("Stock status")}>
                        {(id) => (
                            <Select
                                id={id}
                                className="fxc:w-full"
                                options={STOCK_STATUS_OPTIONS}
                                value={row.stock_status}
                                onChange={(event) => onChange({ stock_status: event.target.value as StockStatus })}
                            />
                        )}
                    </Field>
                ))}
        </div>
    );
}

export function ShippingFields({ row, onChange, errors, path }: { row: VariantRow; onChange: RowChange; errors: FieldErrors; path: string }) {
    return (
        <div className="fxc:space-y-4">
            <Field label={__("Weight")} error={errors[`${path}.weight`]} className="fxc:sm:max-w-[50%]">
                {(id) => <WeightInput id={id} value={row.weight} onChange={(weight) => onChange({ weight })} />}
            </Field>
            <div className="fxc:grid fxc:gap-4 fxc:sm:grid-cols-3">
                <Field label={__("Length")} error={errors[`${path}.length`]}>
                    {(id) => <DimensionInput id={id} value={row.length} onChange={(length) => onChange({ length })} />}
                </Field>
                <Field label={__("Width")} error={errors[`${path}.width`]}>
                    {(id) => <DimensionInput id={id} value={row.width} onChange={(width) => onChange({ width })} />}
                </Field>
                <Field label={__("Height")} error={errors[`${path}.height`]}>
                    {(id) => <DimensionInput id={id} value={row.height} onChange={(height) => onChange({ height })} />}
                </Field>
            </div>
        </div>
    );
}
