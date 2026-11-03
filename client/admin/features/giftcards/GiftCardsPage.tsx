import { useEffect, useState } from "react";
import { CalendarClock, Copy, Gift, Plus, Search } from "lucide-react";

import { showToast } from "@/shared/components/showToast";
import { Badge } from "@/shared/components/ui/badge";
import { Button } from "@/shared/components/ui/button";
import { Card, CardDescription, CardHeader, CardTitle } from "@/shared/components/ui/card";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/shared/components/ui/dialog";
import { Input } from "@/shared/components/ui/input";
import { DatePicker } from "@/shared/components/ui/date-picker";
import { Label } from "@/shared/components/ui/label";
import { Select } from "@/shared/components/ui/select";
import { Switch } from "@/shared/components/ui/switch";
import { Textarea } from "@/shared/components/ui/textarea";
import { getPluginGlobal } from "@/shared/lib/api";
import { __, sprintf } from "@/shared/lib/i18n";
import { inputToMinor } from "@/shared/lib/units";
import { EmptyState } from "../../components/EmptyState";
import { PageTitle } from "../../components/PageTitle";
import { SettingRow } from "../../components/SettingRow";
import { Tabs } from "../../components/Tabs";
import { useUrlTab } from "../../components/useUrlTab";
import { currencyExponent, formatDate, orderMoney } from "../orders/format";
import { ActivityTable, orderUrl } from "./ActivityTable";
import { useSaveSettings, useSettings } from "../settings/useSettings";
import {
    useAdjustGiftCard,
    useCreateGiftCard,
    useGiftCard,
    useGiftCardList,
    useSendGiftCard,
    useUpdateGiftCard,
    type GiftCardDetail,
    type GiftCardFilter,
    type GiftCardState,
} from "./useGiftCards";

const TABS = ["cards", "settings"] as const;

const STATE_LABEL: Record<GiftCardState, string> = {
    active: __("Active"),
    disabled: __("Disabled"),
    used: __("Used up"),
    expired: __("Expired"),
};

const STATE_VARIANT: Record<GiftCardState, "success" | "muted" | "destructive" | "brand"> = {
    active: "success",
    disabled: "muted",
    used: "brand",
    expired: "destructive",
};

