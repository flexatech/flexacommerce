import { useEffect, useState } from "react";
import { Pencil, Plus, Search, Trash2 } from "lucide-react";

import { showToast } from "@/shared/components/showToast";
import { Badge } from "@/shared/components/ui/badge";
import { Button } from "@/shared/components/ui/button";
import { Card } from "@/shared/components/ui/card";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/shared/components/ui/dialog";
import { Input } from "@/shared/components/ui/input";
import { __, sprintf } from "@/shared/lib/i18n";
import { useDebounced } from "@/shared/lib/useDebounced";
import { PageTitle } from "../../components/PageTitle";
import { Tabs } from "../../components/Tabs";
import { formatDate, orderMoney } from "../orders/format";
import { CouponDialog } from "./CouponDialog";
import { useCoupons, useDeleteCoupon, type Coupon, type CouponListParams } from "./useCoupons";

function amountText(coupon: Coupon): string {
    if (coupon.free_shipping && coupon.amount === 0) {
        return __("Free shipping");
    }
    if (coupon.type === "percent") {
        return `${coupon.amount / 100} %`;
    }
    const money = orderMoney(coupon.amount, coupon.currency);
    return coupon.type === "fixed_product" ? sprintf(__("%s per item"), money) : money;
}

/** Disabled, scheduled, expired or used up; else active. */
function stateOf(coupon: Coupon): { label: string; tone: "success" | "muted" | "destructive" } {
    const now = Date.now();
    if (coupon.status === "disabled") {
        return { label: __("Disabled"), tone: "muted" };
    }
    if (coupon.expires_at !== null && Date.parse(coupon.expires_at) <= now) {
        return { label: __("Expired"), tone: "destructive" };
    }
    if (coupon.usage_limit > 0 && coupon.usage_count >= coupon.usage_limit) {
        return { label: __("Used up"), tone: "destructive" };
    }
    if (coupon.starts_at !== null && Date.parse(coupon.starts_at) > now) {
        return { label: __("Scheduled"), tone: "muted" };
    }
    return { label: __("Active"), tone: "success" };
}

