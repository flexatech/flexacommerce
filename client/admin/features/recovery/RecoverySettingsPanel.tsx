import { useEffect, useMemo, useState } from "react";
import { Clock, Mail, Power, Send, Timer, UserRound } from "lucide-react";

import { showToast } from "@/shared/components/showToast";
import { Button } from "@/shared/components/ui/button";
import { Card, CardDescription, CardHeader, CardTitle } from "@/shared/components/ui/card";
import { Input } from "@/shared/components/ui/input";
import { Label } from "@/shared/components/ui/label";
import { Select } from "@/shared/components/ui/select";
import { Switch } from "@/shared/components/ui/switch";
import { Textarea } from "@/shared/components/ui/textarea";
import { getPluginGlobal } from "@/shared/lib/api";
import { __, sprintf } from "@/shared/lib/i18n";
import { MoneyInput } from "../../components/MoneyInput";
import { SaveBar } from "../../components/SaveBar";
import { SettingRow } from "../../components/SettingRow";
import {
    useRecoverySettings,
    useSaveRecoverySettings,
    useTestRecovery,
    type CouponType,
    type Reminder,
    type RecoverySettings,
    type RecoverySettingsResponse,
} from "./useRecovery";

type Changes = Partial<Omit<RecoverySettings, "reminders">> & { reminders?: Partial<Reminder>[] };

const ORDINAL = [__("First reminder"), __("Second reminder"), __("Third reminder")];

function toInt(value: string): number {
    const n = Number.parseInt(value, 10);
    return Number.isFinite(n) ? n : 0;
}

/** Changed values only; a reminder list keeps positions with empty objects. */
function diff(draft: RecoverySettings, saved: RecoverySettings): Changes {
    const out: Changes = {};
    for (const key of ["enabled", "abandon_minutes", "capture_guests"] as const) {
        if (draft[key] !== saved[key]) {
            Object.assign(out, { [key]: draft[key] });
        }
    }
    const reminders = draft.reminders.map((reminder, i) => {
        const changed: Partial<Reminder> = {};
        for (const key of Object.keys(reminder) as (keyof Reminder)[]) {
            if (reminder[key] !== saved.reminders[i]?.[key]) {
                Object.assign(changed, { [key]: reminder[key] });
            }
        }
        return changed;
    });
    if (reminders.some((r) => Object.keys(r).length > 0)) {
        out.reminders = reminders;
    }
    return out;
}

