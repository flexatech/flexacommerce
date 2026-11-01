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
    /** The line sold files when it was ordered. */
    downloadable: boolean;
    /** A bundle line (ADR-071): its parts follow it. */
    bundle: boolean;
    /** The bundle line this part belongs to; parts carry no price. */
    bundled_by: number | null;
}

/** One file one line may download (ADR-058); null limits mean no limit. */
export interface OrderDownload {
    id: number;
    order_item_id: number;
    product_id: number;
    variant_id: number;
    file_id: string;
    /** Empty when the file was removed from the product. */
    file_name: string;
    product_name: string;
    url: string;
    downloads_remaining: number | null;
    access_expires: string | null;
    download_count: number;
    granted_at: string | null;
    last_download_at: string | null;
}

/** One package with its tracking (ADR-067); items null is the whole order. */
export interface OrderShipment {
    id: number;
    order_id: number;
    /** A built-in carrier key, or empty for one typed in. */
    carrier: string;
    carrier_name: string;
    tracking_number: string;
    tracking_url: string;
    items: Record<string, number> | null;
    shipped_at: string | null;
    created_at: string | null;
}

export interface ShipmentCarriers {
    carriers: { key: string; name: string }[];
    last: { carrier: string; carrier_name: string } | null;
}

export interface ShipmentInput {
    carrier: string;
    carrier_name: string;
    tracking_number: string;
    tracking_url: string;
    items: Record<string, number> | null;
    shipped_at: string;
    complete: boolean;
    notify: boolean;
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
    /** The order has a customer account, so a refund can go to store credit (ADR-072). */
    credit_refunds: boolean;
    /** Unpaid and pending, on hold or failed: lines and prices can change (ADR-061). */
    editable: boolean;
    can: { edit: boolean; refund: boolean };
    links: { view: string; pay: string };
    transactions: OrderTransaction[];
    history: OrderHistoryEntry[];
    downloads: OrderDownload[];
    shipments: OrderShipment[];
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
    /** YYYY-MM-DD store-local days, both included; empty for no limit. */
    date_from: string;
    date_to: string;
    page: number;
}

export type OrderExportLayout = "orders" | "items";

export interface OrderExportPage {
    lines: string;
    rows: number;
    orders: number;
    /** The id to send as `after` for the next page. */
    last: number;
    done: boolean;
    /** First page only. */
    total?: number;
    until?: number;
    header?: string;
    name?: string;
}

export interface RefundLineInput {
    item_id: number;
    qty: number;
    amount: number;
    restock: boolean;
}
