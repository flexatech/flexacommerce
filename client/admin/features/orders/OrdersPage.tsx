import { useState } from "react";

import { OrderDetailView } from "./OrderDetail";
import { OrderEditor } from "./OrderEditor";
import { OrdersList } from "./OrdersList";
import { NEW_ORDER, useOrderRoute } from "./navigation";
import type { OrderListParams } from "./types";

export function OrdersPage() {
    const [route, open] = useOrderRoute();
    // Held here so the filters survive a trip to an order and back.
    // The customers screen links here with `?customer_id=` or `?search=`.
    const [params, setParams] = useState<OrderListParams>(() => {
        const query = new URLSearchParams(window.location.search);
        const customer = Number.parseInt(query.get("customer_id") ?? "", 10);
        return {
            status: "",
            needs_attention: false,
            search: query.get("search") ?? "",
            customer_id: Number.isFinite(customer) && customer > 0 ? customer : 0,
            date_from: "",
            date_to: "",
            page: 1,
        };
    });

    if (route.id === NEW_ORDER || (route.id > 0 && route.edit)) {
        return <OrderEditor key={route.id} id={route.id === NEW_ORDER ? 0 : route.id} onDone={(id) => open(id)} />;
    }
    return route.id > 0 ? (
        <OrderDetailView id={route.id} onBack={() => open(0)} onEdit={() => open(route.id, true)} />
    ) : (
        <OrdersList params={params} onParams={setParams} onOpen={open} />
    );
}
