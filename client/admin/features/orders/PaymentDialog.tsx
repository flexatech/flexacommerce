import { useEffect, useState } from "react";

import { showToast } from "@/shared/components/showToast";
import { Button } from "@/shared/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/shared/components/ui/dialog";
import { Input } from "@/shared/components/ui/input";
import { Label } from "@/shared/components/ui/label";
import { __, sprintf } from "@/shared/lib/i18n";
import { currencyExponent, orderMoney } from "./format";
import { MoneyInput } from "../../components/MoneyInput";
import type { OrderDetail } from "./types";
import { useRecordPayment } from "./useOrders";

interface Props {
    order: OrderDetail;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}

/** Money received outside the site: cash on delivery, a bank transfer. */
export function PaymentDialog({ order, open, onOpenChange }: Props) {
    const record = useRecordPayment(order.id);
    const [amount, setAmount] = useState(order.balance_due);
    const [note, setNote] = useState("");
    const [error, setError] = useState("");

    useEffect(() => {
        if (open) {
            setAmount(order.balance_due);
            setNote("");
            setError("");
        }
    }, [open, order.balance_due]);

    const valid = Number.isFinite(amount) && amount > 0 && amount <= order.balance_due;

    const submit = () =>
        record.mutate(
            { amount, note },
            {
                onSuccess: () => {
                    onOpenChange(false);
                    showToast.success(__("Payment recorded."));
                },
                onError: (e) => setError(e.message),
            },
        );

    return (
        <Dialog open={open} onOpenChange={(next) => !record.isPending && onOpenChange(next)}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{__("Record a payment")}</DialogTitle>
                    <DialogDescription>
                        {sprintf(__("%s is still due on this order."), orderMoney(order.balance_due, order.currency))}
                    </DialogDescription>
                </DialogHeader>
                <div className="fxc:space-y-4">
                    <div>
                        <Label htmlFor="fxc-payment-amount" className="fxc:mb-2 fxc:block">
                            {__("Amount received")}
                        </Label>
                        <MoneyInput id="fxc-payment-amount" value={amount} exponent={currencyExponent(order.currency)} onChange={setAmount} />
                    </div>
                    <div>
                        <Label htmlFor="fxc-payment-note" className="fxc:mb-2 fxc:block">
                            {__("Note (optional)")}
                        </Label>
                        <Input
                            id="fxc-payment-note"
                            placeholder={__("For example: bank reference")}
                            value={note}
                            onChange={(e) => setNote(e.target.value)}
                        />
                    </div>
                    {error !== "" && (
                        <p role="alert" className="fxc:text-destructive fxc:text-sm">
                            {error}
                        </p>
                    )}
                </div>
                <DialogFooter>
                    <Button variant="ghost" onClick={() => onOpenChange(false)} disabled={record.isPending}>
                        {__("Cancel")}
                    </Button>
                    <Button onClick={submit} disabled={!valid || record.isPending}>
                        {record.isPending ? __("Saving…") : __("Record payment")}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
