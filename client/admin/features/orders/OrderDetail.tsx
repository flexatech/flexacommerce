import { useEffect, useState, type ReactNode } from "react";
import { AlertTriangle, ArrowLeft, ExternalLink } from "lucide-react";

import { showToast } from "@/shared/components/showToast";
import { Badge } from "@/shared/components/ui/badge";
import { Button } from "@/shared/components/ui/button";
import { Card, CardHeader, CardTitle } from "@/shared/components/ui/card";
import { Input } from "@/shared/components/ui/input";
import { Label } from "@/shared/components/ui/label";
import { Select } from "@/shared/components/ui/select";
import { Textarea } from "@/shared/components/ui/textarea";
import { __, sprintf } from "@/shared/lib/i18n";
import { PageTitle } from "../../components/PageTitle";
import { formatDate, orderMoney, PAYMENT_LABEL, STATUS_LABEL, STATUS_TONE } from "./format";
import { orderUrl } from "./navigation";
import { PaymentDialog } from "./PaymentDialog";
import { RefundDialog } from "./RefundDialog";
import type { OrderAddress, OrderDetail, OrderHistoryEntry, OrderStatus } from "./types";
import { useAddNote, useClearAttention, useOrder, useTransition } from "./useOrders";

export function OrderDetailView({ id, onBack }: { id: number; onBack: () => void }) {
    const query = useOrder(id);

    const back = (
        <a
            href={orderUrl(0)}
            onClick={(e) => {
                e.preventDefault();
                onBack();
            }}
            className="fxc:text-muted-foreground fxc:hover:text-foreground fxc:mb-3 fxc:inline-flex fxc:items-center fxc:gap-1 fxc:text-sm fxc:no-underline"
        >
            <ArrowLeft aria-hidden className="fxc:size-4" />
            {__("All orders")}
        </a>
    );

    if (query.isPending) {
        return (
            <div>
                {back}
                <p className="fxc:text-muted-foreground">{__("Loading order…")}</p>
            </div>
        );
    }
    if (query.isError) {
        return (
            <div>
                {back}
                <p className="fxc:text-destructive">{query.error.message}</p>
            </div>
        );
    }

    const order = query.data;
    return (
        <div>
            {back}
            <PageTitle
                title={sprintf(__("Order #%s"), order.number)}
                description={sprintf(__("Placed %s"), formatDate(order.created_at))}
                actions={
                    <>
                        <Badge variant={STATUS_TONE[order.status]}>{STATUS_LABEL[order.status]}</Badge>
                        <a
                            href={order.links.view}
                            target="_blank"
                            rel="noreferrer"
                            className="fxc:text-primary fxc:inline-flex fxc:items-center fxc:gap-1 fxc:text-sm fxc:no-underline fxc:hover:underline"
                        >
                            {__("Customer view")}
                            <ExternalLink aria-hidden className="fxc:size-3.5" />
                        </a>
                    </>
                }
            />
            {order.needs_attention && <AttentionBanner order={order} />}
            <div className="fxc:grid fxc:gap-6 fxc:lg:grid-cols-3">
                <div className="fxc:space-y-6 fxc:lg:col-span-2">
                    <ItemsCard order={order} />
                    {order.transactions.length > 0 && <TransactionsCard order={order} />}
                    <HistoryCard order={order} />
                </div>
                <div className="fxc:space-y-6">
                    <ActionsCard order={order} />
                    <CustomerCard order={order} />
                </div>
            </div>
        </div>
    );
}

function AttentionBanner({ order }: { order: OrderDetail }) {
    const clear = useClearAttention(order.id);
    return (
        <div
            role="status"
            className="fxc:border-destructive/40 fxc:bg-destructive/5 fxc:mb-6 fxc:flex fxc:items-center fxc:gap-3 fxc:rounded-lg fxc:border fxc:px-4 fxc:py-3 fxc:text-sm"
        >
            <AlertTriangle aria-hidden className="fxc:text-destructive fxc:size-4 fxc:shrink-0" />
            <p className="fxc:flex-1">
                {__("This order needs a look: a payment or refund did not settle as expected. Check the history below.")}
            </p>
            {order.can.edit && (
                <Button
                    size="sm"
                    variant="outline"
                    disabled={clear.isPending}
                    onClick={() =>
                        clear.mutate(undefined, {
                            onSuccess: () => showToast.success(__("Marked as resolved.")),
                            onError: (e) => showToast.error(e.message),
                        })
                    }
                >
                    {__("Mark as resolved")}
                </Button>
            )}
        </div>
    );
}