function ReminderCard({
    index,
    reminder,
    defaults,
    onChange,
    onTest,
    testing,
}: {
    index: number;
    reminder: Reminder;
    defaults: RecoverySettingsResponse["defaults"][number];
    onChange: (next: Reminder) => void;
    onTest: () => void;
    testing: boolean;
}) {
    const { currency } = getPluginGlobal();
    const id = `fxc-reminder-${index}`;
    const set = <K extends keyof Reminder>(key: K, value: Reminder[K]) => onChange({ ...reminder, [key]: value });

    return (
        <Card>
            <CardHeader className="fxc:flex fxc:flex-row fxc:items-start fxc:justify-between fxc:gap-3">
                <div>
                    <CardTitle>{ORDINAL[index]}</CardTitle>
                    <CardDescription>
                        {sprintf(__("Sent %d hours after the shopper last changed the cart."), reminder.delay_hours)}
                    </CardDescription>
                </div>
                <Switch aria-label={sprintf(__("Send the %s"), ORDINAL[index].toLowerCase())} checked={reminder.enabled} onCheckedChange={(on) => set("enabled", on)} />
            </CardHeader>
            {reminder.enabled && (
                <>
                    <SettingRow
                        icon={Timer}
                        label={__("Send after")}
                        htmlFor={`${id}-delay`}
                        description={__("Hours since the last change to the cart. A shopper who comes back starts the count again.")}
                        control={
                            <div className="fxc:flex fxc:items-center fxc:gap-2">
                                <Input
                                    id={`${id}-delay`}
                                    type="number"
                                    min={1}
                                    max={720}
                                    className="fxc:w-24"
                                    value={reminder.delay_hours}
                                    onChange={(e) => set("delay_hours", Math.max(1, Math.min(720, toInt(e.target.value))))}
                                />
                                <span className="fxc:text-muted-foreground fxc:text-sm">{__("hours")}</span>
                            </div>
                        }
                    />
                    <div className="fxc:space-y-4 fxc:border-t fxc:px-5 fxc:py-4">
                        <div>
                            <Label htmlFor={`${id}-subject`} className="fxc:mb-2 fxc:block">
                                {__("Subject")}
                            </Label>
                            <Input id={`${id}-subject`} maxLength={200} placeholder={defaults.subject} value={reminder.subject} onChange={(e) => set("subject", e.target.value)} />
                        </div>
                        <div>
                            <Label htmlFor={`${id}-heading`} className="fxc:mb-2 fxc:block">
                                {__("Heading")}
                            </Label>
                            <Input id={`${id}-heading`} maxLength={200} placeholder={defaults.heading} value={reminder.heading} onChange={(e) => set("heading", e.target.value)} />
                        </div>
                        <div>
                            <Label htmlFor={`${id}-message`} className="fxc:mb-2 fxc:block">
                                {__("Message")}
                            </Label>
                            <Textarea
                                id={`${id}-message`}
                                rows={3}
                                maxLength={2000}
                                placeholder={defaults.message}
                                value={reminder.message}
                                onChange={(e) => set("message", e.target.value)}
                            />
                            <p className="fxc:text-muted-foreground fxc:mt-1 fxc:text-xs">
                                {__("Leave a text empty to send the one shown. {site_title} becomes the site name. The cart items and a button back to the cart follow the message.")}
                            </p>
                        </div>
                    </div>
                    <SettingRow
                        icon={Mail}
                        label={__("Discount code")}
                        htmlFor={`${id}-coupon`}
                        description={__("Each shopper gets their own code, valid once and for their email only. The link in the email adds it to the cart.")}
                        control={
                            <div className="fxc:flex fxc:flex-wrap fxc:items-center fxc:justify-end fxc:gap-2">
                                <Select
                                    id={`${id}-coupon`}
                                    value={reminder.coupon_type}
                                    onChange={(e) => {
                                        const type = e.target.value as CouponType;
                                        onChange({ ...reminder, coupon_type: type, coupon_amount: type === "" ? 0 : reminder.coupon_amount || (type === "percent" ? 10 : 0) });
                                    }}
                                    options={[
                                        { value: "", label: __("No code") },
                                        { value: "percent", label: __("Percent off") },
                                        { value: "fixed", label: __("Amount off") },
                                    ]}
                                />
                                {reminder.coupon_type === "percent" && (
                                    <div className="fxc:flex fxc:items-center fxc:gap-1">
                                        <Input
                                            aria-label={__("Percent off")}
                                            type="number"
                                            min={1}
                                            max={100}
                                            className="fxc:w-20"
                                            value={reminder.coupon_amount}
                                            onChange={(e) => set("coupon_amount", Math.max(0, Math.min(100, toInt(e.target.value))))}
                                        />
                                        <span className="fxc:text-muted-foreground fxc:text-sm">%</span>
                                    </div>
                                )}
                                {reminder.coupon_type === "fixed" && (
                                    <MoneyInput
                                        aria-label={__("Amount off")}
                                        className="fxc:w-28"
                                        exponent={currency.exponent}
                                        value={reminder.coupon_amount}
                                        onChange={(minor) => set("coupon_amount", Number.isFinite(minor) ? Math.max(0, minor) : 0)}
                                    />
                                )}
                            </div>
                        }
                    />
                    {reminder.coupon_type !== "" && (
                        <SettingRow
                            icon={Clock}
                            label={__("Code valid for")}
                            htmlFor={`${id}-days`}
                            control={
                                <div className="fxc:flex fxc:items-center fxc:gap-2">
                                    <Input
                                        id={`${id}-days`}
                                        type="number"
                                        min={1}
                                        max={90}
                                        className="fxc:w-24"
                                        value={reminder.coupon_days}
                                        onChange={(e) => set("coupon_days", Math.max(1, Math.min(90, toInt(e.target.value))))}
                                    />
                                    <span className="fxc:text-muted-foreground fxc:text-sm">{__("days")}</span>
                                </div>
                            }
                        />
                    )}
                    <div className="fxc:flex fxc:justify-end fxc:border-t fxc:px-5 fxc:py-3">
                        <Button variant="outline" size="sm" disabled={testing} onClick={onTest}>
                            <Send aria-hidden className="fxc:size-4" />
                            {testing ? __("Sending…") : __("Send a test")}
                        </Button>
                    </div>
                </>
            )}
        </Card>
    );
}

