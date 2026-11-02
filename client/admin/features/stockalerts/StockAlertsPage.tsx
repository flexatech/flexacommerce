import { useState } from "react";
import { BellRing, Trash2, X } from "lucide-react";

import { showToast } from "@/shared/components/showToast";
import { Badge } from "@/shared/components/ui/badge";
import { Button } from "@/shared/components/ui/button";
import { Card, CardDescription, CardHeader, CardTitle } from "@/shared/components/ui/card";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/shared/components/ui/dialog";
import { getPluginGlobal } from "@/shared/lib/api";
import { __, _n, sprintf } from "@/shared/lib/i18n";
import { EmptyState } from "../../components/EmptyState";
import { PageTitle } from "../../components/PageTitle";
import { Tabs } from "../../components/Tabs";
import { useUrlTab } from "../../components/useUrlTab";
import { formatDate } from "../orders/format";
import { StockAlertSettingsPanel } from "./StockAlertSettingsPanel";
import { useDeleteStockAlert, useStockAlertList, type StockAlertItem, type StockAlertStatus, type WantedProduct } from "./useStockAlerts";

const TABS = ["signups", "settings"] as const;

const STATUS_LABEL: Record<StockAlertStatus, string> = {
    waiting: __("Waiting"),
    sent: __("Emailed"),
    cancelled: __("Stopped"),
};

const STATUS_VARIANT: Record<StockAlertStatus, "brand" | "success" | "muted"> = {
    waiting: "brand",
    sent: "success",
    cancelled: "muted",
};

function editUrl(id: number): string {
    return `${getPluginGlobal().adminUrl.replace(/admin\.php$/, "post.php")}?post=${id}&action=edit`;
}

function StatCard({ label, value }: { label: string; value: string }) {
    return (
        <Card className="fxc:px-5 fxc:py-4">
            <div className="fxc:text-muted-foreground fxc:text-xs fxc:font-medium">{label}</div>
            <div className="fxc:text-foreground fxc:mt-1 fxc:text-xl fxc:font-semibold fxc:tabular-nums">{value}</div>
        </Card>
    );
}

function WantedCard({ wanted, onPick }: { wanted: WantedProduct[]; onPick: (product: WantedProduct) => void }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>{__("Most wanted")}</CardTitle>
                <CardDescription>{__("Products with the most shoppers waiting. Restock these first.")}</CardDescription>
            </CardHeader>
            <ul className="fxc:border-t">
                {wanted.map((product) => (
                    <li key={product.product_id} className="fxc:flex fxc:items-center fxc:justify-between fxc:gap-3 fxc:border-b fxc:px-5 fxc:py-2 fxc:last:border-b-0">
                        <button
                            type="button"
                            className="fxc:text-primary fxc:cursor-pointer fxc:bg-transparent fxc:p-0 fxc:text-left fxc:text-sm fxc:hover:underline"
                            onClick={() => onPick(product)}
                        >
                            {product.name}
                        </button>
                        <span className="fxc:flex fxc:items-center fxc:gap-2 fxc:text-sm fxc:whitespace-nowrap">
                            {product.in_stock && <Badge variant="success">{__("In stock")}</Badge>}
                            <span className="fxc:tabular-nums">{sprintf(_n("%d waiting", "%d waiting", product.waiting), product.waiting)}</span>
                        </span>
                    </li>
                ))}
            </ul>
        </Card>
    );
}

