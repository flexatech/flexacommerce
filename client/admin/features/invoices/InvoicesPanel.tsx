import { useEffect, useMemo, useState } from "react";
import { Barcode, Eye, FileText, Hash, ImageIcon, Paperclip, RotateCcw, Ruler } from "lucide-react";

import { showToast } from "@/shared/components/showToast";
import { Button } from "@/shared/components/ui/button";
import { Card, CardDescription, CardHeader, CardTitle } from "@/shared/components/ui/card";
import { Input } from "@/shared/components/ui/input";
import { Select } from "@/shared/components/ui/select";
import { Switch } from "@/shared/components/ui/switch";
import { Textarea } from "@/shared/components/ui/textarea";
import { __, sprintf } from "@/shared/lib/i18n";
import { canPickImage, pickImage } from "../../components/pickImage";
import { SaveBar } from "../../components/SaveBar";
import { SettingRow } from "../../components/SettingRow";
import { useInvoiceSettings, useSaveInvoiceSettings, type InvoiceSettings, type InvoiceSettingsChanges } from "./useInvoices";

function toInt(value: string): number {
    const n = Number.parseInt(value, 10);
    return Number.isFinite(n) ? n : 0;
}

/** A preview of a number with the placeholders filled in, as the server does it. */
function preview(draft: InvoiceSettings, counter: number, credit = false): string {
    const now = new Date();
    const fill = (text: string) =>
        text.replaceAll("{year}", String(now.getFullYear())).replaceAll("{month}", String(now.getMonth() + 1).padStart(2, "0"));
    return (
        fill(credit ? draft.credit_prefix : draft.prefix) +
        String(counter).padStart(draft.padding, "0") +
        fill(credit ? draft.credit_suffix : draft.suffix)
    );
}

