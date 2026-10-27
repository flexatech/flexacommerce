export type OrderStatus = "pending" | "on-hold" | "processing" | "completed" | "cancelled" | "failed" | "refunded";

export type PaymentStatus = "unpaid" | "partially_paid" | "paid" | "partially_refunded" | "refunded";

export interface OrderSummary {
    id: number;
    number: string;
    status: OrderStatus;
    payment_status: PaymentStatus;
    needs_attention: boolean;
    currency: string;
    total: number;
    amount_paid: number;
    amount_refunded: number;
    customer: { id: number | null; email: string; name: string };
    payment_method: string;
    payment_method_title: string;
    created_at: string | null;
}

export interface OrderAddress {
    first_name: string;
    last_name: string;
    company: string;
    address_1: string;
    address_2: string;
    city: string;
    state: string;
    postcode: string;
    country: string;
    email?: string;
    phone?: string;
}

export interface OrderItem {
    id: number;
    type: "product" | "shipping" | "fee" | "discount" | "tax" | string;
    product_id: number | null;
    variant_id: number | null;
    code: string;
    name: string;
    sku: string;
    attributes: { label: string; value: string }[];
    quantity: number;
    unit_price: number;
    subtotal: number;
    tax_total: number;
    total: number;
    refunded_qty: number;
    refunded_total: number;
    restocked_qty: number;
    stock_managed: boolean;
}

export interface OrderTransaction {
    id: number;
    type: "payment" | "refund" | string;
    status: "pending" | "succeeded" | "failed" | "cancelled" | string;
    method: "gateway" | "manual" | string;
    gateway: string;
    amount: number;
    gateway_reference: string | null;
    failure_message: string | null;
    created_at: string | null;
    completed_at: string | null;
}

export interface OrderHistoryEntry {
    id: number;
    type: string;
    from_status: OrderStatus | null;
    to_status: OrderStatus | null;
    message: string;
    customer_visible: boolean;
    actor_type: string;
    actor_name: string;
    created_at: string | null;
}

export interface OrderDetail extends OrderSummary {
    subtotal: number;
    discount_total: number;
    shipping_total: number;
    fee_total: number;
    tax_total: number;
    refundable: number;
    balance_due: number;
    customer_note: string;
    /** Answers to the merchant's own checkout fields, with the label of the time of the order. */
    fields: { label: string; value: string }[];
    customer_ip: string;
    created_via: string;
    paid_at: string | null;
    completed_at: string | null;
    cancelled_at: string | null;
    anonymized: boolean;
    billing: OrderAddress | null;
    shipping: OrderAddress | null;
    items: OrderItem[];
    transitions: OrderStatus[];
    gateway: { id: string; title: string; refunds: boolean };
    can: { edit: boolean; refund: boolean };
    links: { view: string; pay: string };
    transactions: OrderTransaction[];
    history: OrderHistoryEntry[];
}

export interface OrderCounts {
    all: number;
    status: Record<OrderStatus, number>;
    needs_attention: number;
}

export interface OrderListParams {
    status: OrderStatus | "";
    needs_attention: boolean;
    search: string;
    /** 0 for every customer. */
    customer_id: number;
    page: number;
}

export interface RefundLineInput {
    item_id: number;
    qty: number;
    amount: number;
    restock: boolean;
}
