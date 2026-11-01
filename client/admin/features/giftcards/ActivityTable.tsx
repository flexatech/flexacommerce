import { getPluginGlobal } from "@/shared/lib/api";
import { __, sprintf } from "@/shared/lib/i18n";
import { formatDate, orderMoney } from "../orders/format";
import type { GiftCardActivityRow } from "./useGiftCards";

export const ACTIVITY_LABEL: Record<GiftCardActivityRow["type"], string> = {
    issued: __("Issued"),
    redeemed: __("Paid an order"),
    credited: __("Refunded to the card"),
    adjusted: __("Adjusted"),
};

export function orderUrl(id: number): string {
    return `${getPluginGlobal().adminUrl}?page=flexacommerce-orders&order=${id}`;
}

/** The balance history of a gift card or a store credit account, newest first. */
export function ActivityTable({ rows, currency, labels = ACTIVITY_LABEL }: { rows: GiftCardActivityRow[]; currency: string; labels?: Record<GiftCardActivityRow["type"], string> }) {
    return (
        <table className="fxc:w-full fxc:text-sm">
            <thead>
                <tr className="fxc:text-muted-foreground fxc:border-b fxc:text-left fxc:text-xs">
                    <th className="fxc:py-2 fxc:pr-3 fxc:font-medium">{__("Date")}</th>
                    <th className="fxc:py-2 fxc:pr-3 fxc:font-medium">{__("What")}</th>
                    <th className="fxc:py-2 fxc:pr-3 fxc:text-right fxc:font-medium">{__("Amount")}</th>
                    <th className="fxc:py-2 fxc:text-right fxc:font-medium">{__("Balance")}</th>
                </tr>
            </thead>
            <tbody>
                {rows.map((row) => (
                    <tr key={row.id} className="fxc:border-b fxc:align-top fxc:last:border-b-0">
                        <td className="fxc:text-muted-foreground fxc:py-2 fxc:pr-3 fxc:whitespace-nowrap">{formatDate(row.created_at)}</td>
                        <td className="fxc:py-2 fxc:pr-3">
                            <div>
                                {labels[row.type]}
                                {row.order_id !== null && (
                                    <>
                                        {" "}
                                        <a href={orderUrl(row.order_id)} className="fxc:text-primary fxc:no-underline fxc:hover:underline">
                                            {sprintf(__("order #%d"), row.order_id)}
                                        </a>
                                    </>
                                )}
                            </div>
                            {(row.note !== "" || row.user) && (
                                <div className="fxc:text-muted-foreground fxc:text-xs">{[row.note, row.user ?? ""].filter((part) => part !== "").join(" · ")}</div>
                            )}
                        </td>
                        <td className="fxc:py-2 fxc:pr-3 fxc:text-right fxc:tabular-nums fxc:whitespace-nowrap">
                            {(row.amount > 0 ? "+" : row.amount < 0 ? "−" : "") + orderMoney(Math.abs(row.amount), currency)}
                        </td>
                        <td className="fxc:py-2 fxc:text-right fxc:tabular-nums fxc:whitespace-nowrap">{orderMoney(row.balance_after, currency)}</td>
                    </tr>
                ))}
            </tbody>
        </table>
    );
}
