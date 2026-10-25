import { useEffect, useState } from "react";
import { AlertTriangle, Search } from "lucide-react";

import { Badge } from "@/shared/components/ui/badge";
import { Button } from "@/shared/components/ui/button";
import { Card } from "@/shared/components/ui/card";
import { Input } from "@/shared/components/ui/input";
import { __, sprintf } from "@/shared/lib/i18n";
import { useDebounced } from "@/shared/lib/useDebounced";
import { PageTitle } from "../../components/PageTitle";
import { Tabs } from "../../components/Tabs";
import { formatDate, orderMoney, PAYMENT_LABEL, STATUS_LABEL, STATUS_TONE, STATUSES } from "./format";
import { orderUrl } from "./navigation";
import type { OrderListParams, OrderStatus } from "./types";
import { useOrderCounts, useOrderList } from "./useOrders";

type Filter = "" | "attention" | OrderStatus;

interface Props {
    params: OrderListParams;
    onParams: (params: OrderListParams) => void;
    onOpen: (id: number) => void;
}

export function OrdersList({ params, onParams, onOpen }: Props) {
    const [search, setSearch] = useState(params.search);
    const debounced = useDebounced(search);
    const list = useOrderList(params);
    const counts = useOrderCounts();

    useEffect(() => {
        if (debounced !== params.search) {
            onParams({ ...params, search: debounced, page: 1 });
        }
    }, [debounced, params, onParams]);

    const filter: Filter = params.needs_attention ? "attention" : params.status;
    const setFilter = (next: Filter) =>
        onParams({
            ...params,
            status: next === "attention" ? "" : next,
            needs_attention: next === "attention",
            page: 1,
        });

    const count = (n: number | undefined) => (n === undefined ? "" : ` (${n})`);
    const tabs: { value: Filter; label: string }[] = [
        { value: "", label: __("All") + count(counts.data?.all) },
        ...STATUSES.filter((s) => s === params.status || (counts.data?.status[s] ?? 0) > 0).map((s) => ({
            value: s as Filter,
            label: STATUS_LABEL[s] + count(counts.data?.status[s]),
        })),
    ];
    if ((counts.data?.needs_attention ?? 0) > 0 || params.needs_attention) {
        tabs.push({ value: "attention", label: __("Needs attention") + count(counts.data?.needs_attention) });
    }

    const pages = list.data?.pages ?? 1;

    return (
        <div className="fxc:space-y-4">
            <PageTitle title={__("Orders")} description={__("Orders placed in your store, newest first.")} />

            <div className="fxc:flex fxc:flex-wrap fxc:items-end fxc:justify-between fxc:gap-3">
                <Tabs value={filter} onChange={setFilter} items={tabs} />
                <div className="fxc:relative fxc:w-64">
                    <Search aria-hidden className="fxc:text-muted-foreground fxc:pointer-events-none fxc:absolute fxc:top-2.5 fxc:left-3 fxc:size-4" />
                    <Input
                        aria-label={__("Search orders")}
                        placeholder={__("Number, email or name")}
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        className="fxc:pl-9!"
                    />
                </div>
            </div>

            {params.customer_id > 0 && (
                <div className="fxc:bg-muted fxc:flex fxc:items-center fxc:justify-between fxc:gap-3 fxc:rounded-lg fxc:px-4 fxc:py-2 fxc:text-sm">
                    <span>{__("Showing the orders of one customer account.")}</span>
                    <Button variant="ghost" size="sm" onClick={() => onParams({ ...params, customer_id: 0, page: 1 })}>
                        {__("Show all orders")}
                    </Button>
                </div>
            )}

            <Card className="fxc:overflow-x-auto">
                {list.isPending ? (
                    <p className="fxc:text-muted-foreground fxc:px-5 fxc:py-4">{__("Loading…")}</p>
                ) : list.isError ? (
                    <p className="fxc:text-destructive fxc:px-5 fxc:py-4">{list.error.message}</p>
                ) : list.data.items.length === 0 ? (
                    <p className="fxc:text-muted-foreground fxc:px-5 fxc:py-8 fxc:text-center">
                        {params.search !== "" || filter !== "" || params.customer_id > 0 ? __("No orders match.") : __("No orders yet.")}
                    </p>
                ) : (
                    <table className="fxc:w-full fxc:text-sm">
                        <thead>
                            <tr className="fxc:text-muted-foreground fxc:border-b fxc:text-left fxc:text-xs">
                                <th className="fxc:px-5 fxc:py-2 fxc:font-medium">{__("Order")}</th>
                                <th className="fxc:px-3 fxc:py-2 fxc:font-medium">{__("Date")}</th>
                                <th className="fxc:px-3 fxc:py-2 fxc:font-medium">{__("Customer")}</th>
                                <th className="fxc:px-3 fxc:py-2 fxc:font-medium">{__("Status")}</th>
                                <th className="fxc:px-3 fxc:py-2 fxc:font-medium">{__("Payment")}</th>
                                <th className="fxc:px-5 fxc:py-2 fxc:text-right fxc:font-medium">{__("Total")}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {list.data.items.map((order) => (
                                <tr key={order.id} className="fxc:hover:bg-muted/50 fxc:border-b fxc:last:border-b-0">
                                    <td className="fxc:px-5 fxc:py-3">
                                        <a
                                            href={orderUrl(order.id)}
                                            onClick={(e) => {
                                                if (!e.metaKey && !e.ctrlKey && !e.shiftKey) {
                                                    e.preventDefault();
                                                    onOpen(order.id);
                                                }
                                            }}
                                            className="fxc:text-primary fxc:inline-flex fxc:items-center fxc:gap-1.5 fxc:font-semibold fxc:no-underline fxc:hover:underline"
                                        >
                                            {order.needs_attention && (
                                                <AlertTriangle aria-label={__("Needs attention")} className="fxc:text-destructive fxc:size-4" />
                                            )}
                                            #{order.number}
                                        </a>
                                    </td>
                                    <td className="fxc:text-muted-foreground fxc:px-3 fxc:py-3 fxc:whitespace-nowrap">
                                        {formatDate(order.created_at)}
                                    </td>
                                    <td className="fxc:px-3 fxc:py-3">
                                        <div className="fxc:font-medium">{order.customer.name || __("Guest")}</div>
                                        <div className="fxc:text-muted-foreground fxc:text-xs">{order.customer.email}</div>
                                    </td>
                                    <td className="fxc:px-3 fxc:py-3">
                                        <Badge variant={STATUS_TONE[order.status]}>{STATUS_LABEL[order.status]}</Badge>
                                    </td>
                                    <td className="fxc:text-muted-foreground fxc:px-3 fxc:py-3 fxc:text-xs">
                                        <div>{PAYMENT_LABEL[order.payment_status]}</div>
                                        <div>{order.payment_method_title}</div>
                                    </td>
                                    <td className="fxc:px-5 fxc:py-3 fxc:text-right fxc:font-medium fxc:tabular-nums fxc:whitespace-nowrap">
                                        {orderMoney(order.total, order.currency)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </Card>

            {pages > 1 && (
                <div className="fxc:flex fxc:items-center fxc:justify-end fxc:gap-3 fxc:text-sm">
                    <span className="fxc:text-muted-foreground">{sprintf(__("Page %1$d of %2$d"), params.page, pages)}</span>
                    <Button variant="outline" size="sm" disabled={params.page <= 1} onClick={() => onParams({ ...params, page: params.page - 1 })}>
                        {__("Previous")}
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        disabled={params.page >= pages}
                        onClick={() => onParams({ ...params, page: params.page + 1 })}
                    >
                        {__("Next")}
                    </Button>
                </div>
            )}
        </div>
    );
}
