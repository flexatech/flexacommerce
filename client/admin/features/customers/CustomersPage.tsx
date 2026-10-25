import { useEffect, useState } from "react";
import { Search } from "lucide-react";

import { Badge } from "@/shared/components/ui/badge";
import { Button } from "@/shared/components/ui/button";
import { Card } from "@/shared/components/ui/card";
import { Input } from "@/shared/components/ui/input";
import { Select } from "@/shared/components/ui/select";
import { getPluginGlobal } from "@/shared/lib/api";
import { __, sprintf } from "@/shared/lib/i18n";
import { useDebounced } from "@/shared/lib/useDebounced";
import { PageTitle } from "../../components/PageTitle";
import { Tabs } from "../../components/Tabs";
import { formatDate, orderMoney } from "../orders/format";
import { useCustomers, type Customer, type CustomerListParams, type CustomerOrderBy, type CustomerType } from "./useCustomers";

/** Accounts link by user id so orders placed under an old email are included; guests link by email. */
function ordersUrl(customer: Customer): string {
    const { adminUrl } = getPluginGlobal();
    const query =
        customer.registered && customer.customer_id > 0
            ? `customer_id=${customer.customer_id}`
            : `search=${encodeURIComponent(customer.email)}`;
    return `${adminUrl}?page=flexacommerce-orders&${query}`;
}

export function CustomersPage() {
    const [params, setParams] = useState<CustomerListParams>({ type: "", search: "", orderby: "last_order", page: 1 });
    const [search, setSearch] = useState("");
    const debounced = useDebounced(search);
    const list = useCustomers(params);

    useEffect(() => {
        if (debounced !== params.search) {
            setParams({ ...params, search: debounced, page: 1 });
        }
    }, [debounced, params]);

    const pages = list.data?.pages ?? 1;

    return (
        <div className="fxc:space-y-4">
            <PageTitle
                title={__("Customers")}
                description={__("Everyone who has placed an order: accounts and guest emails. Spent is paid less refunds.")}
            />

            <div className="fxc:flex fxc:flex-wrap fxc:items-end fxc:justify-between fxc:gap-3">
                <Tabs
                    value={params.type}
                    onChange={(type: CustomerType) => setParams({ ...params, type, page: 1 })}
                    items={[
                        { value: "", label: __("All") },
                        { value: "registered", label: __("Accounts") },
                        { value: "guest", label: __("Guests") },
                    ]}
                />
                <div className="fxc:flex fxc:items-center fxc:gap-2">
                    <Select
                        aria-label={__("Sort by")}
                        value={params.orderby}
                        onChange={(e) => setParams({ ...params, orderby: e.target.value as CustomerOrderBy, page: 1 })}
                        options={[
                            { value: "last_order", label: __("Latest order") },
                            { value: "orders", label: __("Most orders") },
                            { value: "spent", label: __("Most spent") },
                        ]}
                    />
                    <div className="fxc:relative fxc:w-64">
                        <Search aria-hidden className="fxc:text-muted-foreground fxc:pointer-events-none fxc:absolute fxc:top-2.5 fxc:left-3 fxc:size-4" />
                        <Input
                            aria-label={__("Search customers")}
                            placeholder={__("Email or name")}
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            className="fxc:pl-9!"
                        />
                    </div>
                </div>
            </div>

            <Card className="fxc:overflow-x-auto">
                {list.isPending ? (
                    <p className="fxc:text-muted-foreground fxc:px-5 fxc:py-4">{__("Loading…")}</p>
                ) : list.isError ? (
                    <p className="fxc:text-destructive fxc:px-5 fxc:py-4">{list.error.message}</p>
                ) : list.data.items.length === 0 ? (
                    <p className="fxc:text-muted-foreground fxc:px-5 fxc:py-8 fxc:text-center">
                        {params.search !== "" || params.type !== "" ? __("No customers match.") : __("No customers yet.")}
                    </p>
                ) : (
                    <table className="fxc:w-full fxc:text-sm">
                        <thead>
                            <tr className="fxc:text-muted-foreground fxc:border-b fxc:text-left fxc:text-xs">
                                <th className="fxc:px-5 fxc:py-2 fxc:font-medium">{__("Customer")}</th>
                                <th className="fxc:px-3 fxc:py-2 fxc:font-medium">{__("Type")}</th>
                                <th className="fxc:px-3 fxc:py-2 fxc:text-right fxc:font-medium">{__("Orders")}</th>
                                <th className="fxc:px-3 fxc:py-2 fxc:text-right fxc:font-medium">{__("Spent")}</th>
                                <th className="fxc:px-3 fxc:py-2 fxc:font-medium">{__("First order")}</th>
                                <th className="fxc:px-5 fxc:py-2 fxc:font-medium">{__("Latest order")}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {list.data.items.map((customer) => (
                                <tr
                                    key={customer.registered ? `u${customer.customer_id}` : `e${customer.email}`}
                                    className="fxc:hover:bg-muted/50 fxc:border-b fxc:last:border-b-0"
                                >
                                    <td className="fxc:px-5 fxc:py-3">
                                        <div className="fxc:font-medium">
                                            {customer.edit_url !== "" ? (
                                                <a href={customer.edit_url} className="fxc:text-primary fxc:no-underline fxc:hover:underline">
                                                    {customer.name || customer.email}
                                                </a>
                                            ) : (
                                                customer.name || customer.email
                                            )}
                                        </div>
                                        {customer.name !== "" && <div className="fxc:text-muted-foreground fxc:text-xs">{customer.email}</div>}
                                    </td>
                                    <td className="fxc:px-3 fxc:py-3">
                                        <Badge variant={customer.registered ? "success" : "muted"}>
                                            {customer.registered ? __("Account") : __("Guest")}
                                        </Badge>
                                    </td>
                                    <td className="fxc:px-3 fxc:py-3 fxc:text-right fxc:tabular-nums">
                                        <a href={ordersUrl(customer)} className="fxc:text-primary fxc:no-underline fxc:hover:underline">
                                            {customer.orders}
                                        </a>
                                    </td>
                                    <td className="fxc:px-3 fxc:py-3 fxc:text-right fxc:font-medium fxc:tabular-nums fxc:whitespace-nowrap">
                                        {orderMoney(customer.spent, customer.currency)}
                                    </td>
                                    <td className="fxc:text-muted-foreground fxc:px-3 fxc:py-3 fxc:whitespace-nowrap">
                                        {formatDate(customer.first_order, false)}
                                    </td>
                                    <td className="fxc:text-muted-foreground fxc:px-5 fxc:py-3 fxc:whitespace-nowrap">
                                        {formatDate(customer.last_order, false)}
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
        </div>
    );
}
