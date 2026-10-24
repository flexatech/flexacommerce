import { useEffect, useMemo, useState } from "react";
import {
    ArrowLeftRight,
    Banknote,
    CircleDot,
    Clock,
    Hash,
    History,
    PauseCircle,
    ScrollText,
    Shield,
    ShoppingCart,
} from "lucide-react";

import { showToast } from "@/shared/components/showToast";
import { Button } from "@/shared/components/ui/button";
import { Card, CardDescription, CardHeader, CardTitle } from "@/shared/components/ui/card";
import { Input } from "@/shared/components/ui/input";
import { Select } from "@/shared/components/ui/select";
import { Switch } from "@/shared/components/ui/switch";
import { __ } from "@/shared/lib/i18n";
import { PageTitle } from "../../components/PageTitle";
import { SettingRow } from "../../components/SettingRow";
import { DataRemoval } from "./DataRemoval";
import { useSaveSettings, useSettings, type Settings } from "./useSettings";

type Draft = Omit<Settings, "remove_data_on_uninstall">;

function changedFields(draft: Draft, saved: Settings): Partial<Draft> {
    const out: Partial<Record<keyof Draft, unknown>> = {};
    for (const key of Object.keys(draft) as (keyof Draft)[]) {
        if (draft[key] !== saved[key]) {
            out[key] = draft[key];
        }
    }
    return out as Partial<Draft>;
}

function toInt(value: string): number {
    const n = Number.parseInt(value, 10);
    return Number.isFinite(n) ? n : 0;
}

