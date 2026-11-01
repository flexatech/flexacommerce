import { useEffect, useState } from "react";
import { Search } from "lucide-react";

import { showToast } from "@/shared/components/showToast";
import { Badge } from "@/shared/components/ui/badge";
import { Button } from "@/shared/components/ui/button";
import { Card } from "@/shared/components/ui/card";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/shared/components/ui/dialog";
import { Input } from "@/shared/components/ui/input";
import { Label } from "@/shared/components/ui/label";
import { getPluginGlobal } from "@/shared/lib/api";
import { __, sprintf } from "@/shared/lib/i18n";
import { PageTitle } from "../../components/PageTitle";
import { Tabs } from "../../components/Tabs";
import { formatDate, orderMoney } from "../orders/format";
import {
    useReschedule,
    useSubscription,
    useSubscriptionAction,
    useSubscriptionList,
    type SubscriptionAction,
    type SubscriptionAddress,
    type SubscriptionDetail,
    type SubscriptionStatus,
} from "./useSubscriptions";

const STATUS_LABEL: Record<SubscriptionStatus, string> = {
    pending: __("Pending"),
    active: __("Active"),
    "on-hold": __("On hold"),
    "pending-cancel": __("Ends soon"),
    cancelled: __("Cancelled"),
    expired: __("Expired"),
};

const STATUS_VARIANT: Record<SubscriptionStatus, "success" | "muted" | "destructive" | "brand"> = {
    pending: "muted",
    active: "success",
    "on-hold": "brand",
    "pending-cancel": "brand",
    cancelled: "destructive",
    expired: "muted",
};

const FILTERS: (SubscriptionStatus | "")[] = ["", "active", "on-hold", "pending-cancel", "pending", "cancelled", "expired"];

const DONE: Record<SubscriptionAction, string> = {
    cancel: __("Subscription cancelled."),
    cancel_end: __("The subscription ends after the period that is paid for."),
    reactivate: __("Subscription reactivated."),
    hold: __("Subscription put on hold. No renewals are made until you resume it."),
    resume: __("Subscription resumed."),
    renew: __("Renewal order made."),
};

function orderUrl(id: number): string {
    return `${getPluginGlobal().adminUrl}?page=flexacommerce-orders&order=${id}`;
}

/** `?subscription=` opens one, so the cancel email can link straight to it. */
function readOpen(): number | null {
    const id = Number.parseInt(new URLSearchParams(window.location.search).get("subscription") ?? "", 10);
    return Number.isFinite(id) && id > 0 ? id : null;
}

function writeOpen(id: number | null): void {
    const url = new URL(window.location.href);
    if (id === null) {
        url.searchParams.delete("subscription");
    } else {
        url.searchParams.set("subscription", String(id));
    }
    window.history.replaceState(null, "", url.toString());
}

function tomorrow(): string {
    const next = new Date();
    next.setDate(next.getDate() + 1);
    return `${next.getFullYear()}-${String(next.getMonth() + 1).padStart(2, "0")}-${String(next.getDate()).padStart(2, "0")}`;
}

function Address({ title, address }: { title: string; address: SubscriptionAddress | null }) {
    if (address === null) {
        return null;
    }
    const lines = [
        [address.first_name, address.last_name].filter(Boolean).join(" "),
        address.company,
        address.address_1,
        address.address_2,
        [address.postcode, address.city, address.state].filter(Boolean).join(" "),
        address.country,
        address.email,
        address.phone,
    ].filter((line): line is string => typeof line === "string" && line !== "");
    if (lines.length === 0) {
        return null;
    }
    return (
        <div>
            <h3 className="fxc:mb-1 fxc:text-sm fxc:font-semibold">{title}</h3>
            <p className="fxc:text-muted-foreground fxc:text-sm">
                {lines.map((line, i) => (
                    <span key={i} className="fxc:block">
                        {line}
                    </span>
                ))}
            </p>
        </div>
    );
}

function Term({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div>
            <dt className="fxc:text-muted-foreground fxc:text-xs">{label}</dt>
            <dd className="fxc:m-0 fxc:text-sm">{children}</dd>
        </div>
    );
}