export function InvoicesPanel() {
    const query = useInvoiceSettings();
    const save = useSaveInvoiceSettings();
    const [draft, setDraft] = useState<InvoiceSettings | null>(null);
    const [next, setNext] = useState<number>(1);
    const [nextCredit, setNextCredit] = useState<number>(1);
    const [logoUrl, setLogoUrl] = useState<string>("");

    const data = query.data;
    useEffect(() => {
        if (data) {
            setDraft(data.settings);
            setNext(data.next_number);
            setNextCredit(data.next_credit);
            setLogoUrl(data.logo_url);
        }
    }, [data]);

    const changes = useMemo(() => {
        const out: InvoiceSettingsChanges = {};
        if (!draft || !data) {
            return out;
        }
        for (const key of Object.keys(draft) as (keyof InvoiceSettings)[]) {
            const a = draft[key];
            const b = data.settings[key];
            if (Array.isArray(a) && Array.isArray(b) ? a.join(",") !== b.join(",") : a !== b) {
                Object.assign(out, { [key]: a });
            }
        }
        if (next !== data.next_number) {
            out.next_number = next;
        }
        if (nextCredit !== data.next_credit) {
            out.next_credit = nextCredit;
        }
        return out;
    }, [draft, data, next, nextCredit]);
    const dirty = Object.keys(changes).length > 0;

    if (query.isPending || !draft || !data) {
        return <p className="fxc:text-muted-foreground">{__("Loading invoice settings…")}</p>;
    }
    if (query.isError) {
        return <p className="fxc:text-destructive">{query.error.message}</p>;
    }

    const set = <K extends keyof InvoiceSettings>(key: K, value: InvoiceSettings[K]) => setDraft({ ...draft, [key]: value });

    const submit = () =>
        save.mutate(changes, {
            onSuccess: () => showToast.success(__("Invoice settings saved.")),
            onError: (error) => showToast.error(error.message),
        });

    const toggleEmail = (id: string, on: boolean) =>
        set("attach_to", on ? [...draft.attach_to, id] : draft.attach_to.filter((e) => e !== id));

    const chooseLogo = () =>
        pickImage(__("Invoice logo"), (image) => {
            set("logo_id", image.id);
            setLogoUrl(image.url);
        });

    return (
        <div className="fxc:space-y-6">

            <Card>
                <CardHeader>
                    <CardTitle>{__("Numbering")}</CardTitle>
                    <CardDescription>
                        {sprintf(
                            __("An order gets its invoice number the first time its invoice is made. Next number: %s"),
                            preview(draft, next),
                        )}
                    </CardDescription>
                </CardHeader>
                <SettingRow
                    icon={Hash}
                    label={__("Prefix")}
                    htmlFor="fxc-invoice-prefix"
                    description={__("You can use {year} and {month}, for example INV-{year}-.")}
                    control={
                        <Input
                            id="fxc-invoice-prefix"
                            className="fxc:w-40"
                            maxLength={40}
                            value={draft.prefix}
                            onChange={(e) => set("prefix", e.target.value)}
                        />
                    }
                />
                <SettingRow
                    icon={Hash}
                    label={__("Suffix")}
                    htmlFor="fxc-invoice-suffix"
                    control={
                        <Input
                            id="fxc-invoice-suffix"
                            className="fxc:w-40"
                            maxLength={40}
                            value={draft.suffix}
                            onChange={(e) => set("suffix", e.target.value)}
                        />
                    }
                />
                <SettingRow
                    icon={Ruler}
                    label={__("Minimum digits")}
                    htmlFor="fxc-invoice-padding"
                    description={__("Numbers are padded with zeros, so 4 digits gives 0042.")}
                    control={
                        <Input
                            id="fxc-invoice-padding"
                            type="number"
                            min={0}
                            max={12}
                            className="fxc:w-24"
                            value={draft.padding}
                            onChange={(e) => set("padding", Math.max(0, Math.min(12, toInt(e.target.value))))}
                        />
                    }
                />
                <SettingRow
                    icon={Hash}
                    label={__("Next number")}
                    htmlFor="fxc-invoice-next"
                    description={__("Can only go up, so no number is used twice. Use it to continue from your old system.")}
                    control={
                        <Input
                            id="fxc-invoice-next"
                            type="number"
                            min={data.next_number}
                            className="fxc:w-32"
                            value={next}
                            onChange={(e) => setNext(Math.max(1, toInt(e.target.value)))}
                        />
                    }
                />
                <SettingRow
                    icon={RotateCcw}
                    label={__("Start again each year")}
                    htmlFor="fxc-invoice-reset"
                    description={__("Numbers start from 1 on 1 January. Put {year} in the prefix so numbers stay unique.")}
                    control={
                        <Select
                            id="fxc-invoice-reset"
                            value={draft.reset}
                            onChange={(e) => set("reset", e.target.value as InvoiceSettings["reset"])}
                            options={[
                                { value: "never", label: __("Never") },
                                { value: "yearly", label: __("Every year") },
                            ]}
                        />
                    }
                />
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>{__("Credit notes")}</CardTitle>
                    <CardDescription>
                        {sprintf(
                            __("Each refund gets its own credit note, numbered the first time it is made. Next number: %s"),
                            preview(draft, nextCredit, true),
                        )}
                    </CardDescription>
                </CardHeader>
                <SettingRow
                    icon={Hash}
                    label={__("Prefix")}
                    htmlFor="fxc-credit-prefix"
                    description={__("You can use {year} and {month}. The minimum digits and the yearly restart are the same as for invoices.")}
                    control={
                        <Input
                            id="fxc-credit-prefix"
                            className="fxc:w-40"
                            maxLength={40}
                            value={draft.credit_prefix}
                            onChange={(e) => set("credit_prefix", e.target.value)}
                        />
                    }
                />
                <SettingRow
                    icon={Hash}
                    label={__("Suffix")}
                    htmlFor="fxc-credit-suffix"
                    control={
                        <Input
                            id="fxc-credit-suffix"
                            className="fxc:w-40"
                            maxLength={40}
                            value={draft.credit_suffix}
                            onChange={(e) => set("credit_suffix", e.target.value)}
                        />
                    }
                />
                <SettingRow
                    icon={Hash}
                    label={__("Next number")}
                    htmlFor="fxc-credit-next"
                    description={__("Can only go up, so no number is used twice.")}
                    control={
                        <Input
                            id="fxc-credit-next"
                            type="number"
                            min={data.next_credit}
                            className="fxc:w-32"
                            value={nextCredit}
                            onChange={(e) => setNextCredit(Math.max(1, toInt(e.target.value)))}
                        />
                    }
                />
                <SettingRow
                    icon={Paperclip}
                    label={__("Attach to the Refunded order email")}
                    description={__("The refund gets its credit note number when the email is sent.")}
                    control={
                        <Switch
                            aria-label={__("Attach to the Refunded order email")}
                            checked={draft.credit_attach}
                            onCheckedChange={(v) => set("credit_attach", v)}
                        />
                    }
                />
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>{__("Customers and emails")}</CardTitle>
                </CardHeader>
                <SettingRow
                    icon={Eye}
                    label={__("Invoice link for customers")}
                    htmlFor="fxc-invoice-access"
                    description={__("Shown on the order confirmation and on the order in My account.")}
                    control={
                        <Select
                            id="fxc-invoice-access"
                            value={draft.customer_access}
                            onChange={(e) => set("customer_access", e.target.value as InvoiceSettings["customer_access"])}
                            options={[
                                { value: "paid", label: __("When the order is paid") },
                                { value: "invoiced", label: __("After you make the invoice") },
                                { value: "never", label: __("Never") },
                            ]}
                        />
                    }
                />
                <div className="fxc:flex fxc:items-start fxc:gap-3 fxc:px-5 fxc:py-4">
                    <div className="fxc:bg-muted fxc:text-primary fxc:flex fxc:h-9 fxc:w-9 fxc:shrink-0 fxc:items-center fxc:justify-center fxc:rounded-lg">
                        <Paperclip aria-hidden className="fxc:h-4 fxc:w-4" />
                    </div>
                    <div className="fxc:min-w-0 fxc:flex-1">
                        <p className="fxc:text-foreground fxc:text-sm fxc:font-medium">{__("Attach the invoice to")}</p>
                        <p className="fxc:text-muted-foreground fxc:text-xs">{__("The order gets its invoice number when the first of these emails is sent.")}</p>
                        <div className="fxc:mt-3 fxc:grid fxc:gap-2 fxc:sm:grid-cols-2">
                            {data.emails.map((email) => (
                                <label key={email.id} className="fxc:text-foreground fxc:flex fxc:items-center fxc:gap-2 fxc:text-sm">
                                    <input
                                        type="checkbox"
                                        className="flexacommerce-check"
                                        checked={draft.attach_to.includes(email.id)}
                                        onChange={(e) => toggleEmail(email.id, e.target.checked)}
                                    />
                                    {email.title}
                                </label>
                            ))}
                        </div>
                    </div>
                </div>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>{__("Layout")}</CardTitle>
                    <CardDescription>{__("Empty fields use the site title and the store address from the general settings.")}</CardDescription>
                </CardHeader>
                <SettingRow
                    icon={ImageIcon}
                    label={__("Logo")}
                    description={__("Shown at the top left, up to about 6 cm wide.")}
                    control={
                        <div className="fxc:flex fxc:items-center fxc:gap-3">
                            {draft.logo_id > 0 && logoUrl !== "" && (
                                <img src={logoUrl} alt="" className="fxc:h-10 fxc:w-auto fxc:max-w-32 fxc:rounded fxc:border fxc:object-contain" />
                            )}
                            {canPickImage() && (
                                <Button variant="outline" size="sm" onClick={chooseLogo}>
                                    {draft.logo_id > 0 ? __("Change") : __("Choose image")}
                                </Button>
                            )}
                            {draft.logo_id > 0 && (
                                <Button variant="ghost" size="sm" onClick={() => set("logo_id", 0)}>
                                    {__("Remove")}
                                </Button>
                            )}
                        </div>
                    }
                />
                <SettingRow
                    icon={FileText}
                    label={__("Shop name")}
                    htmlFor="fxc-invoice-shop-name"
                    control={
                        <Input
                            id="fxc-invoice-shop-name"
                            className="fxc:w-64"
                            maxLength={200}
                            placeholder={__("Site title")}
                            value={draft.shop_name}
                            onChange={(e) => set("shop_name", e.target.value)}
                        />
                    }
                />
                <TextRow
                    id="fxc-invoice-address"
                    label={__("Shop address")}
                    value={draft.shop_address}
                    onChange={(v) => set("shop_address", v)}
                />
                <TextRow
                    id="fxc-invoice-details"
                    label={__("Extra details")}
                    help={__("A VAT or company number, a phone number or a website. One per line.")}
                    value={draft.shop_details}
                    onChange={(v) => set("shop_details", v)}
                />
                <TextRow
                    id="fxc-invoice-footer"
                    label={__("Footer")}
                    help={__("At the bottom of every page, for example payment terms or a thank-you note.")}
                    value={draft.footer}
                    onChange={(v) => set("footer", v)}
                />
                <SettingRow
                    icon={Ruler}
                    label={__("Paper size")}
                    htmlFor="fxc-invoice-paper"
                    control={
                        <Select
                            id="fxc-invoice-paper"
                            value={draft.paper}
                            onChange={(e) => set("paper", e.target.value as InvoiceSettings["paper"])}
                            options={[
                                { value: "", label: data.paper === "letter" ? __("Automatic (Letter)") : __("Automatic (A4)") },
                                { value: "a4", label: __("A4") },
                                { value: "letter", label: __("Letter") },
                            ]}
                        />
                    }
                />
                <SettingRow
                    icon={Barcode}
                    label={__("Show SKUs")}
                    control={<Switch aria-label={__("Show SKUs")} checked={draft.show_sku} onCheckedChange={(v) => set("show_sku", v)} />}
                />
            </Card>

            <SaveBar dirty={dirty} pending={save.isPending} onSave={submit} />
        </div>
    );
}

interface TextRowProps {
    id: string;
    label: string;
    help?: string;
    value: string;
    onChange: (value: string) => void;
}

function TextRow({ id, label, help, value, onChange }: TextRowProps) {
    return (
        <div className="fxc:space-y-1.5 fxc:border-b fxc:px-5 fxc:py-4">
            <label htmlFor={id} className="fxc:text-foreground fxc:block fxc:text-sm fxc:font-medium">
                {label}
            </label>
            <Textarea id={id} value={value} maxLength={2000} onChange={(e) => onChange(e.target.value)} />
            {help && <p className="fxc:text-muted-foreground fxc:text-xs">{help}</p>}
        </div>
    );
}
