import { useEffect, useState } from "react";

import { showToast } from "@/shared/components/showToast";
import { Button } from "@/shared/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/shared/components/ui/dialog";
import { Input } from "@/shared/components/ui/input";
import { Select } from "@/shared/components/ui/select";
import { __, sprintf } from "@/shared/lib/i18n";
import { inputToMinor } from "@/shared/lib/units";
import { ACTIVITY_LABEL, ActivityTable } from "../giftcards/ActivityTable";
import { currencyExponent, orderMoney } from "../orders/format";
import { useAdjustCredit, useCustomerCredit, type Customer } from "./useCustomers";

const CREDIT_LABEL = {
    ...ACTIVITY_LABEL,
    redeemed: __("Paid an order"),
    credited: __("Refund"),
    adjusted: __("Changed by the store"),
};

/**
 * A customer's store credit (ADR-072): the balance, a change with the
 * reason the customer sees, and the history.
 */
export function CreditDialog({ customer, onClose }: { customer: Customer | null; onClose: () => void }) {
    const id = customer?.customer_id ?? null;
    const query = useCustomerCredit(id);
    const adjust = useAdjustCredit(id ?? 0);
    const [direction, setDirection] = useState<"add" | "take">("add");
    const [change, setChange] = useState("");
    const [note, setNote] = useState("");
    const [error, setError] = useState("");
    const credit = query.data && query.data.customer_id === id ? query.data : undefined;

    useEffect(() => {
        setDirection("add");
        setChange("");
        setNote("");
        setError("");
    }, [id]);

    const apply = () => {
        if (!credit) {
            return;
        }
        const minor = inputToMinor(change, currencyExponent(credit.currency));
        if (minor === null || Number.isNaN(minor) || minor <= 0) {
            setError(__("Enter an amount."));
            return;
        }
        if (note.trim() === "") {
            setError(__("Enter a reason. The customer sees it in their account."));
            return;
        }
        setError("");
        adjust.mutate(
            { amount: direction === "add" ? minor : -minor, note: note.trim() },
            {
                onSuccess: () => {
                    setChange("");
                    setNote("");
                    showToast.success(__("Store credit changed."));
                },
                onError: (failure) => setError(failure.message),
            },
        );
    };

    const name = customer ? customer.name || customer.email : "";

    return (
        <Dialog open={customer !== null} onOpenChange={(next) => !next && !adjust.isPending && onClose()}>
            <DialogContent className="fxc:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>{sprintf(__("Store credit: %s"), name)}</DialogTitle>
                    <DialogDescription>
                        {credit
                            ? sprintf(__("Balance: %s. The customer can spend it at checkout, and it never expires."), orderMoney(credit.balance, credit.currency))
                            : __("Loading…")}
                    </DialogDescription>
                </DialogHeader>

                {query.isError && <p className="fxc:text-destructive fxc:text-sm">{query.error.message}</p>}

                {credit && (
                    <div className="fxc:max-h-[60vh] fxc:space-y-5 fxc:overflow-y-auto">
                        <div className="fxc:rounded-md fxc:border fxc:p-4">
                            <h3 className="fxc:mb-3 fxc:text-sm fxc:font-semibold">{__("Change the balance")}</h3>
                            <div className="fxc:flex fxc:flex-wrap fxc:items-end fxc:gap-3">
                                <Select
                                    aria-label={__("Add or take off")}
                                    value={direction}
                                    onChange={(e) => setDirection(e.target.value === "take" ? "take" : "add")}
                                    options={[
                                        { value: "add", label: __("Add") },
                                        { value: "take", label: __("Take off") },
                                    ]}
                                />
                                <Input aria-label={__("Amount")} inputMode="decimal" className="fxc:w-32" value={change} onChange={(e) => setChange(e.target.value)} />
                                <Input
                                    aria-label={__("Reason")}
                                    placeholder={__("Reason, shown to the customer")}
                                    maxLength={500}
                                    className="fxc:min-w-48 fxc:flex-1"
                                    value={note}
                                    onChange={(e) => setNote(e.target.value)}
                                />
                                <Button variant="outline" disabled={adjust.isPending} onClick={apply}>
                                    {adjust.isPending ? __("Saving…") : __("Apply")}
                                </Button>
                            </div>
                            {error !== "" && (
                                <p role="alert" className="fxc:text-destructive fxc:mt-2 fxc:text-sm">
                                    {error}
                                </p>
                            )}
                        </div>

                        <div>
                            <h3 className="fxc:mb-2 fxc:text-sm fxc:font-semibold">{__("History")}</h3>
                            {credit.activity.length === 0 ? (
                                <p className="fxc:text-muted-foreground fxc:text-sm">{__("No store credit yet. Refunds paid to store credit and changes you make show here.")}</p>
                            ) : (
                                <ActivityTable rows={credit.activity} currency={credit.currency} labels={CREDIT_LABEL} />
                            )}
                        </div>
                    </div>
                )}

                <DialogFooter>
                    <Button variant="outline" onClick={onClose} disabled={adjust.isPending}>
                        {__("Close")}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
