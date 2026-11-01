import { useEffect, useMemo, useState } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { ExternalLink, Trash2, Truck } from "lucide-react";

import { showToast } from "@/shared/components/showToast";
import { Button } from "@/shared/components/ui/button";
import { Card, CardHeader, CardTitle } from "@/shared/components/ui/card";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/shared/components/ui/dialog";
import { Input } from "@/shared/components/ui/input";
import { Label } from "@/shared/components/ui/label";
import { Select } from "@/shared/components/ui/select";
import { __, sprintf } from "@/shared/lib/i18n";
import { formatDate } from "./format";
import type { OrderDetail, OrderShipment } from "./types";
import { useAddShipment, useDeleteShipment, useShipmentCarriers } from "./useOrders";

const SHIP_STATUSES = ["processing", "on-hold", "completed"];
const OTHER = "";

/**
 * The packages of the order with their tracking (ADR-067). The admin adds
 * one with its carrier and number, can complete the order at the same time
 * and email the customer the tracking link.
 */
export function ShipmentsCard({ order }: { order: OrderDetail }) {
    const [open, setOpen] = useState(false);
    const remove = useDeleteShipment(order.id);
    const canShip = order.can.edit && SHIP_STATUSES.includes(order.status) && order.items.some((item) => item.type === "product");
    if (order.shipments.length === 0 && !canShip) {
        return null;
    }

    const names = new Map(order.items.map((item) => [String(item.id), item.name]));
    const del = (shipment: OrderShipment) => {
        if (!window.confirm(__("Remove this shipment? The customer no longer sees its tracking."))) {
            return;
        }
        remove.mutate(shipment.id, {
            onSuccess: () => showToast.success(__("Shipment removed.")),
            onError: (e) => showToast.error(e.message),
        });
    };

    return (
        <Card>
            <CardHeader className="fxc:flex fxc:flex-row fxc:items-center fxc:justify-between fxc:gap-3">
                <CardTitle>{__("Shipments")}</CardTitle>
                {canShip && (
                    <Button variant="outline" size="sm" onClick={() => setOpen(true)}>
                        <Truck aria-hidden className="fxc:size-4" />
                        {__("Add tracking")}
                    </Button>
                )}
            </CardHeader>
            {order.shipments.length === 0 ? (
                <p className="fxc:text-muted-foreground fxc:px-5 fxc:pb-4 fxc:text-sm">
                    {__("Nothing shipped yet. Add the tracking number when the package leaves.")}
                </p>
            ) : (
                <ul className="fxc:text-sm">
                    {order.shipments.map((shipment) => (
                        <li key={shipment.id} className="fxc:flex fxc:items-start fxc:gap-3 fxc:border-b fxc:last:border-b-0 fxc:px-5 fxc:py-3">
                            <div className="fxc:min-w-0 fxc:flex-1">
                                <div className="fxc:font-medium fxc:break-words">
                                    {shipment.carrier_name}
                                    {shipment.tracking_number !== "" && (
                                        <>
                                            {" · "}
                                            {shipment.tracking_url !== "" ? (
                                                <a
                                                    href={shipment.tracking_url}
                                                    target="_blank"
                                                    rel="noreferrer"
                                                    className="fxc:text-primary fxc:inline-flex fxc:items-center fxc:gap-1 fxc:no-underline fxc:hover:underline"
                                                >
                                                    {shipment.tracking_number}
                                                    <ExternalLink aria-hidden className="fxc:size-3.5" />
                                                </a>
                                            ) : (
                                                shipment.tracking_number
                                            )}
                                        </>
                                    )}
                                </div>
                                <div className="fxc:text-muted-foreground fxc:text-xs">
                                    {sprintf(__("Shipped %s"), formatDate(shipment.shipped_at, false))}
                                    {" · "}
                                    {shipment.items === null
                                        ? __("Whole order")
                                        : Object.entries(shipment.items)
                                              .map(([id, qty]) => `${names.get(id) ?? __("Removed line")} × ${qty}`)
                                              .join(", ")}
                                </div>
                            </div>
                            {order.can.edit && (
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    aria-label={sprintf(__("Remove the %s shipment"), shipment.carrier_name)}
                                    disabled={remove.isPending}
                                    onClick={() => del(shipment)}
                                >
                                    <Trash2 aria-hidden className="fxc:size-4" />
                                </Button>
                            )}
                        </li>
                    ))}
                </ul>
            )}
            {canShip && <ShipmentDialog order={order} open={open} onOpenChange={setOpen} />}
        </Card>
    );
}

