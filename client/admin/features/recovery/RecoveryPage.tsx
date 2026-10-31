import { useState } from "react";
import { Trash2 } from "lucide-react";

import { showToast } from "@/shared/components/showToast";
import { Badge } from "@/shared/components/ui/badge";
import { Button } from "@/shared/components/ui/button";
import { Card } from "@/shared/components/ui/card";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/shared/components/ui/dialog";
import { getPluginGlobal } from "@/shared/lib/api";
import { __, _n, sprintf } from "@/shared/lib/i18n";
import { PageTitle } from "../../components/PageTitle";
import { Tabs } from "../../components/Tabs";
import { useUrlTab } from "../../components/useUrlTab";
import { formatDate } from "../orders/format";
import { RecoverySettingsPanel } from "./RecoverySettingsPanel";
import { useDeleteRecovery, useRecoveryList, type RecoveryItem, type RecoveryStatus } from "./useRecovery";

const TABS = ["carts", "settings"] as const;

const STATUS_LABEL: Record<RecoveryStatus, string> = {
    open: __("Open"),
    recovered: __("Recovered"),
    lost: __("Lost"),
};

const STATUS_VARIANT: Record<RecoveryStatus, "brand" | "success" | "muted"> = {
    open: "brand",
    recovered: "success",
    lost: "muted",
};

function orderUrl(id: number): string {
    return `${getPluginGlobal().adminUrl}?page=flexacommerce-orders&order=${id}`;
}

/** What happened to the cart so far, in one line. */
function progress(item: RecoveryItem): string {
    if (item.status === "recovered") {
        return item.order_number !== null ? sprintf(__("Order #%s placed"), item.order_number) : __("Order placed");
    }
    if (item.unsubscribed) {
        return __("Stopped reminders");
    }
    const sent =
        item.emails_sent > 0 ? sprintf(_n("%d reminder sent", "%d reminders sent", item.emails_sent), item.emails_sent) : __("No reminder sent yet");
    if (item.status === "open" && item.next_send_at !== null && !item.reminders_done) {
        return `${sent}. ${sprintf(__("Next: %s"), formatDate(item.next_send_at))}`;
    }
    return sent;
}

function StatCard({ label, value }: { label: string; value: string }) {
    return (
        <Card className="fxc:px-5 fxc:py-4">
            <div className="fxc:text-muted-foreground fxc:text-xs fxc:font-medium">{label}</div>
            <div className="fxc:text-foreground fxc:mt-1 fxc:text-xl fxc:font-semibold fxc:tabular-nums">{value}</div>
        </Card>
    );
}

