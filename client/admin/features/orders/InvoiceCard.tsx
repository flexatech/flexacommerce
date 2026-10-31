import { ExternalLink, FileText, Package } from "lucide-react";

import { showToast } from "@/shared/components/showToast";
import { Button } from "@/shared/components/ui/button";
import { Card, CardHeader, CardTitle } from "@/shared/components/ui/card";
import { __ } from "@/shared/lib/i18n";
import { invoicePdfUrl, useCreateInvoice, useOrderInvoice } from "../invoices/useInvoices";
import { formatDate } from "./format";
import type { OrderDetail } from "./types";

/**
 * The order's invoice and packing slip (ADR-060). Opening the invoice
 * PDF gives the order its number when it has none.
 */
export function InvoiceCard({ order }: { order: OrderDetail }) {
    const query = useOrderInvoice(order.id);
    const create = useCreateInvoice(order.id);
    const invoice = query.data?.invoice ?? null;

    return (
        <Card>
            <CardHeader>
                <CardTitle>{__("Invoice")}</CardTitle>
            </CardHeader>
            <div className="fxc:space-y-3 fxc:px-5 fxc:pb-5 fxc:text-sm">
                {query.isPending ? (
                    <p className="fxc:text-muted-foreground">{__("Loading…")}</p>
                ) : invoice ? (
                    <dl className="fxc:grid fxc:grid-cols-[auto_1fr] fxc:gap-x-3 fxc:gap-y-1">
                        <dt className="fxc:text-muted-foreground">{__("Number")}</dt>
                        <dd className="fxc:text-foreground fxc:font-medium" data-testid="invoice-number">
                            {invoice.number}
                        </dd>
                        <dt className="fxc:text-muted-foreground">{__("Date")}</dt>
                        <dd className="fxc:text-foreground">{formatDate(invoice.date, false)}</dd>
                    </dl>
                ) : (
                    <p className="fxc:text-muted-foreground">{__("No invoice yet. It gets its number when you open or send it.")}</p>
                )}
                <div className="fxc:flex fxc:flex-wrap fxc:gap-2">
                    <Button asChild variant="outline" size="sm">
                        <a href={invoicePdfUrl([order.id], "invoice")} target="_blank" rel="noopener noreferrer">
                            <FileText aria-hidden className="fxc:size-4" />
                            {__("Invoice PDF")}
                        </a>
                    </Button>
                    <Button asChild variant="outline" size="sm">
                        <a href={invoicePdfUrl([order.id], "packing_slip")} target="_blank" rel="noopener noreferrer">
                            <Package aria-hidden className="fxc:size-4" />
                            {__("Packing slip")}
                        </a>
                    </Button>
                    {!invoice && order.can.edit && !query.isPending && (
                        <Button
                            variant="ghost"
                            size="sm"
                            disabled={create.isPending}
                            onClick={() =>
                                create.mutate(undefined, {
                                    onSuccess: () => showToast.success(__("Invoice number assigned.")),
                                    onError: (e) => showToast.error(e.message),
                                })
                            }
                        >
                            {__("Assign number")}
                        </Button>
                    )}
                </div>
                {query.data?.customer_can && (
                    <a
                        href={query.data.customer_url}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="fxc:text-primary fxc:inline-flex fxc:items-center fxc:gap-1 fxc:text-xs fxc:underline"
                    >
                        {__("The customer can download it from their order")}
                        <ExternalLink aria-hidden className="fxc:size-3" />
                    </a>
                )}
            </div>
        </Card>
    );
}
