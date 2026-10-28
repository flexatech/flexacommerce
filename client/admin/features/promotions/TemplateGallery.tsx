import { useMemo, useState } from "react";
import { Layers, Percent, Receipt, Sparkles, Ticket, Users } from "lucide-react";

import { Badge } from "@/shared/components/ui/badge";
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/shared/components/ui/dialog";
import { cn } from "@/shared/lib/cn";
import { __ } from "@/shared/lib/i18n";
import { GOALS, templates, type Template, type TemplateGoal } from "./templates";

function TemplateIcon({ template }: { template: Template }) {
    let Icon = Percent;
    if (template.kind === "coupon") {
        Icon = template.automatic ? Sparkles : Ticket;
    } else if (template.preset.values.type === "fee") {
        Icon = Receipt;
    } else if (template.preset.values.type !== "adjust") {
        Icon = Layers;
    } else if (template.preset.values.customers === "roles") {
        Icon = Users;
    }
    return (
        <span className="fxc:bg-muted fxc:text-muted-foreground fxc:flex fxc:size-9 fxc:shrink-0 fxc:items-center fxc:justify-center fxc:rounded-md">
            <Icon aria-hidden className="fxc:size-4" />
        </span>
    );
}

const kindLabel = (template: Template): string =>
    template.kind === "rule" ? (template.preset.values.type === "fee" ? __("Checkout fee") : __("Pricing rule")) : template.automatic ? __("Automatic discount") : __("Coupon code");

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onPick: (template: Template) => void;
}

/** Ready-made promotions by goal (ADR-047): one opens its editor filled in, and nothing is saved until the merchant saves. */
export function TemplateGallery({ open, onOpenChange, onPick }: Props) {
    const [goal, setGoal] = useState<TemplateGoal | "">("");
    const all = useMemo(() => (open ? templates() : []), [open]);
    const groups = GOALS.filter((g) => goal === "" || g.goal === goal);

    const chip = (value: TemplateGoal | "", label: string) => (
        <button
            key={value}
            type="button"
            aria-pressed={goal === value}
            className={cn(
                "fxc:rounded-full fxc:border fxc:px-3 fxc:py-1 fxc:text-sm fxc:outline-none fxc:focus-visible:ring-2 fxc:focus-visible:ring-primary-accent",
                goal === value ? "fxc:border-foreground fxc:bg-foreground fxc:text-background" : "fxc:hover:bg-muted",
            )}
            onClick={() => setGoal(value)}
        >
            {label}
        </button>
    );

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="fxc:max-h-[90vh] fxc:max-w-3xl fxc:overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>{__("Start from a template")}</DialogTitle>
                    <DialogDescription>
                        {__("Pick what you want to do. The editor opens with the values filled in; change anything, pick the products or customers it is for, then save. Nothing is created until you save.")}
                    </DialogDescription>
                </DialogHeader>

                <div className="fxc:flex fxc:flex-wrap fxc:gap-2" role="group" aria-label={__("Goal")}>
                    {chip("", __("All"))}
                    {GOALS.map((g) => chip(g.goal, g.label))}
                </div>

                <div className="fxc:space-y-6">
                    {groups.map((g) => (
                        <section key={g.goal} aria-labelledby={`fxc-goal-${g.goal}`}>
                            <h3 id={`fxc-goal-${g.goal}`} className="fxc:mb-2 fxc:text-sm fxc:font-semibold">
                                {g.label}
                            </h3>
                            <ul className="fxc:grid fxc:gap-2 fxc:sm:grid-cols-2">
                                {all
                                    .filter((t) => t.goal === g.goal)
                                    .map((t) => (
                                        <li key={t.key}>
                                            <button
                                                type="button"
                                                className="fxc:hover:bg-muted fxc:flex fxc:h-full fxc:w-full fxc:items-start fxc:gap-3 fxc:rounded-md fxc:border fxc:px-3 fxc:py-2 fxc:text-start fxc:text-sm fxc:outline-none fxc:focus-visible:ring-2 fxc:focus-visible:ring-primary-accent"
                                                onClick={() => onPick(t)}
                                            >
                                                <TemplateIcon template={t} />
                                                <span className="fxc:min-w-0 fxc:flex-1">
                                                    <span className="fxc:flex fxc:flex-wrap fxc:items-center fxc:gap-2">
                                                        <span className="fxc:font-medium">{t.name}</span>
                                                        <Badge variant="muted">{kindLabel(t)}</Badge>
                                                    </span>
                                                    <span className="fxc:text-muted-foreground fxc:mt-0.5 fxc:block fxc:text-xs">{t.description}</span>
                                                </span>
                                            </button>
                                        </li>
                                    ))}
                            </ul>
                        </section>
                    ))}
                </div>
            </DialogContent>
        </Dialog>
    );
}