export function CouponsPage() {
    const [params, setParams] = useState<CouponListParams>({ status: "", search: "", page: 1 });
    const [search, setSearch] = useState("");
    const debounced = useDebounced(search);
    const list = useCoupons(params);
    const remove = useDeleteCoupon();
    const [editing, setEditing] = useState<{ coupon: Coupon | null } | null>(null);
    const [deleting, setDeleting] = useState<Coupon | null>(null);

    useEffect(() => {
        if (debounced !== params.search) {
            setParams({ ...params, search: debounced, page: 1 });
        }
    }, [debounced, params]);

    const pages = list.data?.pages ?? 1;

    return (
        <div className="fxc:space-y-4">
            <PageTitle
                title={__("Coupons")}
                description={__("Discount codes shoppers enter in the cart. Coupons apply in the order they are added, each to what the earlier ones left.")}
                actions={
                    <Button onClick={() => setEditing({ coupon: null })}>
                        <Plus aria-hidden className="fxc:size-4" />
                        {__("Add coupon")}
                    </Button>
                }
            />

            <div className="fxc:flex fxc:flex-wrap fxc:items-end fxc:justify-between fxc:gap-3">
                <Tabs
                    value={params.status}
                    onChange={(status: CouponListParams["status"]) => setParams({ ...params, status, page: 1 })}
                    items={[
                        { value: "", label: __("All") },
                        { value: "active", label: __("Active") },
                        { value: "disabled", label: __("Disabled") },
                    ]}
                />
                <div className="fxc:relative fxc:w-64">
                    <Search aria-hidden className="fxc:text-muted-foreground fxc:pointer-events-none fxc:absolute fxc:top-2.5 fxc:left-3 fxc:size-4" />
                    <Input
                        aria-label={__("Search coupons")}
                        placeholder={__("Code or description")}
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        className="fxc:pl-9!"
                    />
                </div>
            </div>

            <Card className="fxc:overflow-x-auto">
                {list.isPending ? (
                    <p className="fxc:text-muted-foreground fxc:px-5 fxc:py-4">{__("Loading…")}</p>
                ) : list.isError ? (
                    <p className="fxc:text-destructive fxc:px-5 fxc:py-4">{list.error.message}</p>
                ) : list.data.items.length === 0 ? (
                    <p className="fxc:text-muted-foreground fxc:px-5 fxc:py-8 fxc:text-center">
                        {params.search !== "" || params.status !== "" ? __("No coupons match.") : __("No coupons yet.")}
                    </p>
                ) : (
                    <table className="fxc:w-full fxc:text-sm">
                        <thead>
                            <tr className="fxc:text-muted-foreground fxc:border-b fxc:text-left fxc:text-xs">
                                <th className="fxc:px-5 fxc:py-2 fxc:font-medium">{__("Code")}</th>
                                <th className="fxc:px-3 fxc:py-2 fxc:font-medium">{__("Discount")}</th>
                                <th className="fxc:px-3 fxc:py-2 fxc:font-medium">{__("Status")}</th>
                                <th className="fxc:px-3 fxc:py-2 fxc:text-right fxc:font-medium">{__("Used")}</th>
                                <th className="fxc:px-3 fxc:py-2 fxc:font-medium">{__("Expires")}</th>
                                <th className="fxc:px-5 fxc:py-2">
                                    <span className="fxc:sr-only">{__("Actions")}</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {list.data.items.map((coupon) => {
                                const state = stateOf(coupon);
                                return (
                                    <tr key={coupon.id} className="fxc:hover:bg-muted/50 fxc:border-b fxc:last:border-b-0">
                                        <td className="fxc:px-5 fxc:py-3">
                                            <div className="fxc:font-mono fxc:font-medium">{coupon.code}</div>
                                            {coupon.description !== "" && (
                                                <div className="fxc:text-muted-foreground fxc:max-w-xs fxc:truncate fxc:text-xs">{coupon.description}</div>
                                            )}
                                        </td>
                                        <td className="fxc:px-3 fxc:py-3 fxc:whitespace-nowrap fxc:tabular-nums">{amountText(coupon)}</td>
                                        <td className="fxc:px-3 fxc:py-3">
                                            <Badge variant={state.tone}>{state.label}</Badge>
                                        </td>
                                        <td className="fxc:px-3 fxc:py-3 fxc:text-right fxc:tabular-nums fxc:whitespace-nowrap">
                                            {coupon.usage_limit > 0 ? `${coupon.usage_count} / ${coupon.usage_limit}` : coupon.usage_count}
                                        </td>
                                        <td className="fxc:text-muted-foreground fxc:px-3 fxc:py-3 fxc:whitespace-nowrap">
                                            {coupon.expires_at !== null ? formatDate(coupon.expires_at) : __("Never")}
                                        </td>
                                        <td className="fxc:px-5 fxc:py-3 fxc:text-right fxc:whitespace-nowrap">
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                aria-label={sprintf(__("Edit %s"), coupon.code)}
                                                onClick={() => setEditing({ coupon })}
                                            >
                                                <Pencil aria-hidden className="fxc:size-4" />
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                aria-label={sprintf(__("Delete %s"), coupon.code)}
                                                onClick={() => setDeleting(coupon)}
                                            >
                                                <Trash2 aria-hidden className="fxc:size-4" />
                                            </Button>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                )}
            </Card>

            {pages > 1 && (
                <div className="fxc:flex fxc:items-center fxc:justify-end fxc:gap-3 fxc:text-sm">
                    <span className="fxc:text-muted-foreground">{sprintf(__("Page %1$d of %2$d"), params.page, pages)}</span>
                    <Button variant="outline" size="sm" disabled={params.page <= 1} onClick={() => setParams({ ...params, page: params.page - 1 })}>
                        {__("Previous")}
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        disabled={params.page >= pages}
                        onClick={() => setParams({ ...params, page: params.page + 1 })}
                    >
                        {__("Next")}
                    </Button>
                </div>
            )}

            <CouponDialog open={editing !== null} coupon={editing?.coupon ?? null} onOpenChange={(open) => !open && setEditing(null)} />

            <Dialog open={deleting !== null} onOpenChange={(open) => !open && !remove.isPending && setDeleting(null)}>
                <DialogContent className="fxc:max-w-md">
                    <DialogHeader>
                        <DialogTitle>{sprintf(__("Delete %s?"), deleting?.code ?? "")}</DialogTitle>
                        <DialogDescription>
                            {__("Carts holding the code lose it at their next update. Orders that used it keep their discount. To stop it for now, disable it instead.")}
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
                                        showToast.success(__("Coupon deleted."));
                                        setDeleting(null);
                                    },
                                    onError: (error) => showToast.error(error.message),
                                })
                            }
                        >
                            {remove.isPending ? __("Deleting…") : __("Delete coupon")}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