function CartsPanel({ onSettings }: { onSettings: () => void }) {
    const [status, setStatus] = useState<RecoveryStatus | "">("");
    const [page, setPage] = useState(1);
    const [deleting, setDeleting] = useState<RecoveryItem | null>(null);
    const list = useRecoveryList(status, page);
    const remove = useDeleteRecovery();
    const data = list.data;
    const pages = data?.pages ?? 1;

    return (
        <div className="fxc:space-y-4">
            {data && !data.enabled && (
                <Card className="fxc:flex fxc:flex-wrap fxc:items-center fxc:justify-between fxc:gap-3 fxc:px-5 fxc:py-4">
                    <p className="fxc:text-sm">{__("Cart reminders are off. Turn them on to start following carts that shoppers leave.")}</p>
                    <Button variant="outline" size="sm" onClick={onSettings}>
                        {__("Open settings")}
                    </Button>
                </Card>
            )}

            {data && (
                <div className="fxc:grid fxc:grid-cols-2 fxc:gap-3 fxc:md:grid-cols-4">
                    <StatCard label={__("Recovered sales")} value={data.stats.revenue.formatted || "0"} />
                    <StatCard label={__("Recovery rate")} value={data.stats.rate !== null ? `${data.stats.rate}%` : "–"} />
                    <StatCard label={__("Recovered carts")} value={String(data.stats.recovered)} />
                    <StatCard label={__("Open carts")} value={String(data.stats.open)} />
                </div>
            )}
            {data && (
                <p className="fxc:text-muted-foreground fxc:text-xs">
                    {sprintf(
                        __("Carts left in the last %d days. A cart counts as recovered when its shopper orders after a reminder or after using the link in it."),
                        data.stats.days,
                    )}
                </p>
            )}

            <Tabs
                value={status}
                onChange={(next: RecoveryStatus | "") => {
                    setStatus(next);
                    setPage(1);
                }}
                items={[
                    { value: "", label: __("All") },
                    { value: "open", label: STATUS_LABEL.open },
                    { value: "recovered", label: STATUS_LABEL.recovered },
                    { value: "lost", label: STATUS_LABEL.lost },
                ]}
            />

            <Card className="fxc:overflow-x-auto">
                {list.isPending ? (
                    <p className="fxc:text-muted-foreground fxc:px-5 fxc:py-4">{__("Loading…")}</p>
                ) : list.isError ? (
                    <p className="fxc:text-destructive fxc:px-5 fxc:py-4">{list.error.message}</p>
                ) : list.data.items.length === 0 ? (
                    <p className="fxc:text-muted-foreground fxc:px-5 fxc:py-8 fxc:text-center">
                        {status !== "" ? __("No carts match.") : __("No abandoned carts yet.")}
                    </p>
                ) : (
                    <table className="fxc:w-full fxc:text-sm">
                        <thead>
                            <tr className="fxc:text-muted-foreground fxc:border-b fxc:text-left fxc:text-xs">
                                <th className="fxc:px-5 fxc:py-2 fxc:font-medium">{__("Customer")}</th>
                                <th className="fxc:px-3 fxc:py-2 fxc:font-medium">{__("Cart")}</th>
                                <th className="fxc:px-3 fxc:py-2 fxc:text-right fxc:font-medium">{__("Total")}</th>
                                <th className="fxc:px-3 fxc:py-2 fxc:font-medium">{__("Status")}</th>
                                <th className="fxc:px-3 fxc:py-2 fxc:font-medium">{__("Last activity")}</th>
                                <th className="fxc:px-5 fxc:py-2">
                                    <span className="fxc:sr-only">{__("Actions")}</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {list.data.items.map((item) => (
                                <tr key={item.id} className="fxc:hover:bg-muted/50 fxc:border-b fxc:last:border-b-0">
                                    <td className="fxc:px-5 fxc:py-3">
                                        <div className="fxc:font-medium">{item.customer || item.email}</div>
                                        <div className="fxc:text-muted-foreground fxc:text-xs">
                                            {item.customer !== "" ? item.email : item.user_id === null ? __("Guest") : ""}
                                        </div>
                                    </td>
                                    <td className="fxc:px-3 fxc:py-3">
                                        <div>{item.items.map((line) => `${line.name} × ${line.quantity}`).join(", ")}</div>
                                        {item.coupon_code !== "" && (
                                            <div className="fxc:text-muted-foreground fxc:text-xs">{sprintf(__("Code sent: %s"), item.coupon_code)}</div>
                                        )}
                                    </td>
                                    <td className="fxc:px-3 fxc:py-3 fxc:text-right fxc:font-medium fxc:tabular-nums fxc:whitespace-nowrap">
                                        {item.total.formatted}
                                    </td>
                                    <td className="fxc:px-3 fxc:py-3">
                                        <Badge variant={STATUS_VARIANT[item.status]}>{STATUS_LABEL[item.status]}</Badge>
                                        <div className="fxc:text-muted-foreground fxc:mt-1 fxc:text-xs">
                                            {item.status === "recovered" && item.order_id !== null ? (
                                                <a href={orderUrl(item.order_id)} className="fxc:text-primary fxc:no-underline fxc:hover:underline">
                                                    {progress(item)}
                                                </a>
                                            ) : (
                                                progress(item)
                                            )}
                                        </div>
                                    </td>
                                    <td className="fxc:text-muted-foreground fxc:px-3 fxc:py-3 fxc:whitespace-nowrap">
                                        {formatDate(item.last_activity_at)}
                                    </td>
                                    <td className="fxc:px-5 fxc:py-3 fxc:text-right">
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            aria-label={sprintf(__("Delete the cart of %s"), item.email)}
                                            onClick={() => setDeleting(item)}
                                        >
                                            <Trash2 aria-hidden className="fxc:size-4" />
                                        </Button>
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

            <Dialog open={deleting !== null} onOpenChange={(open) => !open && !remove.isPending && setDeleting(null)}>
                <DialogContent className="fxc:max-w-md">
                    <DialogHeader>
                        <DialogTitle>{__("Delete this cart?")}</DialogTitle>
                        <DialogDescription>
                            {__("No more reminders go out for it and it leaves the numbers above. The shopper's own cart is not changed.")}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" disabled={remove.isPending} onClick={() => setDeleting(null)}>
                            {__("Cancel")}
                        </Button>
                        <Button
                            variant="destructive"
                            disabled={remove.isPending}
                            onClick={() =>
                                deleting !== null &&
                                remove.mutate(deleting.id, {
                                    onSuccess: () => {
                                        showToast.success(__("Cart deleted."));
                                        setDeleting(null);
                                    },
                                    onError: (error) => showToast.error(error.message),
                                })
                            }
                        >
                            {remove.isPending ? __("Deleting…") : __("Delete cart")}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}

export function RecoveryPage() {
    const [tab, setTab] = useUrlTab(TABS);
    return (
        <div className="fxc:space-y-4">
            <PageTitle
                title={__("Abandoned carts")}
                description={__("Carts that signed-in shoppers and guests who typed their email left without ordering, and the reminder emails they get.")}
            />
            <Tabs
                value={tab}
                onChange={setTab}
                items={[
                    { value: "carts", label: __("Carts") },
                    { value: "settings", label: __("Settings") },
                ]}
            />
            {tab === "carts" ? <CartsPanel onSettings={() => setTab("settings")} /> : <RecoverySettingsPanel />}
        </div>
    );
}
