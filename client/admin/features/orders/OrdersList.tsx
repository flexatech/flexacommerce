import { useEffect, useState } from "react";
import { AlertTriangle, Download, FileText, Package, Receipt, Search } from "lucide-react";

import { Badge } from "@/shared/components/ui/badge";
import { Button } from "@/shared/components/ui/button";
import { Card } from "@/shared/components/ui/card";
import { Input } from "@/shared/components/ui/input";
import { __, sprintf } from "@/shared/lib/i18n";
import { useDebounced } from "@/shared/lib/useDebounced";
import { EmptyState } from "../../components/EmptyState";
import { PageTitle } from "../../components/PageTitle";
import { invoicePdfUrl } from "../invoices/useInvoices";
import { Tabs } from "../../components/Tabs";
import { ExportDialog } from "./ExportDialog";
import { formatDate, orderMoney, PAYMENT_LABEL, STATUS_LABEL, STATUS_TONE, STATUSES } from "./format";
import { NEW_ORDER, orderUrl } from "./navigation";
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
    const [selected, setSelected] = useState<number[]>([]);
    const [exporting, setExporting] = useState(false);

    // A new page or filter starts with nothing selected.
    useEffect(() => setSelected([]), [params]);

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

    const narrowed = params.search !== "" || filter !== "" || params.customer_id > 0 || params.date_from !== "" || params.date_to !== "";
    const pages = list.data?.pages ?? 1;
    const ids = list.data?.items.map((order) => order.id) ?? [];
    const allSelected = ids.length > 0 && ids.every((id) => selected.includes(id));
    const toggle = (id: number, on: boolean) => setSelected(on ? [...selected, id] : selected.filter((s) => s !== id));

    return (
        <div className="fxc:space-y-4">
            <PageTitle
                title={__("Orders")}
                description={__("Orders placed in your store, newest first.")}
                actions={
                    <div className="fxc:flex fxc:items-center fxc:gap-2">
                        <Button variant="outline" onClick={() => setExporting(true)}>
                            <Download aria-hidden className="fxc:size-4" />
                            {__("Export CSV")}
                        </Button>
                        <Button asChild>
                            <a
                                href={orderUrl(NEW_ORDER)}
                                className="fxc:no-underline"
                                onClick={(e) => {
                                    e.preventDefault();
                                    onOpen(NEW_ORDER);
                                }}
                            >
                                {__("Create order")}
                            </a>
                        </Button>
                    </div>
                }
            />

            <div className="fxc:flex fxc:flex-wrap fxc:items-end fxc:justify-between fxc:gap-3">
                <Tabs value={filter} onChange={setFilter} items={tabs} />
                <div className="fxc:flex fxc:flex-wrap fxc:items-center fxc:gap-2">
                    <div className="fxc:flex fxc:items-center fxc:gap-1.5 fxc:text-sm">
                        <Input
                            type="date"
                            aria-label={__("Orders from")}
                            className="fxc:w-40"
                            value={params.date_from}
                            max={params.date_to || undefined}
                            onChange={(e) => onParams({ ...params, date_from: e.target.value, page: 1 })}
                        />
                        <span className="fxc:text-muted-foreground" aria-hidden>
                            –
                        </span>
                        <Input
                            type="date"
                            aria-label={__("Orders to")}
                            className="fxc:w-40"
                            value={params.date_to}
                            min={params.date_from || undefined}
                            onChange={(e) => onParams({ ...params, date_to: e.target.value, page: 1 })}
                        />
                        {(params.date_from !== "" || params.date_to !== "") && (
                            <Button variant="ghost" size="sm" onClick={() => onParams({ ...params, date_from: "", date_to: "", page: 1 })}>
                                {__("Any date")}
                            </Button>
                        )}
                    </div>
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
            </div>

            <ExportDialog params={params} filtered={narrowed} open={exporting} onOpenChange={setExporting} />

            {params.customer_id > 0 && (
                <div className="fxc:bg-muted fxc:flex fxc:items-center fxc:justify-between fxc:gap-3 fxc:rounded-lg fxc:px-4 fxc:py-2 fxc:text-sm">
                    <span>{__("Showing the orders of one customer account.")}</span>
                    <Button variant="ghost" size="sm" onClick={() => onParams({ ...params, customer_id: 0, page: 1 })}>
                        {__("Show all orders")}
                    </Button>
                </div>
            )}

            {selected.length > 0 && (
                <div className="fxc:bg-muted fxc:flex fxc:flex-wrap fxc:items-center fxc:gap-3 fxc:rounded-lg fxc:px-4 fxc:py-2 fxc:text-sm" role="region" aria-label={__("Selected orders")}>
                    <span className="fxc:font-medium">{sprintf(__("%d selected"), selected.length)}</span>
                    <Button asChild variant="outline" size="sm">
                        <a href={invoicePdfUrl(selected, "invoice")} target="_blank" rel="noopener noreferrer">
                            <FileText aria-hidden className="fxc:size-4" />
                            {__("Invoices (PDF)")}
                        </a>
                    </Button>
                    <Button asChild variant="outline" size="sm">
                        <a href={invoicePdfUrl(selected, "packing_slip")} target="_blank" rel="noopener noreferrer">
                            <Package aria-hidden className="fxc:size-4" />
                            {__("Packing slips (PDF)")}
                        </a>
                    </Button>
                    <Button variant="ghost" size="sm" onClick={() => setSelected([])}>
                        {__("Clear selection")}
                    </Button>
                </div>
            )}

            <Card className="fxc:overflow-x-auto">
                {list.isPending ? (
                    <p className="fxc:text-muted-foreground fxc:px-5 fxc:py-4">{__("Loading…")}</p>
                ) : list.isError ? (
                    <p className="fxc:text-destructive fxc:px-5 fxc:py-4">{list.error.message}</p>
                ) : list.data.items.length === 0 ? (
                    <EmptyState
                        icon={Receipt}
                        filtered={narrowed}
                        title={narrowed ? __("No orders match.") : __("No orders yet.")}
                        description={__("Orders show here as soon as a shopper checks out. You can also create one for a customer with Create order.")}
                    />
                ) : (
                    <table className="fxc:w-full fxc:text-sm">
                        <thead>
                            <tr className="fxc:text-muted-foreground fxc:border-b fxc:text-left fxc:text-xs">
                                <th className="fxc:w-8 fxc:py-2 fxc:pl-5">
                                    <input
                                        type="checkbox"
                                        className="flexacommerce-check"
                                        aria-label={__("Select all orders on this page")}
                                        checked={allSelected}
                                        onChange={(e) => setSelected(e.target.checked ? ids : [])}
                                    />
                                </th>
                                <th className="fxc:px-3 fxc:py-2 fxc:font-medium">{__("Order")}</th>
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
                                    <td className="fxc:w-8 fxc:py-3 fxc:pl-5">
                                        <input
                                            type="checkbox"
                                            className="flexacommerce-check"
                                            aria-label={sprintf(__("Select order %s"), order.number)}
                                            checked={selected.includes(order.id)}
                                            onChange={(e) => toggle(order.id, e.target.checked)}
                                        />
                                    </td>
                                    <td className="fxc:px-3 fxc:py-3">
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
