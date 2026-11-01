import { useEffect, useMemo, useState } from "react";
import { Power, Send, UserRound } from "lucide-react";

import { showToast } from "@/shared/components/showToast";
import { Button } from "@/shared/components/ui/button";
import { Card, CardDescription, CardHeader, CardTitle } from "@/shared/components/ui/card";
import { Input } from "@/shared/components/ui/input";
import { Label } from "@/shared/components/ui/label";
import { Switch } from "@/shared/components/ui/switch";
import { Textarea } from "@/shared/components/ui/textarea";
import { __, sprintf } from "@/shared/lib/i18n";
import { SettingRow } from "../../components/SettingRow";
import { useSaveStockAlertSettings, useStockAlertSettings, useTestStockAlert, type StockAlertSettings } from "./useStockAlerts";

function diff(draft: StockAlertSettings, saved: StockAlertSettings): Partial<StockAlertSettings> {
    const out: Partial<StockAlertSettings> = {};
    for (const key of Object.keys(draft) as (keyof StockAlertSettings)[]) {
        if (draft[key] !== saved[key]) {
            Object.assign(out, { [key]: draft[key] });
        }
    }
    return out;
}

export function StockAlertSettingsPanel() {
    const query = useStockAlertSettings();
    const save = useSaveStockAlertSettings();
    const test = useTestStockAlert();
    const [draft, setDraft] = useState<StockAlertSettings | null>(null);
    const [to, setTo] = useState("");

    const data = query.data;
    useEffect(() => {
        if (data) {
            setDraft(data.settings);
            setTo((current) => current || data.email);
        }
    }, [data]);

    const changes = useMemo(() => (draft && data ? diff(draft, data.settings) : {}), [draft, data]);
    const dirty = Object.keys(changes).length > 0;

    if (query.isError) {
        return <p className="fxc:text-destructive">{query.error.message}</p>;
    }
    if (query.isPending || !draft || !data) {
        return <p className="fxc:text-muted-foreground">{__("Loading…")}</p>;
    }

    const set = <K extends keyof StockAlertSettings>(key: K, value: StockAlertSettings[K]) => setDraft({ ...draft, [key]: value });

    const submit = () =>
        save.mutate(changes, {
            onSuccess: () => showToast.success(__("Stock alert settings saved.")),
            onError: (error) => showToast.error(error.message),
        });

    const sendTest = () => {
        if (dirty) {
            showToast.error(__("Save your changes first, so the test shows them."));
            return;
        }
        test.mutate(
            { to },
            {
                onSuccess: (result) => showToast.success(sprintf(__("Test email sent to %s."), result.sent)),
                onError: (error) => showToast.error(error.message),
            },
        );
    };

    return (
        <div className="fxc:space-y-6">
            <div className="fxc:flex fxc:items-center fxc:justify-end fxc:gap-3">
                {dirty && <span className="fxc:text-muted-foreground fxc:text-xs">{__("Unsaved changes")}</span>}
                <Button onClick={submit} disabled={!dirty || save.isPending}>
                    {save.isPending ? __("Saving…") : __("Save changes")}
                </Button>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>{__("Back-in-stock emails")}</CardTitle>
                    <CardDescription>
                        {__("A product or variation that is out of stock shows a form for an email address. When stock comes back, each shopper who signed up gets one email with a link to the product.")}
                    </CardDescription>
                </CardHeader>
                <SettingRow
                    icon={Power}
                    label={__("Show the signup form")}
                    htmlFor="fxc-stock-alerts-enabled"
                    description={__("Turning this off hides the form and stops the emails. Signups already made stay in the list.")}
                    control={<Switch id="fxc-stock-alerts-enabled" checked={draft.enabled} onCheckedChange={(on) => set("enabled", on)} />}
                />
                <SettingRow
                    icon={UserRound}
                    label={__("Let guests sign up")}
                    htmlFor="fxc-stock-alerts-guests"
                    description={__("Guests type an email address. When this is off, guests see a link to log in, and signed-in shoppers sign up with one click.")}
                    control={<Switch id="fxc-stock-alerts-guests" checked={draft.guests} onCheckedChange={(on) => set("guests", on)} />}
                />
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>{__("Email")}</CardTitle>
                    <CardDescription>{__("The email shows the product image and name, a button to the product and a link to stop these emails.")}</CardDescription>
                </CardHeader>
                <div className="fxc:space-y-4 fxc:border-t fxc:px-5 fxc:py-4">
                    <div>
                        <Label htmlFor="fxc-stock-alerts-subject" className="fxc:mb-2 fxc:block">
                            {__("Subject")}
                        </Label>
                        <Input
                            id="fxc-stock-alerts-subject"
                            maxLength={200}
                            placeholder={data.defaults.subject}
                            value={draft.subject}
                            onChange={(e) => set("subject", e.target.value)}
                        />
                    </div>
                    <div>
                        <Label htmlFor="fxc-stock-alerts-heading" className="fxc:mb-2 fxc:block">
                            {__("Heading")}
                        </Label>
                        <Input
                            id="fxc-stock-alerts-heading"
                            maxLength={200}
                            placeholder={data.defaults.heading}
                            value={draft.heading}
                            onChange={(e) => set("heading", e.target.value)}
                        />
                    </div>
                    <div>
                        <Label htmlFor="fxc-stock-alerts-message" className="fxc:mb-2 fxc:block">
                            {__("Message")}
                        </Label>
                        <Textarea
                            id="fxc-stock-alerts-message"
                            rows={3}
                            maxLength={200}
                            placeholder={data.defaults.message}
                            value={draft.message}
                            onChange={(e) => set("message", e.target.value)}
                        />
                        <p className="fxc:text-muted-foreground fxc:mt-1 fxc:text-xs">
                            {__("Leave a text empty to send the one shown. {product} becomes the product name and {site_title} the site name.")}
                        </p>
                    </div>
                </div>
                <SettingRow
                    icon={Send}
                    label={__("Send a test to")}
                    htmlFor="fxc-stock-alerts-test"
                    description={__("The test shows the product most shoppers wait for, or your newest product.")}
                    control={
                        <div className="fxc:flex fxc:flex-wrap fxc:items-center fxc:justify-end fxc:gap-2">
                            <Input id="fxc-stock-alerts-test" type="email" className="fxc:w-64" value={to} onChange={(e) => setTo(e.target.value)} />
                            <Button variant="outline" size="sm" disabled={test.isPending} onClick={sendTest}>
                                {test.isPending ? __("Sending…") : __("Send a test")}
                            </Button>
                        </div>
                    }
                />
            </Card>
        </div>
    );
}