function SignupsPanel({ onSettings }: { onSettings: () => void }) {
    const [status, setStatus] = useState<StockAlertStatus | "">("");
    const [product, setProduct] = useState<WantedProduct | null>(null);
    const [page, setPage] = useState(1);
    const [deleting, setDeleting] = useState<StockAlertItem | null>(null);
    const list = useStockAlertList(status, product?.product_id ?? 0, page);
    const remove = useDeleteStockAlert();
    const data = list.data;
    const pages = data?.pages ?? 1;

    return (
        <div className="fxc:space-y-4">
            {data && !data.enabled && (
                <Card className="fxc:flex fxc:flex-wrap fxc:items-center fxc:justify-between fxc:gap-3 fxc:px-5 fxc:py-4">
                    <p className="fxc:text-sm">{__("Back-in-stock emails are off. Turn them on to show the signup form on out-of-stock products.")}</p>
                    <Button variant="outline" size="sm" onClick={onSettings}>
                        {__("Open settings")}
                    </Button>
                </Card>
            )}

            {data && (
                <div className="fxc:grid fxc:grid-cols-2 fxc:gap-3">
                    <StatCard label={__("Shoppers waiting")} value={String(data.stats.waiting)} />
                    <StatCard label={sprintf(__("Emails sent in %d days"), data.stats.days)} value={String(data.stats.sent)} />
                </div>
            )}

            {data && data.wanted.length > 0 && (
                <WantedCard
                    wanted={data.wanted}
                    onPick={(next) => {
                        setProduct(next);
                        setPage(1);
                    }}
                />
            )}

            <div className="fxc:flex fxc:flex-wrap fxc:items-center fxc:justify-between fxc:gap-3">
                <Tabs
                    value={status}
                    onChange={(next: StockAlertStatus | "") => {
                        setStatus(next);
                        setPage(1);
                    }}
                    items={[
                        { value: "", label: __("All") },
                        { value: "waiting", label: STATUS_LABEL.waiting },
                        { value: "sent", label: STATUS_LABEL.sent },
                        { value: "cancelled", label: STATUS_LABEL.cancelled },
                    ]}
                />
                {product && (
                    <Button
                        variant="outline"
                        size="sm"
                        aria-label={sprintf(__("Show all products, not only %s"), product.name)}
                        onClick={() => {
                            setProduct(null);
                            setPage(1);
                        }}
                    >
                        {product.name}
                        <X aria-hidden className="fxc:size-4" />
                    </Button>
                )}
            </div>

            <Card className="fxc:overflow-x-auto">
                {list.isPending ? (
                    <p className="fxc:text-muted-foreground fxc:px-5 fxc:py-4">{__("Loading…")}</p>
                ) : list.isError ? (
                    <p className="fxc:text-destructive fxc:px-5 fxc:py-4">{list.error.message}</p>
                ) : list.data.items.length === 0 ? (
                    <EmptyState
                        icon={BellRing}
                        filtered={status !== "" || product !== null}
                        title={status !== "" || product ? __("No signups match.") : __("No back-in-stock signups yet.")}
                        description={__("When a shopper asks for an email on a sold-out product, the request shows here.")}
                    />
                ) : (
                    <table className="fxc:w-full fxc:text-sm">
                        <thead>
                            <tr className="fxc:text-muted-foreground fxc:border-b fxc:text-left fxc:text-xs">
                                <th className="fxc:px-5 fxc:py-2 fxc:font-medium">{__("Shopper")}</th>
                                <th className="fxc:px-3 fxc:py-2 fxc:font-medium">{__("Product")}</th>
                                <th className="fxc:px-3 fxc:py-2 fxc:font-medium">{__("Status")}</th>
                                <th className="fxc:px-3 fxc:py-2 fxc:font-medium">{__("Signed up")}</th>
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
                                        <a href={editUrl(item.product_id)} className="fxc:text-primary fxc:no-underline fxc:hover:underline">
                                            {item.product}
                                        </a>
                                        {item.variant_id === 0 && <div className="fxc:text-muted-foreground fxc:text-xs">{__("Any variation")}</div>}
                                    </td>
                                    <td className="fxc:px-3 fxc:py-3">
                                        <Badge variant={STATUS_VARIANT[item.status]}>{STATUS_LABEL[item.status]}</Badge>
                                        {item.sent_at !== null && <div className="fxc:text-muted-foreground fxc:mt-1 fxc:text-xs">{formatDate(item.sent_at)}</div>}
                                    </td>
                                    <td className="fxc:text-muted-foreground fxc:px-3 fxc:py-3 fxc:whitespace-nowrap">{formatDate(item.created_at)}</td>
                                    <td className="fxc:px-5 fxc:py-3 fxc:text-right">
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            aria-label={sprintf(__("Delete the signup of %s"), item.email)}
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
                        <DialogTitle>{__("Delete this signup?")}</DialogTitle>
                        <DialogDescription>{__("The shopper gets no email when the product is back. They can sign up again on the product page.")}</DialogDescription>
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
                                        showToast.success(__("Signup deleted."));
                                        setDeleting(null);
                                    },
                                    onError: (error) => showToast.error(error.message),
                                })
                            }
                        >
                            {remove.isPending ? __("Deleting…") : __("Delete signup")}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}

export function StockAlertsPage() {
    const [tab, setTab] = useUrlTab(TABS);
    return (
        <div className="fxc:space-y-4">
            <PageTitle
                title={__("Stock alerts")}
                description={__("Shoppers who asked for an email when an out-of-stock product or variation comes back.")}
            />
            <Tabs
                value={tab}
                onChange={setTab}
                items={[
                    { value: "signups", label: __("Signups") },
                    { value: "settings", label: __("Settings") },
                ]}
            />
            {tab === "signups" ? <SignupsPanel onSettings={() => setTab("settings")} /> : <StockAlertSettingsPanel />}
        </div>
    );
}