export function RecoverySettingsPanel() {
    const query = useRecoverySettings();
    const save = useSaveRecoverySettings();
    const test = useTestRecovery();
    const [draft, setDraft] = useState<RecoverySettings | null>(null);
    const [to, setTo] = useState("");
    const [testing, setTesting] = useState<number | null>(null);

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

    const set = <K extends keyof RecoverySettings>(key: K, value: RecoverySettings[K]) => setDraft({ ...draft, [key]: value });

    const submit = () =>
        save.mutate(changes, {
            onSuccess: () => showToast.success(__("Cart reminder settings saved.")),
            onError: (error) => showToast.error(error.message),
        });

    const sendTest = (index: number) => {
        if (dirty) {
            showToast.error(__("Save your changes first, so the test shows them."));
            return;
        }
        setTesting(index);
        test.mutate(
            { reminder: index, to },
            {
                onSuccess: (result) => showToast.success(sprintf(__("Test email sent to %s."), result.sent)),
                onError: (error) => showToast.error(error.message),
                onSettled: () => setTesting(null),
            },
        );
    };

    return (
        <div className="fxc:space-y-6">

            <Card>
                <CardHeader>
                    <CardTitle>{__("Cart reminders")}</CardTitle>
                    <CardDescription>
                        {__("A cart left without an order gets up to three reminder emails. They stop as soon as the shopper orders or empties the cart, and every email has a link to stop them.")}
                    </CardDescription>
                </CardHeader>
                <SettingRow
                    icon={Power}
                    label={__("Send cart reminders")}
                    htmlFor="fxc-recovery-enabled"
                    control={<Switch id="fxc-recovery-enabled" checked={draft.enabled} onCheckedChange={(on) => set("enabled", on)} />}
                />
                <SettingRow
                    icon={Timer}
                    label={__("Cart counts as left after")}
                    htmlFor="fxc-recovery-minutes"
                    description={__("Minutes without a change to the cart, from 15 to 1440.")}
                    control={
                        <div className="fxc:flex fxc:items-center fxc:gap-2">
                            <Input
                                id="fxc-recovery-minutes"
                                type="number"
                                min={15}
                                max={1440}
                                className="fxc:w-24"
                                value={draft.abandon_minutes}
                                onChange={(e) => set("abandon_minutes", toInt(e.target.value))}
                                onBlur={() => set("abandon_minutes", Math.max(15, Math.min(1440, draft.abandon_minutes)))}
                            />
                            <span className="fxc:text-muted-foreground fxc:text-sm">{__("minutes")}</span>
                        </div>
                    }
                />
                <SettingRow
                    icon={UserRound}
                    label={__("Follow guests")}
                    htmlFor="fxc-recovery-guests"
                    description={__("Keep the email a guest types at checkout, before they order. A note under the field tells them. Signed-in shoppers are always followed.")}
                    control={<Switch id="fxc-recovery-guests" checked={draft.capture_guests} onCheckedChange={(on) => set("capture_guests", on)} />}
                />
                <SettingRow
                    icon={Send}
                    label={__("Send tests to")}
                    htmlFor="fxc-recovery-test"
                    description={__("Test emails show a sample cart. A test with a discount shows a sample code that does not work.")}
                    control={<Input id="fxc-recovery-test" type="email" className="fxc:w-64" value={to} onChange={(e) => setTo(e.target.value)} />}
                />
            </Card>

            {draft.reminders.map((reminder, index) => (
                <ReminderCard
                    key={index}
                    index={index}
                    reminder={reminder}
                    defaults={data.defaults[index]}
                    testing={testing === index}
                    onTest={() => sendTest(index)}
                    onChange={(next) => set("reminders", draft.reminders.map((r, i) => (i === index ? next : r)))}
                />
            ))}

            <SaveBar dirty={dirty} pending={save.isPending} onSave={submit} />
        </div>
    );
}
