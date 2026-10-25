import { useEffect, useMemo, useState } from "react";

import { showToast } from "@/shared/components/showToast";
import { Button } from "@/shared/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/shared/components/ui/dialog";
import { Input } from "@/shared/components/ui/input";
import { Label } from "@/shared/components/ui/label";
import { __, sprintf } from "@/shared/lib/i18n";
import { currencyExponent, orderMoney } from "./format";
import { MoneyInput } from "../../components/MoneyInput";
import type { OrderDetail, OrderItem, RefundLineInput } from "./types";
import { useRefund } from "./useOrders";

interface Line {
    qty: number;
    restock: boolean;
}

function refundableQty(item: OrderItem): number {
    return Math.max(0, item.quantity - item.refunded_qty);
}

function remaining(item: OrderItem): number {
    return Math.max(0, item.total + item.tax_total - item.refunded_total);
}

/** The share of the line (tax included) for `qty` units, never above what is left. */
function lineAmount(item: OrderItem, qty: number): number {
    if (qty <= 0 || item.quantity <= 0) {
        return 0;
    }
    return Math.min(remaining(item), Math.round(((item.total + item.tax_total) * qty) / item.quantity));
}

interface Props {
    order: OrderDetail;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}

export function RefundDialog({ order, open, onOpenChange }: Props) {
    const refund = useRefund(order.id);
    const exponent = currencyExponent(order.currency);
    const items = useMemo(() => order.items.filter((i) => i.type !== "discount" && i.type !== "tax" && remaining(i) > 0), [order.items]);

    const [lines, setLines] = useState<Record<number, Line>>({});
    const [amount, setAmount] = useState(0);
    const [touched, setTouched] = useState(false);
    const [reason, setReason] = useState("");
    const [error, setError] = useState("");

    useEffect(() => {
        if (open) {
            setLines({});
            setAmount(0);
            setTouched(false);
            setReason("");
            setError("");
        }
    }, [open]);

    const linesTotal = items.reduce((sum, item) => sum + lineAmount(item, lines[item.id]?.qty ?? 0), 0);
    const total = touched ? amount : Math.min(linesTotal, order.refundable);

    const setLine = (item: OrderItem, patch: Partial<Line>) => {
        const current = lines[item.id] ?? { qty: 0, restock: item.stock_managed };
        setLines({ ...lines, [item.id]: { ...current, ...patch } });
    };

    const payload = (): RefundLineInput[] =>
        items
            .filter((item) => (lines[item.id]?.qty ?? 0) > 0)
            .map((item) => {
                const line = lines[item.id];
                return {
                    item_id: item.id,
                    qty: line.qty,
                    amount: lineAmount(item, line.qty),
                    restock: item.type === "product" && line.restock,
                };
            });

    const valid = Number.isFinite(total) && total > 0 && total <= order.refundable;

    const submit = (viaGateway: boolean) => {
        setError("");
        refund.mutate(
            { amount: total, lines: payload(), reason, via_gateway: viaGateway },
            {
                onSuccess: () => {
                    onOpenChange(false);
                    showToast.success(__("Refund recorded."));
                },
                onError: (e) => setError(e.message),
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={(next) => !refund.isPending && onOpenChange(next)}>
            <DialogContent className="fxc:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>{sprintf(__("Refund order #%s"), order.number)}</DialogTitle>
                    <DialogDescription>
                        {sprintf(__("Up to %s can be refunded."), orderMoney(order.refundable, order.currency))}
                    </DialogDescription>
                </DialogHeader>

                {items.length > 0 && (
                    <table className="fxc:w-full fxc:text-sm">
                        <thead>
                            <tr className="fxc:text-muted-foreground fxc:border-b fxc:text-left fxc:text-xs">
                                <th className="fxc:py-2 fxc:font-medium">{__("Item")}</th>
                                <th className="fxc:py-2 fxc:font-medium">{__("Quantity")}</th>
                                <th className="fxc:py-2 fxc:font-medium">{__("Restock")}</th>
                                <th className="fxc:py-2 fxc:text-right fxc:font-medium">{__("Amount")}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {items.map((item) => {
                                const max = refundableQty(item);
                                const line = lines[item.id] ?? { qty: 0, restock: item.stock_managed };
                                return (
                                    <tr key={item.id} className="fxc:border-b fxc:last:border-b-0">
                                        <td className="fxc:py-2 fxc:pr-3">
                                            <div className="fxc:font-medium">{item.name}</div>
                                            <div className="fxc:text-muted-foreground fxc:text-xs">
                                                {sprintf(__("%1$d of %2$d left to refund"), max, item.quantity)}
                                            </div>
                                        </td>
                                        <td className="fxc:py-2 fxc:pr-3">
                                            <Input
                                                type="number"
                                                min={0}
                                                max={max}
                                                className="fxc:w-20"
                                                aria-label={sprintf(__("Quantity of %s to refund"), item.name)}
                                                value={line.qty}
                                                disabled={max === 0}
                                                onChange={(e) =>
                                                    setLine(item, { qty: Math.min(max, Math.max(0, Number.parseInt(e.target.value, 10) || 0)) })
                                                }
                                            />
                                        </td>
                                        <td className="fxc:py-2 fxc:pr-3">
                                            {item.type === "product" && item.stock_managed && (
                                                <input
                                                    type="checkbox"
                                                    className="flexacommerce-check"
                                                    aria-label={sprintf(__("Put %s back in stock"), item.name)}
                                                    checked={line.restock}
                                                    onChange={(e) => setLine(item, { restock: e.target.checked })}
                                                />
                                            )}
                                        </td>
                                        <td className="fxc:py-2 fxc:text-right fxc:tabular-nums">
                                            {orderMoney(lineAmount(item, line.qty), order.currency)}
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                )}

                <div className="fxc:grid fxc:gap-4 fxc:sm:grid-cols-2">
                    <div>
                        <Label htmlFor="fxc-refund-amount" className="fxc:mb-2 fxc:block">
                            {__("Refund amount")}
                        </Label>
                        <MoneyInput
                            id="fxc-refund-amount"
                            value={total}
                            exponent={exponent}
                            onChange={(minor) => {
                                setTouched(true);
                                setAmount(minor);
                            }}
                        />
                        {touched && total !== linesTotal && linesTotal > 0 && (
                            <button
                                type="button"
                                className="fxc:text-primary fxc:mt-1 fxc:cursor-pointer fxc:bg-transparent fxc:text-xs fxc:hover:underline"
                                onClick={() => setTouched(false)}
                            >
                                {sprintf(__("Use the item total (%s)"), orderMoney(linesTotal, order.currency))}
                            </button>
                        )}
                    </div>
                    <div>
                        <Label htmlFor="fxc-refund-reason" className="fxc:mb-2 fxc:block">
                            {__("Reason (optional)")}
                        </Label>
                        <Input id="fxc-refund-reason" value={reason} onChange={(e) => setReason(e.target.value)} />
                    </div>
                </div>

                {error !== "" && (
                    <p role="alert" className="fxc:text-destructive fxc:text-sm">
                        {error}
                    </p>
                )}

                <DialogFooter>
                    <Button variant="ghost" onClick={() => onOpenChange(false)} disabled={refund.isPending}>
                        {__("Cancel")}
                    </Button>
                    <Button variant="outline" onClick={() => submit(false)} disabled={!valid || refund.isPending}>
                        {sprintf(__("Refund %s manually"), orderMoney(valid ? total : 0, order.currency))}
                    </Button>
                    {order.gateway.refunds && (
                        <Button onClick={() => submit(true)} disabled={!valid || refund.isPending}>
                            {refund.isPending
                                ? __("Refunding…")
                                : sprintf(__("Refund %1$s via %2$s"), orderMoney(valid ? total : 0, order.currency), order.gateway.title)}
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