function today(): string {
    const d = new Date();
    const pad = (n: number) => String(n).padStart(2, "0");
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

interface DialogProps {
    order: OrderDetail;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}

function ShipmentDialog({ order, open, onOpenChange }: DialogProps) {
    const qc = useQueryClient();
    const carriers = useShipmentCarriers(open);
    const add = useAddShipment(order.id);
    const lines = useMemo(
        () => order.items.filter((item) => item.type === "product" && item.bundled_by === null && item.quantity - item.refunded_qty > 0),
        [order.items],
    );
    const canComplete = order.transitions.includes("completed");

    const [carrier, setCarrier] = useState(OTHER);
    const [carrierName, setCarrierName] = useState("");
    const [trackingUrl, setTrackingUrl] = useState("");
    const [number, setNumber] = useState("");
    const [date, setDate] = useState(today());
    const [whole, setWhole] = useState(true);
    const [qty, setQty] = useState<Record<string, number>>({});
    const [complete, setComplete] = useState(canComplete);
    const [notify, setNotify] = useState(true);
    const [error, setError] = useState("");

    useEffect(() => {
        if (!open) {
            return;
        }
        setNumber("");
        setDate(today());
        setWhole(true);
        setQty(Object.fromEntries(lines.map((item) => [String(item.id), item.quantity - item.refunded_qty])));
        setComplete(canComplete);
        setNotify(true);
        setError("");
    }, [open, lines, canComplete]);

    // Start on the carrier used last, once the list is in.
    const last = carriers.data?.last ?? null;
    useEffect(() => {
        if (!open || carriers.data === undefined) {
            return;
        }
        const known = last !== null && carriers.data.carriers.some((c) => c.key === last.carrier);
        setCarrier(known && last !== null ? last.carrier : (carriers.data.carriers[0]?.key ?? OTHER));
        setCarrierName(last !== null && last.carrier === "" ? last.carrier_name : "");
        setTrackingUrl("");
    }, [open, carriers.data, last]);

    const options = [
        ...(carriers.data?.carriers ?? []).map((c) => ({ value: c.key, label: c.name })),
        { value: OTHER, label: __("Other carrier") },
    ];
    const items = whole ? null : Object.fromEntries(Object.entries(qty).filter(([, n]) => n > 0));
    const valid = (carrier !== OTHER || carrierName.trim() !== "") && (items === null || Object.keys(items).length > 0);

    const submit = () =>
        add.mutate(
            {
                carrier,
                carrier_name: carrier === OTHER ? carrierName : "",
                tracking_number: number,
                tracking_url: carrier === OTHER ? trackingUrl : "",
                items,
                shipped_at: date,
                complete: canComplete && complete,
                notify,
            },
            {
                onSuccess: () => {
                    void qc.invalidateQueries({ queryKey: ["shipment-carriers"] });
                    onOpenChange(false);
                    showToast.success(notify ? __("Tracking added and the customer was emailed.") : __("Tracking added."));
                },
                onError: (e) => setError(e.message),
            },
        );

    return (
        <Dialog open={open} onOpenChange={(next) => !add.isPending && onOpenChange(next)}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{__("Add tracking")}</DialogTitle>
                    <DialogDescription>{__("The customer sees the tracking on their order page and in the email.")}</DialogDescription>
                </DialogHeader>
                <div className="fxc:space-y-4">
                    <div className="fxc:grid fxc:gap-4 fxc:sm:grid-cols-2">
                        <div>
                            <Label htmlFor="fxc-shipment-carrier" className="fxc:mb-2 fxc:block">
                                {__("Carrier")}
                            </Label>
                            <Select
                                id="fxc-shipment-carrier"
                                className="fxc:w-full"
                                value={carrier}
                                options={options}
                                onChange={(e) => setCarrier(e.target.value)}
                            />
                        </div>
                        <div>
                            <Label htmlFor="fxc-shipment-number" className="fxc:mb-2 fxc:block">
                                {__("Tracking number")}
                            </Label>
                            <Input id="fxc-shipment-number" value={number} autoComplete="off" onChange={(e) => setNumber(e.target.value)} />
                        </div>
                    </div>
                    {carrier === OTHER && (
                        <div className="fxc:grid fxc:gap-4 fxc:sm:grid-cols-2">
                            <div>
                                <Label htmlFor="fxc-shipment-name" className="fxc:mb-2 fxc:block">
                                    {__("Carrier name")}
                                </Label>
                                <Input id="fxc-shipment-name" value={carrierName} onChange={(e) => setCarrierName(e.target.value)} />
                            </div>
                            <div>
                                <Label htmlFor="fxc-shipment-url" className="fxc:mb-2 fxc:block">
                                    {__("Tracking link (optional)")}
                                </Label>
                                <Input
                                    id="fxc-shipment-url"
                                    type="url"
                                    placeholder="https://"
                                    aria-describedby="fxc-shipment-url-help"
                                    value={trackingUrl}
                                    onChange={(e) => setTrackingUrl(e.target.value)}
                                />
                                <p id="fxc-shipment-url-help" className="fxc:text-muted-foreground fxc:mt-1 fxc:text-xs">
                                    {__("Put {number} where the tracking number goes.")}
                                </p>
                            </div>
                        </div>
                    )}
                    <div>
                        <Label htmlFor="fxc-shipment-date" className="fxc:mb-2 fxc:block">
                            {__("Shipped on")}
                        </Label>
                        <Input id="fxc-shipment-date" type="date" max={today()} value={date} onChange={(e) => setDate(e.target.value)} />
                    </div>
                    <fieldset className="fxc:space-y-2">
                        <legend className="fxc:text-foreground fxc:mb-2 fxc:text-sm fxc:font-medium">{__("In this package")}</legend>
                        <div className="fxc:flex fxc:items-center fxc:gap-2">
                            <input
                                id="fxc-shipment-whole"
                                type="radio"
                                name="fxc-shipment-scope"
                                className="flexacommerce-check"
                                checked={whole}
                                onChange={() => setWhole(true)}
                            />
                            <label htmlFor="fxc-shipment-whole" className="fxc:text-foreground fxc:text-sm">
                                {__("The whole order")}
                            </label>
                        </div>
                        <div className="fxc:flex fxc:items-center fxc:gap-2">
                            <input
                                id="fxc-shipment-part"
                                type="radio"
                                name="fxc-shipment-scope"
                                className="flexacommerce-check"
                                checked={!whole}
                                onChange={() => setWhole(false)}
                            />
                            <label htmlFor="fxc-shipment-part" className="fxc:text-foreground fxc:text-sm">
                                {__("Some products")}
                            </label>
                        </div>
                        {!whole && (
                            <ul className="fxc:space-y-2 fxc:ps-6">
                                {lines.map((item) => {
                                    const max = item.quantity - item.refunded_qty;
                                    const key = String(item.id);
                                    return (
                                        <li key={item.id} className="fxc:flex fxc:items-center fxc:gap-3">
                                            <Input
                                                type="number"
                                                min={0}
                                                max={max}
                                                className="fxc:w-20"
                                                aria-label={sprintf(__("Quantity of %s in the package"), item.name)}
                                                value={qty[key] ?? 0}
                                                onChange={(e) =>
                                                    setQty({ ...qty, [key]: Math.max(0, Math.min(max, Math.trunc(Number(e.target.value) || 0))) })
                                                }
                                            />
                                            <span className="fxc:text-foreground fxc:text-sm">
                                                {item.name} <span className="fxc:text-muted-foreground">{sprintf(__("of %d"), max)}</span>
                                            </span>
                                        </li>
                                    );
                                })}
                            </ul>
                        )}
                    </fieldset>
                    {canComplete && (
                        <div className="fxc:flex fxc:items-start fxc:gap-2">
                            <input
                                id="fxc-shipment-complete"
                                type="checkbox"
                                className="flexacommerce-check fxc:mt-0.5"
                                checked={complete}
                                onChange={(e) => setComplete(e.target.checked)}
                            />
                            <label htmlFor="fxc-shipment-complete" className="fxc:text-foreground fxc:text-sm">
                                {__("Mark the order as completed")}
                            </label>
                        </div>
                    )}
                    <div className="fxc:flex fxc:items-start fxc:gap-2">
                        <input
                            id="fxc-shipment-notify"
                            type="checkbox"
                            className="flexacommerce-check fxc:mt-0.5"
                            checked={notify}
                            onChange={(e) => setNotify(e.target.checked)}
                        />
                        <label htmlFor="fxc-shipment-notify" className="fxc:text-foreground fxc:text-sm">
                            {__("Email the customer the tracking")}
                        </label>
                    </div>
                    {error !== "" && (
                        <p role="alert" className="fxc:text-destructive fxc:text-sm">
                            {error}
                        </p>
                    )}
                </div>
                <DialogFooter>
                    <Button variant="ghost" onClick={() => onOpenChange(false)} disabled={add.isPending}>
                        {__("Cancel")}
                    </Button>
                    <Button onClick={submit} disabled={!valid || add.isPending || carriers.isPending}>
                        {add.isPending ? __("Saving…") : __("Add tracking")}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