export function SettingsPage() {
    const query = useSettings();
    const save = useSaveSettings();
    const [draft, setDraft] = useState<Draft | null>(null);

    const saved = query.data?.settings;
    useEffect(() => {
        if (saved) {
            const { remove_data_on_uninstall: _ignored, ...rest } = saved;
            setDraft(rest);
        }
    }, [saved]);

    const changes = useMemo(() => (draft && saved ? changedFields(draft, saved) : {}), [draft, saved]);
    const dirty = Object.keys(changes).length > 0;

    if (query.isPending || !draft || !saved) {
        return <p className="fxc:text-muted-foreground">{__("Loading settings…")}</p>;
    }
    if (query.isError) {
        return <p className="fxc:text-destructive">{query.error.message}</p>;
    }

    const set = <K extends keyof Draft>(key: K, value: Draft[K]) => setDraft({ ...draft, [key]: value });

    const submit = () =>
        save.mutate(changes, {
            onSuccess: () => showToast.success(__("Settings saved.")),
            onError: (error) => showToast.error(error.message),
        });

    const currencyOptions = query.data.currencies.map((code) => ({ value: code, label: code }));

    return (
        <div className="fxc:space-y-6">
            <PageTitle
                title={__("Settings")}
                description={__("Store-wide options. Changes apply to new carts and orders.")}
                actions={
                    <>
                        {dirty && <span className="fxc:text-muted-foreground fxc:text-xs">{__("Unsaved changes")}</span>}
                        <Button onClick={submit} disabled={!dirty || save.isPending}>
                            {save.isPending ? __("Saving…") : __("Save changes")}
                        </Button>
                    </>
                }
            />

            <Card>
                <CardHeader>
                    <CardTitle>{__("Currency")}</CardTitle>
                    <CardDescription>{__("Prices are stored in the currency's smallest unit.")}</CardDescription>
                </CardHeader>
                <SettingRow
                    icon={Banknote}
                    label={__("Store currency")}
                    htmlFor="fxc-currency"
                    control={
                        <Select
                            id="fxc-currency"
                            value={draft.currency}
                            onChange={(e) => set("currency", e.target.value)}
                            options={currencyOptions}
                        />
                    }
                />
                <SettingRow
                    icon={ArrowLeftRight}
                    label={__("Symbol position")}
                    htmlFor="fxc-currency-position"
                    control={
                        <Select
                            id="fxc-currency-position"
                            value={draft.currency_position}
                            onChange={(e) => set("currency_position", e.target.value as Draft["currency_position"])}
                            options={[
                                { value: "left", label: __("Left ($99)") },
                                { value: "right", label: __("Right (99$)") },
                                { value: "left_space", label: __("Left with space ($ 99)") },
                                { value: "right_space", label: __("Right with space (99 $)") },
                            ]}
                        />
                    }
                />
                <SettingRow
                    icon={CircleDot}
                    label={__("Thousand separator")}
                    htmlFor="fxc-thousand"
                    control={
                        <Input
                            id="fxc-thousand"
                            className="fxc:w-20"
                            maxLength={3}
                            value={draft.thousand_separator}
                            onChange={(e) => set("thousand_separator", e.target.value)}
                        />
                    }
                />
                <SettingRow
                    icon={CircleDot}
                    label={__("Decimal separator")}
                    htmlFor="fxc-decimal"
                    control={
                        <Input
                            id="fxc-decimal"
                            className="fxc:w-20"
                            maxLength={3}
                            value={draft.decimal_separator}
                            onChange={(e) => set("decimal_separator", e.target.value)}
                        />
                    }
                />
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>{__("Orders and checkout")}</CardTitle>
                </CardHeader>
                <SettingRow
                    icon={Hash}
                    label={__("Order number prefix")}
                    htmlFor="fxc-order-prefix"
                    description={__("Shown before every new order number, for example SHOP-1001.")}
                    control={
                        <Input
                            id="fxc-order-prefix"
                            className="fxc:w-32"
                            value={draft.order_number_prefix}
                            onChange={(e) => set("order_number_prefix", e.target.value)}
                        />
                    }
                />
                <SettingRow
                    icon={History}
                    label={__("Imported order prefix")}
                    htmlFor="fxc-migration-prefix"
                    description={__("Used for orders brought over from WooCommerce.")}
                    control={
                        <Input
                            id="fxc-migration-prefix"
                            className="fxc:w-32"
                            value={draft.migration_number_prefix}
                            onChange={(e) => set("migration_number_prefix", e.target.value)}
                        />
                    }
                />
                <SettingRow
                    icon={Clock}
                    label={__("Hold unpaid stock (minutes)")}
                    htmlFor="fxc-hold"
                    description={__("How long a pending order keeps its stock before it is released.")}
                    control={
                        <Input
                            id="fxc-hold"
                            type="number"
                            min={1}
                            max={10080}
                            className="fxc:w-24"
                            value={draft.hold_minutes}
                            onChange={(e) => set("hold_minutes", toInt(e.target.value))}
                        />
                    }
                />
                <SettingRow
                    icon={ShoppingCart}
                    label={__("Maximum quantity per line")}
                    htmlFor="fxc-max-qty"
                    control={
                        <Input
                            id="fxc-max-qty"
                            type="number"
                            min={1}
                            max={100000}
                            className="fxc:w-24"
                            value={draft.max_line_quantity}
                            onChange={(e) => set("max_line_quantity", toInt(e.target.value))}
                        />
                    }
                />
                <SettingRow
                    icon={Shield}
                    label={__("Store customer IP address")}
                    description={__("Kept with each order for fraud review. Turn off if your privacy policy does not allow it.")}
                    control={
                        <Switch
                            aria-label={__("Store customer IP address")}
                            checked={draft.store_customer_ip}
                            onCheckedChange={(v) => set("store_customer_ip", v)}
                        />
                    }
                />
                <SettingRow
                    icon={PauseCircle}
                    label={__("Pause checkout")}
                    description={__("Shoppers can browse and fill their cart but cannot place orders.")}
                    control={
                        <Switch
                            aria-label={__("Pause checkout")}
                            checked={draft.checkout_paused}
                            onCheckedChange={(v) => set("checkout_paused", v)}
                        />
                    }
                />
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>{__("Logging")}</CardTitle>
                </CardHeader>
                <SettingRow
                    icon={ScrollText}
                    label={__("Minimum level")}
                    htmlFor="fxc-log-level"
                    description={__("Entries below this level are not written.")}
                    control={
                        <Select
                            id="fxc-log-level"
                            value={draft.log_level}
                            onChange={(e) => set("log_level", e.target.value as Draft["log_level"])}
                            options={[
                                { value: "debug", label: __("Debug") },
                                { value: "info", label: __("Info") },
                                { value: "notice", label: __("Notice") },
                                { value: "warning", label: __("Warning") },
                                { value: "error", label: __("Error") },
                                { value: "critical", label: __("Critical") },
                            ]}
                        />
                    }
                />
                <SettingRow
                    icon={History}
                    label={__("Keep logs for (days)")}
                    htmlFor="fxc-log-days"
                    control={
                        <Input
                            id="fxc-log-days"
                            type="number"
                            min={1}
                            max={365}
                            className="fxc:w-24"
                            value={draft.log_retention_days}
                            onChange={(e) => set("log_retention_days", toInt(e.target.value))}
                        />
                    }
                />
            </Card>

            <DataRemoval enabled={saved.remove_data_on_uninstall} />
        </div>
    );
}
