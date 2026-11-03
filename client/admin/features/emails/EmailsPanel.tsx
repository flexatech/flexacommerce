import { useEffect, useState } from "react";
import { ChevronDown, Mail, Palette } from "lucide-react";

import { showToast } from "@/shared/components/showToast";
import { Badge } from "@/shared/components/ui/badge";
import { Button } from "@/shared/components/ui/button";
import { Card } from "@/shared/components/ui/card";
import { Input } from "@/shared/components/ui/input";
import { Switch } from "@/shared/components/ui/switch";
import { getPluginGlobal } from "@/shared/lib/api";
import { cn } from "@/shared/lib/cn";
import { __, sprintf } from "@/shared/lib/i18n";
import { useEmails, useSaveEmail, type EmailValues, type OrderEmail } from "./useEmails";

const KEYS = ["enabled", "recipients", "subject", "heading", "days"] as const;

function pick(email: OrderEmail): EmailValues {
    return { enabled: email.enabled, recipients: email.recipients, subject: email.subject, heading: email.heading, days: email.days };
}

export function EmailsPanel() {
    const emails = useEmails();
    const [open, setOpen] = useState<string>("");

    if (emails.isPending) {
        return <p className="fxc:text-muted-foreground">{__("Loading emails…")}</p>;
    }
    if (emails.isError) {
        return <p className="fxc:text-destructive">{emails.error.message}</p>;
    }

    const { emails: list, admin_email, placeholders } = emails.data;

    return (
        <div className="fxc:space-y-3">
            <div className="fxc:flex fxc:flex-wrap fxc:items-start fxc:justify-between fxc:gap-3">
                <p className="fxc:text-muted-foreground fxc:min-w-0 fxc:flex-1 fxc:text-sm">
                    {sprintf(
                        __("Emails sent when an order changes. Leave a subject or heading empty to use the default. You can use %s."),
                        placeholders.join(", "),
                    )}
                </p>
                {list.length > 0 && (
                    <Button variant="outline" size="sm" asChild>
                        <a href={`${getPluginGlobal().adminUrl}?page=flexacommerce-email-design&email=${encodeURIComponent(list[0].id)}`} className="fxc:no-underline">
                            <Palette aria-hidden />
                            {__("Open the email designer")}
                        </a>
                    </Button>
                )}
            </div>
            {list.map((email) => (
                <EmailCard
                    key={email.id}
                    email={email}
                    adminEmail={admin_email}
                    open={open === email.id}
                    onToggle={() => setOpen(open === email.id ? "" : email.id)}
                />
            ))}
        </div>
    );
}

interface CardProps {
    email: OrderEmail;
    adminEmail: string;
    open: boolean;
    onToggle: () => void;
}

