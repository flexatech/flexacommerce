import { useEffect, useState } from "react";
import { ChevronDown, CreditCard } from "lucide-react";

import { showToast } from "@/shared/components/showToast";
import { Badge } from "@/shared/components/ui/badge";
import { Button } from "@/shared/components/ui/button";
import { Card } from "@/shared/components/ui/card";
import { cn } from "@/shared/lib/cn";
import { __ } from "@/shared/lib/i18n";
import { FieldRow, type FieldValues } from "../../components/FieldControl";
import { useGateways, useSaveGateway, type Gateway } from "./usePayments";

export function PaymentsPanel() {
    const gateways = useGateways();
    const [open, setOpen] = useState<string>("");

    if (gateways.isPending) {
        return <p className="fxc:text-muted-foreground">{__("Loading payment methods…")}</p>;
    }
    if (gateways.isError) {
        return <p className="fxc:text-destructive">{gateways.error.message}</p>;
    }
    if (gateways.data.length === 0) {
        return <p className="fxc:text-muted-foreground">{__("No payment methods are installed.")}</p>;
    }

    return (
        <div className="fxc:space-y-3">
            <p className="fxc:text-muted-foreground fxc:text-sm">
                {__("Shoppers see the enabled methods at checkout, in this order.")}
            </p>
            {gateways.data.map((gateway) => (
                <GatewayCard
                    key={gateway.id}
                    gateway={gateway}
                    open={open === gateway.id}
                    onToggle={() => setOpen(open === gateway.id ? "" : gateway.id)}
                />
            ))}
        </div>
    );
}

function GatewayCard({ gateway, open, onToggle }: { gateway: Gateway; open: boolean; onToggle: () => void }) {
    const save = useSaveGateway();
    const [draft, setDraft] = useState<FieldValues>(gateway.values);

    useEffect(() => setDraft(gateway.values), [gateway.values]);

    const dirty = gateway.fields.some((f) => draft[f.id] !== gateway.values[f.id]);

    const submit = () =>
        save.mutate(
            { id: gateway.id, values: draft },
            {
                onSuccess: () => showToast.success(__("Payment method saved.")),
                onError: (e) => showToast.error(e.message),
            },
        );

    return (
        <Card>
            <button
                type="button"
                aria-expanded={open}
                onClick={onToggle}
                className="fxc:hover:bg-muted/50 fxc:flex fxc:w-full fxc:cursor-pointer fxc:items-center fxc:gap-3 fxc:bg-transparent fxc:px-5 fxc:py-4 fxc:text-left fxc:outline-none fxc:focus-visible:ring-2 fxc:focus-visible:ring-primary-accent"
            >
                <div className="fxc:bg-muted fxc:text-primary fxc:flex fxc:h-9 fxc:w-9 fxc:shrink-0 fxc:items-center fxc:justify-center fxc:rounded-lg">
                    <CreditCard aria-hidden className="fxc:size-4" />
                </div>
                <div className="fxc:min-w-0 fxc:flex-1">
                    <div className="fxc:text-foreground fxc:text-sm fxc:font-semibold">{gateway.title}</div>
                    <div className="fxc:text-muted-foreground fxc:font-mono fxc:text-xs">{gateway.id}</div>
                </div>
                {gateway.supports.refunds && <Badge variant="muted">{__("Refunds")}</Badge>}
                <Badge variant={gateway.enabled ? "success" : "muted"}>{gateway.enabled ? __("Enabled") : __("Off")}</Badge>
                <ChevronDown aria-hidden className={cn("fxc:text-muted-foreground fxc:size-4 fxc:transition-transform", open && "fxc:rotate-180")} />
            </button>
            {open && (
                <div className="fxc:space-y-4 fxc:border-t fxc:px-5 fxc:py-4">
                    {gateway.fields.map((field) => (
                        <FieldRow
                            key={field.id}
                            field={field}
                            id={`fxc-gateway-${gateway.id}-${field.id}`}
                            value={draft[field.id]}
                            onChange={(value) => setDraft({ ...draft, [field.id]: value })}
                        />
                    ))}
                    <div className="fxc:flex fxc:justify-end fxc:gap-2 fxc:pt-2">
                        {dirty && (
                            <Button variant="ghost" onClick={() => setDraft(gateway.values)} disabled={save.isPending}>
                                {__("Discard")}
                            </Button>
                        )}
                        <Button onClick={submit} disabled={!dirty || save.isPending}>
                            {save.isPending ? __("Saving…") : __("Save")}
                        </Button>
                    </div>
                </div>
            )}
        </Card>
    );
}