function today(): string {
    const now = new Date();
    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, "0")}-${String(now.getDate()).padStart(2, "0")}`;
}

function Field({ id, label, help, children }: { id: string; label: string; help?: string; children: React.ReactNode }) {
    return (
        <div>
            <Label htmlFor={id} className="fxc:mb-2 fxc:block">
                {label}
            </Label>
            {children}
            {help && <p className="fxc:text-muted-foreground fxc:mt-1 fxc:text-xs">{help}</p>}
        </div>
    );
}

function NewCardDialog({ open, onClose, onCreated, expiryMonths }: { open: boolean; onClose: () => void; onCreated: (id: number) => void; expiryMonths: number }) {
    const { currency } = getPluginGlobal();
    const create = useCreateGiftCard();
    const [amount, setAmount] = useState("");
    const [email, setEmail] = useState("");
    const [name, setName] = useState("");
    const [sender, setSender] = useState("");
    const [message, setMessage] = useState("");
    const [deliverOn, setDeliverOn] = useState("");
    const [expiresOn, setExpiresOn] = useState<string | null>(null);
    const [send, setSend] = useState(true);
    const [note, setNote] = useState("");
    const [error, setError] = useState("");

    useEffect(() => {
        if (open) {
            setAmount("");
            setEmail("");
            setName("");
            setSender("");
            setMessage("");
            setDeliverOn("");
            setExpiresOn(null);
            setSend(true);
            setNote("");
            setError("");
        }
    }, [open]);

    const submit = () => {
        const minor = inputToMinor(amount, currency.exponent);
        if (minor === null || Number.isNaN(minor) || minor <= 0) {
            setError(__("Enter the card value."));
            return;
        }
        setError("");
        create.mutate(
            {
                amount: minor,
                recipient_email: email.trim(),
                recipient_name: name,
                sender_name: sender,
                message,
                deliver_on: deliverOn,
                ...(expiresOn !== null ? { expires_on: expiresOn } : {}),
                send,
                note,
            },
            {
                onSuccess: (card) => {
                    showToast.success(card.delivered_at !== null ? sprintf(__("Gift card made and sent to %s."), card.recipient_email) : __("Gift card made."));
                    onCreated(card.id);
                },
                onError: (failure) => setError(failure.message),
            },
        );
    };

    const defaultExpiry =
        expiryMonths > 0 ? sprintf(__("Empty uses the store default: %d months from today."), expiryMonths) : __("Empty means the card never expires.");

    return (
        <Dialog open={open} onOpenChange={(next) => !next && !create.isPending && onClose()}>
            <DialogContent className="fxc:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{__("New gift card")}</DialogTitle>
                    <DialogDescription>{__("Make a card for a customer, a giveaway or a goodwill gesture. The code is made for you.")}</DialogDescription>
                </DialogHeader>
                <div className="fxc:space-y-4">
                    <Field id="fxc-gc-amount" label={sprintf(__("Value (%s)"), currency.code)}>
                        <Input id="fxc-gc-amount" inputMode="decimal" className="fxc:w-40" value={amount} onChange={(e) => setAmount(e.target.value)} />
                    </Field>
                    <div className="fxc:grid fxc:gap-4 fxc:sm:grid-cols-2">
                        <Field id="fxc-gc-email" label={__("Recipient email")}>
                            <Input id="fxc-gc-email" type="email" value={email} onChange={(e) => setEmail(e.target.value)} />
                        </Field>
                        <Field id="fxc-gc-name" label={__("Recipient name")}>
                            <Input id="fxc-gc-name" maxLength={200} value={name} onChange={(e) => setName(e.target.value)} />
                        </Field>
                        <Field id="fxc-gc-sender" label={__("From")}>
                            <Input id="fxc-gc-sender" maxLength={200} placeholder={__("The store name")} value={sender} onChange={(e) => setSender(e.target.value)} />
                        </Field>
                        <Field id="fxc-gc-deliver" label={__("Send on")} help={__("Empty sends it now.")}>
                            <DatePicker id="fxc-gc-deliver" min={today()} value={deliverOn} onChange={(value) => setDeliverOn(value)} />
                        </Field>
                    </div>
                    <Field id="fxc-gc-message" label={__("Message")}>
                        <Textarea id="fxc-gc-message" rows={3} maxLength={1000} value={message} onChange={(e) => setMessage(e.target.value)} />
                    </Field>
                    <Field id="fxc-gc-expires" label={__("Expires after")} help={expiresOn === null || expiresOn === "" ? defaultExpiry : undefined}>
                        <DatePicker id="fxc-gc-expires" min={today()} className="fxc:w-48" value={expiresOn ?? ""} onChange={(value) => setExpiresOn(value)} />
                    </Field>
                    <Field id="fxc-gc-note" label={__("Private note")} help={__("Shown in the card history, never to the customer.")}>
                        <Input id="fxc-gc-note" maxLength={500} value={note} onChange={(e) => setNote(e.target.value)} />
                    </Field>
                    <div className="fxc:flex fxc:items-center fxc:gap-3">
                        <Switch id="fxc-gc-send" checked={send} onCheckedChange={setSend} />
                        <Label htmlFor="fxc-gc-send">{__("Email the code to the recipient")}</Label>
                    </div>
                    {error !== "" && (
                        <p role="alert" className="fxc:text-destructive fxc:text-sm">
                            {error}
                        </p>
                    )}
                </div>
                <DialogFooter>
                    <Button variant="outline" disabled={create.isPending} onClick={onClose}>
                        {__("Cancel")}
                    </Button>
                    <Button disabled={create.isPending} onClick={submit}>
                        {create.isPending ? __("Saving…") : __("Make gift card")}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function Activity({ card }: { card: GiftCardDetail }) {
    if (card.activity.length === 0) {
        return null;
    }
    return (
        <div>
            <h3 className="fxc:mb-2 fxc:text-sm fxc:font-semibold">{__("History")}</h3>
            <ActivityTable rows={card.activity} currency={card.currency} />
        </div>
    );
}

function CardDialog({ id, onClose }: { id: number | null; onClose: () => void }) {
    const query = useGiftCard(id);
    const card = query.data;
    return (
        <Dialog open={id !== null} onOpenChange={(next) => !next && onClose()}>
            <DialogContent className="fxc:max-w-2xl">
                {query.isError ? (
                    <p className="fxc:text-destructive">{query.error.message}</p>
                ) : !card || card.id !== id ? (
                    <>
                        <DialogTitle className="fxc:sr-only">{__("Gift card")}</DialogTitle>
                        <p className="fxc:text-muted-foreground">{__("Loading…")}</p>
                    </>
                ) : (
                    <CardEditor key={card.id} card={card} onClose={onClose} />
                )}
            </DialogContent>
        </Dialog>
    );
}

function CardEditor({ card, onClose }: { card: GiftCardDetail; onClose: () => void }) {
    const update = useUpdateGiftCard(card.id);
    const adjust = useAdjustGiftCard(card.id);
    const resend = useSendGiftCard(card.id);
    const exponent = currencyExponent(card.currency);
    const [email, setEmail] = useState(card.recipient_email);
    const [name, setName] = useState(card.recipient_name);
    const [expiresOn, setExpiresOn] = useState(card.expires_on);
    const [direction, setDirection] = useState<"add" | "take">("add");
    const [change, setChange] = useState("");
    const [note, setNote] = useState("");
    const [adjustError, setAdjustError] = useState("");

    const dirty = email !== card.recipient_email || name !== card.recipient_name || expiresOn !== card.expires_on;

    const save = () => {
        update.mutate(
            { recipient_email: email.trim(), recipient_name: name, expires_on: expiresOn },
            {
                onSuccess: () => showToast.success(__("Gift card saved.")),
                onError: (failure) => showToast.error(failure.message),
            },
        );
    };

    const toggle = (on: boolean) =>
        update.mutate(
            { status: on ? "active" : "disabled" },
            {
                onSuccess: () => showToast.success(on ? __("Gift card turned on.") : __("Gift card turned off. It cannot pay until you turn it on again.")),
                onError: (failure) => showToast.error(failure.message),
            },
        );

    const applyAdjust = () => {
        const minor = inputToMinor(change, exponent);
        if (minor === null || Number.isNaN(minor) || minor <= 0) {
            setAdjustError(__("Enter an amount."));
            return;
        }
        setAdjustError("");
        adjust.mutate(
            { amount: direction === "add" ? minor : -minor, note },
            {
                onSuccess: () => {
                    setChange("");
                    setNote("");
                    showToast.success(__("Balance changed."));
                },
                onError: (failure) => setAdjustError(failure.message),
            },
        );
    };

    const copy = () => {
        void navigator.clipboard?.writeText(card.code).then(() => showToast.success(__("Code copied.")));
    };

    return (
        <>
            <DialogHeader>
                <DialogTitle className="fxc:flex fxc:flex-wrap fxc:items-center fxc:gap-2">
                    <span className="fxc:font-mono fxc:tracking-wide">{card.code}</span>
                    <Button variant="ghost" size="icon" aria-label={__("Copy the code")} onClick={copy}>
                        <Copy aria-hidden className="fxc:size-4" />
                    </Button>
                    <Badge variant={STATE_VARIANT[card.state]}>{STATE_LABEL[card.state]}</Badge>
                </DialogTitle>
                <DialogDescription>
                    {sprintf(__("%1$s left of %2$s."), orderMoney(card.balance, card.currency), orderMoney(card.initial_amount, card.currency))}{" "}
                    {card.order_id !== null && (
                        <a href={orderUrl(card.order_id)} className="fxc:text-primary fxc:no-underline fxc:hover:underline">
                            {sprintf(__("Bought in order #%d."), card.order_id)}
                        </a>
                    )}
                </DialogDescription>
            </DialogHeader>

            <div className="fxc:max-h-[60vh] fxc:space-y-5 fxc:overflow-y-auto">
                <div className="fxc:flex fxc:items-center fxc:gap-3">
                    <Switch id="fxc-gc-active" checked={card.status === "active"} disabled={update.isPending} onCheckedChange={toggle} />
                    <Label htmlFor="fxc-gc-active">{__("Card can pay")}</Label>
                </div>

                <div className="fxc:grid fxc:gap-4 fxc:sm:grid-cols-3">
                    <Field id="fxc-gc-edit-email" label={__("Recipient email")}>
                        <Input id="fxc-gc-edit-email" type="email" value={email} onChange={(e) => setEmail(e.target.value)} />
                    </Field>
                    <Field id="fxc-gc-edit-name" label={__("Recipient name")}>
                        <Input id="fxc-gc-edit-name" maxLength={200} value={name} onChange={(e) => setName(e.target.value)} />
                    </Field>
                    <Field id="fxc-gc-edit-expires" label={__("Expires after")} help={expiresOn === "" ? __("Never expires.") : undefined}>
                        <DatePicker id="fxc-gc-edit-expires" value={expiresOn} onChange={(value) => setExpiresOn(value)} />
                    </Field>
                </div>
                <div className="fxc:flex fxc:flex-wrap fxc:items-center fxc:justify-between fxc:gap-3">
                    <p className="fxc:text-muted-foreground fxc:text-xs">
                        {card.delivered_at !== null
                            ? sprintf(__("Emailed %s."), formatDate(card.delivered_at))
                            : card.deliver_at !== null
                              ? sprintf(__("Will be emailed %s."), formatDate(card.deliver_at))
                              : card.recipient_email === ""
                                ? __("No recipient email. The buyer got the code.")
                                : __("Not emailed yet.")}
                    </p>
                    <div className="fxc:flex fxc:gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={resend.isPending || dirty || card.recipient_email === "" || card.status !== "active"}
                            onClick={() =>
                                resend.mutate(undefined, {
                                    onSuccess: () => showToast.success(sprintf(__("Gift card sent to %s."), card.recipient_email)),
                                    onError: (failure) => showToast.error(failure.message),
                                })
                            }
                        >
                            {resend.isPending ? __("Sending…") : card.delivered_at !== null ? __("Send again") : __("Send now")}
                        </Button>
                        <Button size="sm" disabled={!dirty || update.isPending} onClick={save}>
                            {update.isPending ? __("Saving…") : __("Save changes")}
                        </Button>
                    </div>
                </div>

                <div className="fxc:rounded-md fxc:border fxc:p-4">
                    <h3 className="fxc:mb-3 fxc:text-sm fxc:font-semibold">{__("Change the balance")}</h3>
                    <div className="fxc:flex fxc:flex-wrap fxc:items-end fxc:gap-3">
                        <Select
                            aria-label={__("Add or take off")}
                            value={direction}
                            onChange={(e) => setDirection(e.target.value === "take" ? "take" : "add")}
                            options={[
                                { value: "add", label: __("Add") },
                                { value: "take", label: __("Take off") },
                            ]}
                        />
                        <Input aria-label={__("Amount")} inputMode="decimal" className="fxc:w-32" value={change} onChange={(e) => setChange(e.target.value)} />
                        <Input aria-label={__("Reason")} placeholder={__("Reason (optional)")} maxLength={500} className="fxc:min-w-48 fxc:flex-1" value={note} onChange={(e) => setNote(e.target.value)} />
                        <Button variant="outline" disabled={adjust.isPending} onClick={applyAdjust}>
                            {adjust.isPending ? __("Saving…") : __("Apply")}
                        </Button>
                    </div>
                    {adjustError !== "" && (
                        <p role="alert" className="fxc:text-destructive fxc:mt-2 fxc:text-sm">
                            {adjustError}
                        </p>
                    )}
                </div>

                {card.message !== "" && (
                    <div>
                        <h3 className="fxc:mb-1 fxc:text-sm fxc:font-semibold">{__("Message")}</h3>
                        <p className="fxc:text-muted-foreground fxc:text-sm fxc:whitespace-pre-line">{card.message}</p>
                    </div>
                )}

                <Activity card={card} />
            </div>

            <DialogFooter>
                <Button variant="outline" onClick={onClose}>
                    {__("Close")}
                </Button>
            </DialogFooter>
        </>
    );
}

function CardsPanel() {
    const [search, setSearch] = useState("");
    const [query, setQuery] = useState("");
    const [status, setStatus] = useState<GiftCardFilter>("");
    const [page, setPage] = useState(1);
    const [creating, setCreating] = useState(false);
    const [open, setOpen] = useState<number | null>(null);
    const list = useGiftCardList(query, status, page);
    const pages = list.data?.pages ?? 1;

    useEffect(() => {
        const timer = window.setTimeout(() => {
            setQuery(search.trim());
            setPage(1);
        }, 300);
        return () => window.clearTimeout(timer);
    }, [search]);

    return (
        <div className="fxc:space-y-4">
            <div className="fxc:flex fxc:flex-wrap fxc:items-center fxc:justify-between fxc:gap-3">
                <div className="fxc:relative fxc:w-full fxc:max-w-sm">
                    <Search aria-hidden className="fxc:text-muted-foreground fxc:pointer-events-none fxc:absolute fxc:top-1/2 fxc:left-3 fxc:size-4 fxc:-translate-y-1/2" />
                    <Input
                        type="search"
                        aria-label={__("Search gift cards")}
                        placeholder={__("Code, email, name or order number")}
                        className="fxc:pl-9!"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                    />
                </div>
                <Button onClick={() => setCreating(true)}>
                    <Plus aria-hidden className="fxc:size-4" />
                    {__("New gift card")}
                </Button>
            </div>

            <Tabs
                value={status}
                onChange={(next: GiftCardFilter) => {
                    setStatus(next);
                    setPage(1);
                }}
                items={[
                    { value: "", label: __("All") },
                    { value: "active", label: STATE_LABEL.active },
                    { value: "used", label: STATE_LABEL.used },
                    { value: "expired", label: STATE_LABEL.expired },
                    { value: "disabled", label: STATE_LABEL.disabled },
                ]}
            />

            <Card className="fxc:overflow-x-auto">
                {list.isPending ? (
                    <p className="fxc:text-muted-foreground fxc:px-5 fxc:py-4">{__("Loading…")}</p>
                ) : list.isError ? (
                    <p className="fxc:text-destructive fxc:px-5 fxc:py-4">{list.error.message}</p>
                ) : list.data.items.length === 0 ? (
                    <EmptyState
                        icon={Gift}
                        filtered={status !== "" || query !== ""}
                        title={status !== "" || query !== "" ? __("No gift cards match.") : __("No gift cards yet.")}
                        description={__("Turn on Gift card on a product to sell them, or make one here.")}
                    />
                ) : (
                    <table className="fxc:w-full fxc:text-sm">
                        <thead>
                            <tr className="fxc:text-muted-foreground fxc:border-b fxc:text-left fxc:text-xs">
                                <th className="fxc:px-5 fxc:py-2 fxc:font-medium">{__("Code")}</th>
                                <th className="fxc:px-3 fxc:py-2 fxc:font-medium">{__("Recipient")}</th>
                                <th className="fxc:px-3 fxc:py-2 fxc:text-right fxc:font-medium">{__("Balance")}</th>
                                <th className="fxc:px-3 fxc:py-2 fxc:font-medium">{__("Status")}</th>
                                <th className="fxc:px-5 fxc:py-2 fxc:font-medium">{__("Created")}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {list.data.items.map((card) => (
                                <tr key={card.id} className="fxc:hover:bg-muted/50 fxc:border-b fxc:last:border-b-0">
                                    <td className="fxc:px-5 fxc:py-3">
                                        <button
                                            type="button"
                                            className="fxc:text-primary fxc:cursor-pointer fxc:bg-transparent fxc:p-0 fxc:text-left fxc:font-mono fxc:hover:underline"
                                            onClick={() => setOpen(card.id)}
                                        >
                                            {card.code}
                                        </button>
                                        {card.order_id !== null && (
                                            <div className="fxc:text-muted-foreground fxc:text-xs">{sprintf(__("Order #%d"), card.order_id)}</div>
                                        )}
                                    </td>
                                    <td className="fxc:px-3 fxc:py-3">
                                        <div>{card.recipient_name || card.recipient_email || "—"}</div>
                                        {card.recipient_name !== "" && <div className="fxc:text-muted-foreground fxc:text-xs">{card.recipient_email}</div>}
                                    </td>
                                    <td className="fxc:px-3 fxc:py-3 fxc:text-right fxc:tabular-nums fxc:whitespace-nowrap">
                                        <div>{orderMoney(card.balance, card.currency)}</div>
                                        <div className="fxc:text-muted-foreground fxc:text-xs">{sprintf(__("of %s"), orderMoney(card.initial_amount, card.currency))}</div>
                                    </td>
                                    <td className="fxc:px-3 fxc:py-3">
                                        <Badge variant={STATE_VARIANT[card.state]}>{STATE_LABEL[card.state]}</Badge>
                                        {card.expires_at !== null && card.state === "active" && (
                                            <div className="fxc:text-muted-foreground fxc:mt-1 fxc:text-xs">{sprintf(__("Until %s"), formatDate(card.expires_at, false))}</div>
                                        )}
                                    </td>
                                    <td className="fxc:text-muted-foreground fxc:px-5 fxc:py-3 fxc:whitespace-nowrap">{formatDate(card.created_at, false)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </Card>

            {pages > 1 && (
                <div className="fxc:flex fxc:items-center fxc:justify-end fxc:gap-3 fxc:text-sm">
                    <span className="fxc:text-muted-foreground">{sprintf(__("Page %1$d of %2$d"), page, pages)}</span>
                    <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => setPage(page - 1)}>
                        {__("Previous")}
                    </Button>
                    <Button variant="outline" size="sm" disabled={page >= pages} onClick={() => setPage(page + 1)}>
                        {__("Next")}
                    </Button>
                </div>
            )}

            <NewCardDialog
                open={creating}
                expiryMonths={list.data?.expiry_months ?? 0}
                onClose={() => setCreating(false)}
                onCreated={(id) => {
                    setCreating(false);
                    setOpen(id);
                }}
            />
            <CardDialog id={open} onClose={() => setOpen(null)} />
        </div>
    );
}

function SettingsPanel() {
    const query = useSettings();
    const save = useSaveSettings();
    const [months, setMonths] = useState<string | null>(null);

    if (query.isError) {
        return <p className="fxc:text-destructive">{query.error.message}</p>;
    }
    if (query.isPending) {
        return <p className="fxc:text-muted-foreground">{__("Loading…")}</p>;
    }
    const saved = query.data.settings.gift_card_expiry_months;
    const value = months ?? String(saved);
    const parsed = Math.min(120, Math.max(0, Number.parseInt(value, 10) || 0));

    return (
        <div className="fxc:space-y-6">
            <div className="fxc:flex fxc:items-center fxc:justify-end">
                <Button
                    disabled={parsed === saved || save.isPending}
                    onClick={() =>
                        save.mutate(
                            { gift_card_expiry_months: parsed },
                            {
                                onSuccess: () => {
                                    setMonths(null);
                                    showToast.success(__("Gift card settings saved."));
                                },
                                onError: (failure) => showToast.error(failure.message),
                            },
                        )
                    }
                >
                    {save.isPending ? __("Saving…") : __("Save changes")}
                </Button>
            </div>
            <Card>
                <CardHeader>
                    <CardTitle>{__("Gift cards")}</CardTitle>
                    <CardDescription>
                        {__("Turn on Gift card on a product to sell cards; its variations can be the amounts. Each one bought makes a code that is emailed to the recipient, on the day the buyer picked. Shoppers enter the code in the coupon box of the cart or checkout.")}
                    </CardDescription>
                </CardHeader>
                <SettingRow
                    icon={CalendarClock}
                    label={__("Cards expire after (months)")}
                    htmlFor="fxc-gc-expiry"
                    description={__("Counted from the day the card is made. 0 means cards never expire. Some places do not allow gift cards to expire; check the rules where you sell. Changing this does not touch cards already made.")}
                    control={<Input id="fxc-gc-expiry" type="number" min={0} max={120} className="fxc:w-24" value={value} onChange={(e) => setMonths(e.target.value)} />}
                />
            </Card>
        </div>
    );
}

export function GiftCardsPage() {
    const [tab, setTab] = useUrlTab(TABS);
    return (
        <div className="fxc:space-y-4">
            <PageTitle title={__("Gift cards")} description={__("Cards sold in the store and cards you made. A card pays part or all of an order until its balance runs out.")} />
            <Tabs
                value={tab}
                onChange={setTab}
                items={[
                    { value: "cards", label: __("Cards") },
                    { value: "settings", label: __("Settings") },
                ]}
            />
            {tab === "cards" ? <CardsPanel /> : <SettingsPanel />}
        </div>
    );
}
