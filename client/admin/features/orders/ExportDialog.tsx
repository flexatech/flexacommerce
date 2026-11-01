import { useState } from "react";
import { Download } from "lucide-react";

import { showToast } from "@/shared/components/showToast";
import { Button } from "@/shared/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/shared/components/ui/dialog";
import { cn } from "@/shared/lib/cn";
import { __, sprintf } from "@/shared/lib/i18n";
import type { OrderExportLayout, OrderListParams } from "./types";
import { useOrderExport } from "./useOrders";

interface Props {
    params: OrderListParams;
    /** Whether a tab, search, date or customer narrows the list. */
    filtered: boolean;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}

/** Saves the orders the list shows as a CSV file (ADR-070). */
export function ExportDialog({ params, filtered, open, onOpenChange }: Props) {
    const [layout, setLayout] = useState<OrderExportLayout>("orders");
    const run = useOrderExport();

    const layouts: { value: OrderExportLayout; label: string; help: string }[] = [
        {
            value: "orders",
            label: __("One row per order"),
            help: __("Customer, addresses, payment and shipping method, coupons, totals, refunds and tracking numbers."),
        },
        {
            value: "items",
            label: __("One row per product"),
            help: __("Each product an order bought, with its SKU, options, quantity, price, tax and refunds."),
        },
    ];

    const start = () =>
        run.mutate(
            { params, layout },
            {
                onSuccess: ({ rows, orders }) => {
                    onOpenChange(false);
                    showToast.success(orders === 0 ? __("No orders match, so the file has only its header.") : sprintf(__("Exported %1$d rows from %2$d orders."), rows, orders));
                },
                onError: (e) => showToast.error(e.message),
            },
        );

    return (
        <Dialog open={open} onOpenChange={(next) => !run.isPending && onOpenChange(next)}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{__("Export orders")}</DialogTitle>
                    <DialogDescription>
                        {filtered
                            ? __("The file has the orders that match the tab, search and dates you chose in the list.")
                            : __("The file has every order. Choose a tab, search or dates in the list first to export fewer.")}
                    </DialogDescription>
                </DialogHeader>
                <fieldset className="fxc:space-y-2">
                    <legend className="fxc:mb-2 fxc:text-sm fxc:font-medium">{__("Rows")}</legend>
                    {layouts.map((option) => (
                        <label
                            key={option.value}
                            className={cn(
                                "fxc:flex fxc:cursor-pointer fxc:items-start fxc:gap-2 fxc:rounded-md fxc:border fxc:px-3 fxc:py-2 fxc:text-sm",
                                layout === option.value && "fxc:border-primary fxc:bg-muted/50",
                            )}
                        >
                            <input
                                type="radio"
                                name="fxc-export-layout"
                                checked={layout === option.value}
                                onChange={() => setLayout(option.value)}
                                disabled={run.isPending}
                                className="flexacommerce-check fxc:mt-0.5"
                            />
                            <span>
                                <span className="fxc:block">{option.label}</span>
                                <span className="fxc:text-muted-foreground fxc:block fxc:text-xs">{option.help}</span>
                            </span>
                        </label>
                    ))}
                </fieldset>
                <p className="fxc:text-muted-foreground fxc:text-xs">{__("Amounts are plain numbers in the order's currency and dates are in the store's time zone, so the file opens in any spreadsheet.")}</p>
                <DialogFooter className="fxc:items-center">
                    {run.progress && (
                        <span className="fxc:text-muted-foreground fxc:mr-auto fxc:text-sm" role="status">
                            {sprintf(__("%1$d of %2$d orders"), run.progress.done, run.progress.total)}
                        </span>
                    )}
                    <Button variant="ghost" onClick={() => onOpenChange(false)} disabled={run.isPending}>
                        {__("Cancel")}
                    </Button>
                    <Button onClick={start} disabled={run.isPending}>
                        <Download aria-hidden className="fxc:size-4" />
                        {run.isPending ? __("Exporting…") : __("Download CSV")}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
