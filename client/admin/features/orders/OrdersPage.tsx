import { useState } from "react";

import { OrderDetailView } from "./OrderDetail";
import { OrdersList } from "./OrdersList";
import { useOrderId } from "./navigation";
import type { OrderListParams } from "./types";

export function OrdersPage() {
    const [orderId, open] = useOrderId();
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
            page: 1,
        };
    });

    return orderId > 0 ? (
        <OrderDetailView id={orderId} onBack={() => open(0)} />
    ) : (
        <OrdersList params={params} onParams={setParams} onOpen={open} />
    );
}