function Row({ label, value, strong = false }: { label: string; value: ReactNode; strong?: boolean }) {
    return (
        <div className={strong ? "fxc:flex fxc:justify-between fxc:font-semibold" : "fxc:text-muted-foreground fxc:flex fxc:justify-between"}>
            <span>{label}</span>
            <span className="fxc:tabular-nums">{value}</span>
        </div>
    );
}

function ItemsCard({ order }: { order: OrderDetail }) {
    const money = (minor: number) => orderMoney(minor, order.currency);
    const products = order.items.filter((i) => i.type === "product");
    const extras = order.items.filter((i) => i.type === "shipping" || i.type === "fee");
    const taxes = order.items.filter((i) => i.type === "tax");
    const coupons = order.items.filter((i) => i.type === "discount").map((i) => i.name);

    return (
        <Card>
            <CardHeader>
                <CardTitle>{__("Items")}</CardTitle>
            </CardHeader>
            <table className="fxc:w-full fxc:text-sm">
                <tbody>
                    {[...products, ...extras].map((item) => (
                        <tr key={item.id} className="fxc:border-b">
                            <td className="fxc:px-5 fxc:py-3">
                                <div className="fxc:font-medium">{item.name}</div>
                                <div className="fxc:text-muted-foreground fxc:text-xs">
                                    {[
                                        item.sku !== "" ? sprintf(__("SKU: %s"), item.sku) : "",
                                        ...item.attributes.map((a) => `${a.label}: ${a.value}`),
                                        item.type === "shipping" ? __("Shipping") : item.type === "fee" ? __("Fee") : "",
                                    ]
                                        .filter(Boolean)
                                        .join(" · ")}
                                </div>
                                {item.refunded_qty > 0 && (
                                    <div className="fxc:text-destructive fxc:text-xs">
                                        {sprintf(__("%1$d refunded (%2$s)"), item.refunded_qty, money(item.refunded_total))}
                                        {item.restocked_qty > 0 && " · " + sprintf(__("%d restocked"), item.restocked_qty)}
                                    </div>
                                )}
                            </td>
                            <td className="fxc:text-muted-foreground fxc:px-3 fxc:py-3 fxc:text-right fxc:whitespace-nowrap fxc:tabular-nums">
                                {money(item.unit_price)} × {item.quantity}
                            </td>
                            <td className="fxc:px-5 fxc:py-3 fxc:text-right fxc:whitespace-nowrap fxc:tabular-nums">{money(item.total)}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
            <div className="fxc:space-y-1 fxc:border-b fxc:last:border-b-0 fxc:px-5 fxc:py-4 fxc:text-sm">
                <Row label={__("Subtotal")} value={money(order.subtotal)} />
                {order.discount_total > 0 && (
                    <Row
                        label={coupons.length > 0 ? sprintf(__("Discount (%s)"), coupons.join(", ")) : __("Discount")}
                        value={"−" + money(order.discount_total)}
                    />
                )}
                {order.shipping_total > 0 && <Row label={__("Shipping")} value={money(order.shipping_total)} />}
                {order.fee_total > 0 && <Row label={__("Fees")} value={money(order.fee_total)} />}
                {taxes.length > 0
                    ? taxes.map((tax) => <Row key={tax.id} label={tax.name || __("Tax")} value={money(tax.total)} />)
                    : order.tax_total > 0 && <Row label={__("Tax")} value={money(order.tax_total)} />}
                <Row label={__("Total")} value={money(order.total)} strong />
                <div className="fxc:border-t fxc:pt-2" />
                <Row label={__("Paid")} value={money(order.amount_paid)} />
                {order.amount_refunded > 0 && <Row label={__("Refunded")} value={"−" + money(order.amount_refunded)} />}
                {order.balance_due > 0 && <Row label={__("Balance due")} value={money(order.balance_due)} strong />}
            </div>
        </Card>
    );
}

function TransactionsCard({ order }: { order: OrderDetail }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>{__("Transactions")}</CardTitle>
            </CardHeader>
            <ul className="fxc:text-sm">
                {order.transactions.map((txn) => (
                    <li key={txn.id} className="fxc:flex fxc:items-start fxc:gap-3 fxc:border-b fxc:last:border-b-0 fxc:px-5 fxc:py-3">
                        <div className="fxc:min-w-0 fxc:flex-1">
                            <div className="fxc:font-medium">
                                {txn.type === "refund" ? __("Refund") : __("Payment")}
                                <span className="fxc:text-muted-foreground fxc:font-normal">
                                    {" · "}
                                    {txn.method === "manual" ? __("Recorded by hand") : txn.gateway}
                                </span>
                            </div>
                            <div className="fxc:text-muted-foreground fxc:text-xs">
                                {formatDate(txn.created_at)}
                                {txn.gateway_reference !== null && txn.gateway_reference !== "" && (
                                    <span className="fxc:font-mono"> · {txn.gateway_reference}</span>
                                )}
                            </div>
                            {txn.failure_message !== null && txn.failure_message !== "" && (
                                <div className="fxc:text-destructive fxc:text-xs">{txn.failure_message}</div>
                            )}
                        </div>
                        <Badge variant={txn.status === "succeeded" ? "success" : txn.status === "failed" ? "destructive" : "muted"}>
                            {txn.status}
                        </Badge>
                        <span className="fxc:w-24 fxc:text-right fxc:tabular-nums">
                            {(txn.type === "refund" ? "−" : "") + orderMoney(txn.amount, order.currency)}
                        </span>
                    </li>
                ))}
            </ul>
        </Card>
    );
}

function historyText(entry: OrderHistoryEntry): string {
    if (entry.type === "status" && entry.to_status !== null) {
        const to = STATUS_LABEL[entry.to_status] ?? entry.to_status;
        const line =
            entry.from_status !== null
                ? sprintf(__("Status changed from %1$s to %2$s."), STATUS_LABEL[entry.from_status] ?? entry.from_status, to)
                : sprintf(__("Order created as %s."), to);
        return entry.message !== "" ? `${line} ${entry.message}` : line;
    }
    return entry.message;
}

function HistoryCard({ order }: { order: OrderDetail }) {
    const add = useAddNote(order.id);
    const [note, setNote] = useState("");
    const [visible, setVisible] = useState(false);

    const submit = () =>
        add.mutate(
            { note, customer_visible: visible },
            {
                onSuccess: () => {
                    setNote("");
                    setVisible(false);
                },
                onError: (e) => showToast.error(e.message),
            },
        );

    return (
        <Card>
            <CardHeader>
                <CardTitle>{__("History")}</CardTitle>
            </CardHeader>
            {order.can.edit && !order.anonymized && (
                <div className="fxc:space-y-2 fxc:border-b fxc:last:border-b-0 fxc:px-5 fxc:py-4">
                    <Label htmlFor="fxc-order-note" className="fxc:sr-only">
                        {__("Add a note")}
                    </Label>
                    <Textarea id="fxc-order-note" placeholder={__("Add a note")} value={note} onChange={(e) => setNote(e.target.value)} />
                    <div className="fxc:flex fxc:items-center fxc:justify-between fxc:gap-3">
                        <label className="fxc:flex fxc:items-center fxc:gap-2 fxc:text-sm">
                            <input
                                type="checkbox"
                                className="flexacommerce-check"
                                checked={visible}
                                onChange={(e) => setVisible(e.target.checked)}
                            />
                            {__("Show to the customer")}
                        </label>
                        <Button size="sm" onClick={submit} disabled={note.trim() === "" || add.isPending}>
                            {add.isPending ? __("Adding…") : __("Add note")}
                        </Button>
                    </div>
                </div>
            )}
            <ol className="fxc:text-sm">
                {order.history.map((entry) => (
                    <li key={entry.id} className="fxc:border-b fxc:last:border-b-0 fxc:px-5 fxc:py-3">
                        <p className="fxc:break-words">{historyText(entry)}</p>
                        <div className="fxc:text-muted-foreground fxc:mt-0.5 fxc:flex fxc:flex-wrap fxc:gap-x-2 fxc:text-xs">
                            <time dateTime={entry.created_at ?? undefined}>{formatDate(entry.created_at)}</time>
                            <span>{entry.actor_name !== "" ? entry.actor_name : entry.actor_type}</span>
                            {entry.customer_visible && <span className="fxc:text-primary">{__("Visible to customer")}</span>}
                        </div>
                    </li>
                ))}
            </ol>
        </Card>
    );
}

function ActionsCard({ order }: { order: OrderDetail }) {
    const transition = useTransition(order.id);
    const [target, setTarget] = useState<OrderStatus | "">("");
    const [note, setNote] = useState("");
    const [notify, setNotify] = useState(false);
    const [paymentOpen, setPaymentOpen] = useState(false);
    const [refundOpen, setRefundOpen] = useState(false);

    useEffect(() => {
        setTarget("");
        setNote("");
        setNotify(false);
    }, [order.status]);

    const changeStatus = () => {
        if (target === "") {
            return;
        }
        transition.mutate(
            { status: target, note, notify_customer: target === "cancelled" && notify },
            {
                onSuccess: (next) => showToast.success(sprintf(__("Order marked as %s."), STATUS_LABEL[next.status])),
                onError: (e) => showToast.error(e.message),
            },
        );
    };

    const canPay = order.can.edit && order.balance_due > 0 && order.status !== "cancelled" && order.status !== "refunded";
    const canRefund = order.can.refund && order.refundable > 0;

    return (
        <Card>
            <CardHeader>
                <CardTitle>{__("Order")}</CardTitle>
            </CardHeader>
            <div className="fxc:space-y-1 fxc:border-b fxc:last:border-b-0 fxc:px-5 fxc:py-4 fxc:text-sm">
                <Row label={__("Payment")} value={PAYMENT_LABEL[order.payment_status]} />
                <Row label={__("Method")} value={order.payment_method_title || "—"} />
                {order.paid_at !== null && <Row label={__("Paid on")} value={formatDate(order.paid_at, false)} />}
                {order.completed_at !== null && <Row label={__("Completed on")} value={formatDate(order.completed_at, false)} />}
                {order.cancelled_at !== null && <Row label={__("Cancelled on")} value={formatDate(order.cancelled_at, false)} />}
            </div>
            {order.can.edit && order.transitions.length > 0 && (
                <div className="fxc:space-y-2 fxc:border-b fxc:last:border-b-0 fxc:px-5 fxc:py-4">
                    <Label htmlFor="fxc-order-status" className="fxc:block">
                        {__("Change status")}
                    </Label>
                    <Select
                        id="fxc-order-status"
                        className="fxc:w-full"
                        value={target}
                        onChange={(e) => setTarget(e.target.value as OrderStatus | "")}
                        options={[
                            { value: "", label: __("Choose a status") },
                            ...order.transitions.map((s) => ({ value: s, label: STATUS_LABEL[s] })),
                        ]}
                    />
                    {target !== "" && (
                        <>
                            <Input
                                aria-label={__("Note for the history (optional)")}
                                placeholder={__("Note for the history (optional)")}
                                value={note}
                                onChange={(e) => setNote(e.target.value)}
                            />
                            {target === "cancelled" && (
                                <>
                                    <p className="fxc:text-muted-foreground fxc:text-xs">
                                        {__("Cancelling puts reserved stock back. Money already paid is not refunded automatically.")}
                                    </p>
                                    {order.amount_paid > 0 ? (
                                        <p className="fxc:text-muted-foreground fxc:text-xs">
                                            {__("The customer paid for this order, so they get the cancellation email.")}
                                        </p>
                                    ) : (
                                        <div className="fxc:flex fxc:items-start fxc:gap-2">
                                            <input
                                                id="fxc-order-notify"
                                                type="checkbox"
                                                checked={notify}
                                                onChange={(e) => setNotify(e.target.checked)}
                                                className="flexacommerce-check fxc:mt-0.5"
                                            />
                                            <label htmlFor="fxc-order-notify" className="fxc:text-foreground fxc:text-sm">
                                                {__("Email the customer about the cancellation")}
                                            </label>
                                        </div>
                                    )}
                                </>
                            )}
                            <Button
                                className="fxc:w-full"
                                variant={target === "cancelled" ? "destructive" : "primary"}
                                onClick={changeStatus}
                                disabled={transition.isPending}
                            >
                                {transition.isPending ? __("Saving…") : sprintf(__("Mark as %s"), STATUS_LABEL[target])}
                            </Button>
                        </>
                    )}
                </div>
            )}
            {(canPay || canRefund || order.links.pay !== "") && (
                <div className="fxc:flex fxc:flex-col fxc:gap-2 fxc:border-b fxc:last:border-b-0 fxc:px-5 fxc:py-4">
                    {canPay && (
                        <Button variant="outline" onClick={() => setPaymentOpen(true)}>
                            {__("Record a payment")}
                        </Button>
                    )}
                    {canRefund && (
                        <Button variant="outline" onClick={() => setRefundOpen(true)}>
                            {__("Refund")}
                        </Button>
                    )}
                    {order.links.pay !== "" && (
                        <Button
                            variant="ghost"
                            onClick={() =>
                                void navigator.clipboard.writeText(order.links.pay).then(
                                    () => showToast.success(__("Payment link copied.")),
                                    () => showToast.error(__("Could not copy the link.")),
                                )
                            }
                        >
                            {__("Copy payment link")}
                        </Button>
                    )}
                </div>
            )}
            {canPay && <PaymentDialog order={order} open={paymentOpen} onOpenChange={setPaymentOpen} />}
            {canRefund && <RefundDialog order={order} open={refundOpen} onOpenChange={setRefundOpen} />}
        </Card>
    );
}

function AddressBlock({ title, address }: { title: string; address: OrderAddress | null }) {
    if (address === null) {
        return null;
    }
    const lines = [
        [address.first_name, address.last_name].filter(Boolean).join(" "),
        address.company,
        address.address_1,
        address.address_2,
        [address.postcode, address.city].filter(Boolean).join(" "),
        [address.state, address.country].filter(Boolean).join(", "),
    ].filter((l) => l !== "");
    return (
        <div className="fxc:border-b fxc:last:border-b-0 fxc:px-5 fxc:py-4 fxc:text-sm">
            <h3 className="fxc:text-muted-foreground fxc:mb-1 fxc:text-xs fxc:font-medium fxc:uppercase">{title}</h3>
            {lines.map((line, i) => (
                <div key={i}>{line}</div>
            ))}
            {address.phone !== undefined && address.phone !== "" && <div className="fxc:text-muted-foreground">{address.phone}</div>}
        </div>
    );
}

function CustomerCard({ order }: { order: OrderDetail }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>{__("Customer")}</CardTitle>
            </CardHeader>
            <div className="fxc:border-b fxc:last:border-b-0 fxc:px-5 fxc:py-4 fxc:text-sm">
                <div className="fxc:font-medium">{order.customer.name || __("Guest")}</div>
                {order.customer.email !== "" && (
                    <a href={`mailto:${order.customer.email}`} className="fxc:text-primary fxc:no-underline fxc:hover:underline">
                        {order.customer.email}
                    </a>
                )}
                {order.customer.id === null && <div className="fxc:text-muted-foreground fxc:text-xs">{__("Checked out as a guest")}</div>}
                {order.anonymized && <div className="fxc:text-muted-foreground fxc:text-xs">{__("Personal data was erased.")}</div>}
            </div>
            <AddressBlock title={__("Billing")} address={order.billing} />
            <AddressBlock title={__("Shipping")} address={order.shipping} />
            {order.customer_note !== "" && (
                <div className="fxc:border-b fxc:last:border-b-0 fxc:px-5 fxc:py-4 fxc:text-sm">
                    <h3 className="fxc:text-muted-foreground fxc:mb-1 fxc:text-xs fxc:font-medium fxc:uppercase">{__("Customer note")}</h3>
                    <p className="fxc:break-words fxc:whitespace-pre-line">{order.customer_note}</p>
                </div>
            )}
            {order.fields.length > 0 && (
                <div className="fxc:border-b fxc:last:border-b-0 fxc:px-5 fxc:py-4 fxc:text-sm">
                    <h3 className="fxc:text-muted-foreground fxc:mb-1 fxc:text-xs fxc:font-medium fxc:uppercase">{__("Additional information")}</h3>
                    <dl className="fxc:space-y-2">
                        {order.fields.map((field, i) => (
                            <div key={i}>
                                <dt className="fxc:text-muted-foreground fxc:text-xs">{field.label}</dt>
                                <dd className="fxc:break-words fxc:whitespace-pre-line">{field.value}</dd>
                            </div>
                        ))}
                    </dl>
                </div>
            )}
        </Card>
    );
}