function SubscriptionDialog({ id, onClose }: { id: number | null; onClose: () => void }) {
    const query = useSubscription(id);
    const row = query.data;
    return (
        <Dialog open={id !== null} onOpenChange={(next) => !next && onClose()}>
            <DialogContent className="fxc:max-w-2xl">
                {query.isError ? (
                    <>
                        <DialogTitle className="fxc:sr-only">{__("Subscription")}</DialogTitle>
                        <p className="fxc:text-destructive">{query.error.message}</p>
                    </>
                ) : !row || row.id !== id ? (
                    <>
                        <DialogTitle className="fxc:sr-only">{__("Subscription")}</DialogTitle>
                        <p className="fxc:text-muted-foreground">{__("Loading…")}</p>
                    </>
                ) : (
                    <SubscriptionView key={row.id} row={row} onClose={onClose} />
                )}
            </DialogContent>
        </Dialog>
    );
}

function SubscriptionView({ row, onClose }: { row: SubscriptionDetail; onClose: () => void }) {
    const action = useSubscriptionAction(row.id);
    const reschedule = useReschedule(row.id);
    const [day, setDay] = useState(row.next_payment_on);
    const [confirm, setConfirm] = useState(false);
    const running = row.status === "active" || row.status === "on-hold";
    const busy = action.isPending || reschedule.isPending;

    useEffect(() => setDay(row.next_payment_on), [row.next_payment_on]);

    const run = (what: SubscriptionAction) =>
        action.mutate(what, {
            onSuccess: () => {
                setConfirm(false);
                showToast.success(DONE[what]);
            },
            onError: (failure) => showToast.error(failure.message),
        });

    const plan = row.plan;
    const ended = row.status === "cancelled" || row.status === "expired";

    return (
        <>
            <DialogHeader>
                <DialogTitle className="fxc:flex fxc:flex-wrap fxc:items-center fxc:gap-2">
                    <span>{sprintf(__("Subscription #%d"), row.id)}</span>
                    <Badge variant={STATUS_VARIANT[row.status]}>{STATUS_LABEL[row.status]}</Badge>
                </DialogTitle>
                <DialogDescription>
                    {row.title} · {row.price}
                </DialogDescription>
            </DialogHeader>

            <div className="fxc:max-h-[60vh] fxc:space-y-5 fxc:overflow-y-auto">
                <dl className="fxc:m-0 fxc:grid fxc:gap-3 fxc:sm:grid-cols-3">
                    <Term label={__("Customer")}>
                        <span className="fxc:block">{row.customer_name || row.customer_email}</span>
                        {row.customer_name !== "" && <span className="fxc:text-muted-foreground fxc:block fxc:text-xs">{row.customer_email}</span>}
                    </Term>
                    <Term label={__("Started")}>{formatDate(row.start_at, false) || "—"}</Term>
                    <Term label={__("Payments")}>
                        {plan.length > 0 ? sprintf(__("%1$d of %2$d"), row.billed, plan.length) : sprintf(__("%d so far"), row.billed)}
                    </Term>
                    {row.trial_end_at !== null && <Term label={__("Trial ends")}>{formatDate(row.trial_end_at, false)}</Term>}
                    <Term label={__("Next payment")}>{row.next_payment_at !== null ? formatDate(row.next_payment_at) : "—"}</Term>
                    {row.end_at !== null && <Term label={ended ? __("Ended") : __("Ends")}>{formatDate(row.end_at, false)}</Term>}
                    <Term label={__("Payment method")}>{row.payment_method_title || row.payment_method || "—"}</Term>
                    {row.parent_order_id !== null && (
                        <Term label={__("First order")}>
                            <a href={orderUrl(row.parent_order_id)} className="fxc:text-primary fxc:no-underline fxc:hover:underline">
                                {sprintf(__("#%d"), row.parent_order_id)}
                            </a>
                        </Term>
                    )}
                </dl>

                {row.unpaid_order_id !== null && (
                    <p className="fxc:bg-muted fxc:rounded-md fxc:px-3 fxc:py-2 fxc:text-sm">
                        {__("A renewal is waiting for payment.")}{" "}
                        <a href={orderUrl(row.unpaid_order_id)} className="fxc:text-primary fxc:no-underline fxc:hover:underline">
                            {sprintf(__("Open order #%d"), row.unpaid_order_id)}
                        </a>
                    </p>
                )}

                {row.items.length > 0 && (
                    <div>
                        <h3 className="fxc:mb-2 fxc:text-sm fxc:font-semibold">{__("Each renewal")}</h3>
                        <table className="fxc:w-full fxc:text-sm">
                            <tbody>
                                {row.items.map((item, i) => (
                                    <tr key={i} className="fxc:border-b">
                                        <td className="fxc:py-2 fxc:pr-3">{sprintf(__("%1$s × %2$d"), item.name, item.quantity)}</td>
                                        <td className="fxc:py-2 fxc:text-right fxc:tabular-nums">{orderMoney(item.total, row.currency)}</td>
                                    </tr>
                                ))}
                                {row.shipping_total > 0 && (
                                    <tr className="fxc:border-b">
                                        <td className="fxc:text-muted-foreground fxc:py-2 fxc:pr-3">{__("Shipping")}</td>
                                        <td className="fxc:py-2 fxc:text-right fxc:tabular-nums">{orderMoney(row.shipping_total, row.currency)}</td>
                                    </tr>
                                )}
                                {row.tax_total > 0 && (
                                    <tr className="fxc:border-b">
                                        <td className="fxc:text-muted-foreground fxc:py-2 fxc:pr-3">{__("Tax")}</td>
                                        <td className="fxc:py-2 fxc:text-right fxc:tabular-nums">{orderMoney(row.tax_total, row.currency)}</td>
                                    </tr>
                                )}
                                <tr>
                                    <td className="fxc:py-2 fxc:pr-3 fxc:font-semibold">{__("Total")}</td>
                                    <td className="fxc:py-2 fxc:text-right fxc:font-semibold fxc:tabular-nums">{orderMoney(row.total, row.currency)}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                )}

                {running && row.next_payment_at !== null && (
                    <div className="fxc:rounded-md fxc:border fxc:p-4">
                        <Label htmlFor="fxc-sub-next" className="fxc:mb-2 fxc:block">
                            {__("Move the next payment")}
                        </Label>
                        <div className="fxc:flex fxc:flex-wrap fxc:items-center fxc:gap-3">
                            <Input id="fxc-sub-next" type="date" min={tomorrow()} className="fxc:w-48" value={day} onChange={(e) => setDay(e.target.value)} />
                            <Button
                                variant="outline"
                                disabled={busy || day === "" || day === row.next_payment_on}
                                onClick={() =>
                                    reschedule.mutate(day, {
                                        onSuccess: () => showToast.success(__("Next payment moved. The payments after it follow the new day.")),
                                        onError: (failure) => showToast.error(failure.message),
                                    })
                                }
                            >
                                {reschedule.isPending ? __("Saving…") : __("Save date")}
                            </Button>
                        </div>
                        <p className="fxc:text-muted-foreground fxc:mt-2 fxc:text-xs">{__("The time of day stays the same. The customer is not charged until then.")}</p>
                    </div>
                )}

                {!ended && (
                    <div className="fxc:flex fxc:flex-wrap fxc:gap-2">
                        {running && row.next_payment_at !== null && (
                            <Button variant="outline" size="sm" disabled={busy} onClick={() => run("renew")}>
                                {__("Make the renewal order now")}
                            </Button>
                        )}
                        {row.status === "active" && (
                            <Button variant="outline" size="sm" disabled={busy} onClick={() => run("hold")}>
                                {__("Put on hold")}
                            </Button>
                        )}
                        {row.status === "on-hold" && (
                            <Button variant="outline" size="sm" disabled={busy} onClick={() => run("resume")}>
                                {__("Resume")}
                            </Button>
                        )}
                        {row.status === "pending-cancel" && (
                            <Button variant="outline" size="sm" disabled={busy} onClick={() => run("reactivate")}>
                                {__("Reactivate")}
                            </Button>
                        )}
                        {row.status === "active" && row.next_payment_at !== null && (
                            <Button variant="outline" size="sm" disabled={busy} onClick={() => run("cancel_end")}>
                                {__("Cancel at the end of the period")}
                            </Button>
                        )}
                        {confirm ? (
                            <span className="fxc:flex fxc:items-center fxc:gap-2">
                                <span className="fxc:text-sm">{__("Cancel now? Unpaid renewals are cancelled too.")}</span>
                                <Button variant="destructive" size="sm" disabled={busy} onClick={() => run("cancel")}>
                                    {__("Yes, cancel")}
                                </Button>
                                <Button variant="ghost" size="sm" disabled={busy} onClick={() => setConfirm(false)}>
                                    {__("Keep it")}
                                </Button>
                            </span>
                        ) : (
                            <Button variant="outline" size="sm" disabled={busy} onClick={() => setConfirm(true)}>
                                {__("Cancel now")}
                            </Button>
                        )}
                    </div>
                )}

                {row.orders.length > 0 && (
                    <div>
                        <h3 className="fxc:mb-2 fxc:text-sm fxc:font-semibold">{__("Orders")}</h3>
                        <table className="fxc:w-full fxc:text-sm">
                            <thead>
                                <tr className="fxc:text-muted-foreground fxc:border-b fxc:text-left fxc:text-xs">
                                    <th className="fxc:py-2 fxc:pr-3 fxc:font-medium">{__("Order")}</th>
                                    <th className="fxc:py-2 fxc:pr-3 fxc:font-medium">{__("Date")}</th>
                                    <th className="fxc:py-2 fxc:pr-3 fxc:font-medium">{__("Status")}</th>
                                    <th className="fxc:py-2 fxc:text-right fxc:font-medium">{__("Total")}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {row.orders.map((order) => (
                                    <tr key={order.id} className="fxc:border-b fxc:last:border-b-0">
                                        <td className="fxc:py-2 fxc:pr-3">
                                            <a href={orderUrl(order.id)} className="fxc:text-primary fxc:no-underline fxc:hover:underline">
                                                {sprintf(__("#%s"), order.number)}
                                            </a>
                                            <span className="fxc:text-muted-foreground fxc:ml-2 fxc:text-xs">{order.relation === "parent" ? __("First order") : __("Renewal")}</span>
                                        </td>
                                        <td className="fxc:text-muted-foreground fxc:py-2 fxc:pr-3 fxc:whitespace-nowrap">{formatDate(order.created_at, false)}</td>
                                        <td className="fxc:py-2 fxc:pr-3">{order.status_label}</td>
                                        <td className="fxc:py-2 fxc:text-right fxc:tabular-nums">{orderMoney(order.total, row.currency)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                <div className="fxc:grid fxc:gap-4 fxc:sm:grid-cols-2">
                    <Address title={__("Billing address")} address={row.billing} />
                    <Address title={__("Shipping address")} address={row.shipping} />
                </div>

                {row.events.length > 0 && (
                    <div>
                        <h3 className="fxc:mb-2 fxc:text-sm fxc:font-semibold">{__("History")}</h3>
                        <ul className="fxc:m-0 fxc:list-none fxc:space-y-2 fxc:p-0">
                            {row.events.map((event, i) => (
                                <li key={i} className="fxc:text-sm">
                                    <span className="fxc:text-muted-foreground fxc:mr-2 fxc:text-xs fxc:whitespace-nowrap">{formatDate(event.created_at)}</span>
                                    {event.message}
                                    {event.actor !== "" && <span className="fxc:text-muted-foreground fxc:ml-1 fxc:text-xs">{sprintf(__("by %s"), event.actor)}</span>}
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
            </div>

            <DialogFooter>
                <Button variant="outline" onClick={onClose}>
                    {__("Close")}
                </Button>
            </DialogFooter>
        </>
    );
}

export function SubscriptionsPage() {
    const [search, setSearch] = useState("");
    const [query, setQuery] = useState("");
    const [status, setStatus] = useState<SubscriptionStatus | "">("");
    const [page, setPage] = useState(1);
    const [open, setOpenState] = useState<number | null>(readOpen);
    const list = useSubscriptionList(query, status, page);
    const pages = list.data?.pages ?? 1;
    const counts = list.data?.counts ?? {};

    const setOpen = (id: number | null) => {
        writeOpen(id);
        setOpenState(id);
    };

    useEffect(() => {
        const timer = window.setTimeout(() => {
            setQuery(search.trim());
            setPage(1);
        }, 300);
        return () => window.clearTimeout(timer);
    }, [search]);

    return (
        <div className="fxc:space-y-4">
            <PageTitle
                title={__("Subscriptions")}
                description={__("Products sold with a plan renew on their own: on each payment day the store makes a renewal order and emails the customer a link to pay it.")}
            />
            <div className="fxc:relative fxc:w-full fxc:max-w-sm">
                <Search aria-hidden className="fxc:text-muted-foreground fxc:pointer-events-none fxc:absolute fxc:top-1/2 fxc:left-3 fxc:size-4 fxc:-translate-y-1/2" />
                <Input
                    type="search"
                    aria-label={__("Search subscriptions")}
                    placeholder={__("Email, name, product or number")}
                    className="fxc:pl-9"
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                />
            </div>

            <Tabs
                value={status}
                onChange={(next: SubscriptionStatus | "") => {
                    setStatus(next);
                    setPage(1);
                }}
                items={FILTERS.map((value) => ({
                    value,
                    label: value === "" ? __("All") : counts[value] ? `${STATUS_LABEL[value]} (${counts[value]})` : STATUS_LABEL[value],
                }))}
            />

            <Card className="fxc:overflow-x-auto">
                {list.isPending ? (
                    <p className="fxc:text-muted-foreground fxc:px-5 fxc:py-4">{__("Loading…")}</p>
                ) : list.isError ? (
                    <p className="fxc:text-destructive fxc:px-5 fxc:py-4">{list.error.message}</p>
                ) : list.data.items.length === 0 ? (
                    <p className="fxc:text-muted-foreground fxc:px-5 fxc:py-8 fxc:text-center">
                        {status !== "" || query !== ""
                            ? __("No subscriptions match.")
                            : __("No subscriptions yet. Give a product a subscription plan in its General tab to sell it as one.")}
                    </p>
                ) : (
                    <table className="fxc:w-full fxc:text-sm">
                        <thead>
                            <tr className="fxc:text-muted-foreground fxc:border-b fxc:text-left fxc:text-xs">
                                <th className="fxc:px-5 fxc:py-2 fxc:font-medium">{__("Subscription")}</th>
                                <th className="fxc:px-3 fxc:py-2 fxc:font-medium">{__("Customer")}</th>
                                <th className="fxc:px-3 fxc:py-2 fxc:font-medium">{__("Status")}</th>
                                <th className="fxc:px-3 fxc:py-2 fxc:text-right fxc:font-medium">{__("Price")}</th>
                                <th className="fxc:px-5 fxc:py-2 fxc:font-medium">{__("Next payment")}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {list.data.items.map((row) => (
                                <tr key={row.id} className="fxc:hover:bg-muted/50 fxc:border-b fxc:last:border-b-0">
                                    <td className="fxc:px-5 fxc:py-3">
                                        <button
                                            type="button"
                                            className="fxc:text-primary fxc:cursor-pointer fxc:bg-transparent fxc:p-0 fxc:text-left fxc:hover:underline"
                                            onClick={() => setOpen(row.id)}
                                        >
                                            {sprintf(__("#%d"), row.id)}
                                        </button>
                                        <div className="fxc:text-muted-foreground fxc:text-xs">{row.title}</div>
                                    </td>
                                    <td className="fxc:px-3 fxc:py-3">
                                        <div>{row.customer_name || row.customer_email}</div>
                                        {row.customer_name !== "" && <div className="fxc:text-muted-foreground fxc:text-xs">{row.customer_email}</div>}
                                    </td>
                                    <td className="fxc:px-3 fxc:py-3">
                                        <Badge variant={STATUS_VARIANT[row.status]}>{STATUS_LABEL[row.status]}</Badge>
                                    </td>
                                    <td className="fxc:px-3 fxc:py-3 fxc:text-right fxc:whitespace-nowrap">{row.price}</td>
                                    <td className="fxc:text-muted-foreground fxc:px-5 fxc:py-3 fxc:whitespace-nowrap">
                                        {row.next_payment_at !== null ? formatDate(row.next_payment_at, false) : row.end_at !== null ? sprintf(__("Ends %s"), formatDate(row.end_at, false)) : "—"}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </Card>

            {pages > 1 && (
                <div className="fxc:flex fxc:items-center fxc:justify-end fxc:gap-3 fxc:text-sm">
                    <span className="fxc:text-muted-foreground">{sprintf(__("Page %1$d of %2$d"), page, pages)}</span>
                    <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => setPage(page - 1)}>
                        {__("Previous")}
                    </Button>
                    <Button variant="outline" size="sm" disabled={page >= pages} onClick={() => setPage(page + 1)}>
                        {__("Next")}
                    </Button>
                </div>
            )}

            <SubscriptionDialog id={open} onClose={() => setOpen(null)} />
        </div>
    );
}