function EmailCard({ email, adminEmail, open, onToggle }: CardProps) {
    const save = useSaveEmail();
    const [draft, setDraft] = useState<EmailValues>(() => pick(email));

    useEffect(() => setDraft(pick(email)), [email]);

    const changes: Partial<EmailValues> = {};
    for (const key of KEYS) {
        if (draft[key] !== email[key]) {
            Object.assign(changes, { [key]: draft[key] });
        }
    }
    const dirty = Object.keys(changes).length > 0;
    const set = <K extends keyof EmailValues>(key: K, value: EmailValues[K]) => setDraft({ ...draft, [key]: value });

    const submit = () =>
        save.mutate(
            { id: email.id, changes },
            {
                onSuccess: () => showToast.success(__("Email settings saved.")),
                onError: (e) => showToast.error(e.message),
            },
        );

    const field = (key: string) => `fxc-email-${email.id}-${key}`;

    return (
        <Card>
            <button
                type="button"
                aria-expanded={open}
                onClick={onToggle}
                className="fxc:hover:bg-muted/50 fxc:flex fxc:w-full fxc:cursor-pointer fxc:items-center fxc:gap-3 fxc:bg-transparent fxc:px-5 fxc:py-4 fxc:text-left fxc:outline-none"
            >
                <div className="fxc:bg-muted fxc:text-primary fxc:flex fxc:h-9 fxc:w-9 fxc:shrink-0 fxc:items-center fxc:justify-center fxc:rounded-lg">
                    <Mail aria-hidden className="fxc:size-4" />
                </div>
                <div className="fxc:min-w-0 fxc:flex-1">
                    <div className="fxc:text-foreground fxc:text-sm fxc:font-semibold">{email.title}</div>
                    <div className="fxc:text-muted-foreground fxc:text-xs">
                        {email.to_customer ? __("To the customer") : email.recipients !== "" ? email.recipients : adminEmail}
                    </div>
                </div>
                {email.custom && <Badge variant="brand">{__("Custom design")}</Badge>}
                {email.manual ? (
                    <Badge variant="muted">{__("Sent by hand")}</Badge>
                ) : (
                    <Badge variant={email.enabled ? "success" : "muted"}>{email.enabled ? __("Enabled") : __("Off")}</Badge>
                )}
                <ChevronDown aria-hidden className={cn("fxc:text-muted-foreground fxc:size-4 fxc:transition-transform", open && "fxc:rotate-180")} />
            </button>
            {open && (
                <div className="fxc:space-y-4 fxc:border-t fxc:px-5 fxc:py-4">
                    {email.manual ? (
                        <p className="fxc:text-muted-foreground fxc:text-sm">{__("Sent from an order's screen with “Send order details”.")}</p>
                    ) : (
                        <div className="fxc:flex fxc:items-start fxc:justify-between fxc:gap-4">
                            <label htmlFor={field("enabled")} className="fxc:text-foreground fxc:block fxc:text-sm fxc:font-medium">
                                {__("Send this email")}
                            </label>
                            <Switch id={field("enabled")} checked={draft.enabled} onCheckedChange={(v) => set("enabled", v)} />
                        </div>
                    )}
                    {draft.days !== null && (
                        <div className="fxc:space-y-1.5">
                            <label htmlFor={field("days")} className="fxc:text-foreground fxc:block fxc:text-sm fxc:font-medium">
                                {__("Days before the renewal")}
                            </label>
                            <Input
                                id={field("days")}
                                type="number"
                                min={1}
                                max={60}
                                className="fxc:w-28"
                                value={String(draft.days)}
                                onChange={(e) => set("days", Math.max(1, Math.min(60, Number(e.target.value) || 1)))}
                            />
                            <p className="fxc:text-muted-foreground fxc:text-xs">
                                {__("Plans whose billing period is this many days or shorter get no reminder.")}
                            </p>
                        </div>
                    )}
                    {!email.to_customer && (
                        <div className="fxc:space-y-1.5">
                            <label htmlFor={field("recipients")} className="fxc:text-foreground fxc:block fxc:text-sm fxc:font-medium">
                                {__("Recipients")}
                            </label>
                            <Input
                                id={field("recipients")}
                                value={draft.recipients}
                                placeholder={adminEmail}
                                onChange={(e) => set("recipients", e.target.value)}
                            />
                            <p className="fxc:text-muted-foreground fxc:text-xs">
                                {__("Separate addresses with commas. Empty means the site admin email.")}
                            </p>
                        </div>
                    )}
                    <div className="fxc:space-y-1.5">
                        <label htmlFor={field("subject")} className="fxc:text-foreground fxc:block fxc:text-sm fxc:font-medium">
                            {__("Subject")}
                        </label>
                        <Input
                            id={field("subject")}
                            value={draft.subject}
                            maxLength={255}
                            placeholder={__("Default subject")}
                            onChange={(e) => set("subject", e.target.value)}
                        />
                    </div>
                    <div className="fxc:space-y-1.5">
                        <label htmlFor={field("heading")} className="fxc:text-foreground fxc:block fxc:text-sm fxc:font-medium">
                            {__("Heading")}
                        </label>
                        <Input
                            id={field("heading")}
                            value={draft.heading}
                            maxLength={255}
                            placeholder={__("Default heading")}
                            onChange={(e) => set("heading", e.target.value)}
                        />
                    </div>
                    <div className="fxc:bg-muted/50 fxc:flex fxc:flex-wrap fxc:items-center fxc:justify-between fxc:gap-3 fxc:rounded-md fxc:px-3 fxc:py-2.5">
                        <p className="fxc:text-muted-foreground fxc:text-sm">
                            {email.custom ? __("This email has its own design.") : __("Uses the default design. Add your logo, colors, text and images.")}
                        </p>
                        <Button variant="outline" size="sm" asChild>
                            <a href={`${getPluginGlobal().adminUrl}?page=flexacommerce-email-design&email=${encodeURIComponent(email.id)}`} className="fxc:no-underline">
                                <Palette aria-hidden />
                                {email.custom ? __("Edit design") : __("Customize design")}
                            </a>
                        </Button>
                    </div>
                    <div className="fxc:flex fxc:justify-end fxc:gap-2 fxc:pt-2">
                        {dirty && (
                            <Button variant="ghost" onClick={() => setDraft(pick(email))} disabled={save.isPending}>
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
