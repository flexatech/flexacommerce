import { useEffect, useState } from "react";

import { showToast } from "@/shared/components/showToast";
import { Button } from "@/shared/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/shared/components/ui/dialog";
import { ApiError } from "@/shared/lib/api";
import { __ } from "@/shared/lib/i18n";
import { useConditionChoices } from "../conditions/useConditions";
import { AddressFields } from "./OrderEditor";
import type { OrderDetail } from "./types";
import { EMPTY_ADDRESS, useUpdateAddresses } from "./useOrderEditor";

interface Props {
    order: OrderDetail;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}

/** Addresses can change on any order, paid or not; the totals stay as they are (ADR-061). */
export function AddressesDialog({ order, open, onOpenChange }: Props) {
    const update = useUpdateAddresses(order.id);
    const choices = useConditionChoices();
    const countries = (choices.data?.countries ?? []).map((c) => ({ value: c.id, label: c.title }));
    const [billing, setBilling] = useState(EMPTY_ADDRESS);
    const [shipTo, setShipTo] = useState(false);
    const [shipping, setShipping] = useState(EMPTY_ADDRESS);
    const [errors, setErrors] = useState<Record<string, string>>({});

    useEffect(() => {
        if (open) {
            setBilling({ ...EMPTY_ADDRESS, ...(order.billing ?? {}) });
            setShipTo(order.shipping !== null);
            setShipping({ ...EMPTY_ADDRESS, ...(order.shipping ?? {}) });
            setErrors({});
        }
    }, [open, order.billing, order.shipping]);

    const submit = () =>
        update.mutate(
            { billing, shipping: shipTo ? shipping : null },
            {
                onSuccess: () => {
                    onOpenChange(false);
                    showToast.success(__("Addresses saved."));
                },
                onError: (e) => {
                    const field = e instanceof ApiError && typeof e.data.field === "string" ? e.data.field : "form";
                    setErrors({ [field]: e.message });
                },
            },
        );

    return (
        <Dialog open={open} onOpenChange={(next) => !update.isPending && onOpenChange(next)}>
            <DialogContent className="fxc:max-h-[90vh] fxc:overflow-y-auto fxc:sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>{__("Edit addresses")}</DialogTitle>
                    <DialogDescription>{__("Prices, taxes and shipping stay as they are. Edit the order to work them out again.")}</DialogDescription>
                </DialogHeader>
                <div className="fxc:space-y-5">
                    <AddressFields prefix="billing" title={__("Billing address")} value={billing} onChange={setBilling} countries={countries} errors={errors} email />
                    <label className="fxc:flex fxc:items-center fxc:gap-2 fxc:text-sm">
                        <input type="checkbox" className="flexacommerce-check" checked={shipTo} onChange={(e) => setShipTo(e.target.checked)} />
                        {__("Ship to a different address")}
                    </label>
                    {shipTo && (
                        <AddressFields prefix="shipping" title={__("Shipping address")} value={shipping} onChange={setShipping} countries={countries} errors={errors} />
                    )}
                    {errors.form !== undefined && (
                        <p role="alert" className="fxc:text-destructive fxc:text-sm">
                            {errors.form}
                        </p>
                    )}
                </div>
                <DialogFooter>
                    <Button variant="outline" onClick={() => onOpenChange(false)} disabled={update.isPending}>
                        {__("Cancel")}
                    </Button>
                    <Button onClick={submit} disabled={update.isPending}>
                        {update.isPending ? __("Saving…") : __("Save addresses")}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
