import { useEffect, useState } from "react";
import { Layers, LayoutTemplate, Pencil, Percent, Plus, Receipt, Trash2, Users } from "lucide-react";

import { showToast } from "@/shared/components/showToast";
import { Badge } from "@/shared/components/ui/badge";
import { Button } from "@/shared/components/ui/button";
import { Card } from "@/shared/components/ui/card";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/shared/components/ui/dialog";
import { Switch } from "@/shared/components/ui/switch";
import { __, sprintf } from "@/shared/lib/i18n";
import type { TemplateProps } from "../promotions/templates";
import { PriceRuleDialog, RULE_PRESETS, type RulePreset } from "./PriceRuleDialog";
import { summary } from "./ruleText";
import { useDeletePriceRule, usePriceRules, useSavePriceRule, type PriceRule } from "./usePriceRules";

/** Off, scheduled or ended; null when it applies now. */
function stateOf(rule: PriceRule): { label: string; tone: "muted" | "destructive" } | null {
    const now = Date.now();
    if (!rule.enabled) {
        return { label: __("Off"), tone: "muted" };
    }
    if (rule.ends_at !== null && Date.parse(rule.ends_at) <= now) {
        return { label: __("Ended"), tone: "destructive" };
    }
    if (rule.starts_at !== null && Date.parse(rule.starts_at) > now) {
        return { label: __("Scheduled"), tone: "muted" };
    }
    return null;
}

function RuleIcon({ rule }: { rule: Pick<PriceRule, "type" | "customers"> }) {
    const Icon = rule.type === "fee" ? Receipt : rule.type !== "adjust" ? Layers : rule.customers === "roles" ? Users : Percent;
    return (
        <span className="fxc:bg-muted fxc:text-muted-foreground fxc:flex fxc:size-9 fxc:shrink-0 fxc:items-center fxc:justify-center fxc:rounded-md">
            <Icon aria-hidden className="fxc:size-4" />
        </span>
    );
}

/** The Pricing rules tab of Promotions: prices that change without a code. */
export function PriceRulesPanel({ template, onTemplates, onTemplateUsed }: TemplateProps) {
    const list = usePriceRules();
    const save = useSavePriceRule();
    const remove = useDeletePriceRule();
    const [editing, setEditing] = useState<{ rule: PriceRule | null; preset?: RulePreset } | null>(null);
    const [deleting, setDeleting] = useState<PriceRule | null>(null);

    useEffect(() => {
        if (template?.kind === "rule") {
            setEditing({ rule: null, preset: template.preset });
            onTemplateUsed?.();
        }
    }, [template, onTemplateUsed]);

    const items = list.data?.items ?? [];
    const roles = list.data?.roles ?? [];

    const toggle = (rule: PriceRule, enabled: boolean) =>
        save.mutate(
            { id: rule.id, input: { enabled } },
            {
                onSuccess: () => showToast.success(enabled ? __("Rule turned on.") : __("Rule turned off.")),
                onError: (error) => showToast.error(error.message),
            },
        );

    return (
        <div className="fxc:space-y-4">
            <div className="fxc:flex fxc:flex-wrap fxc:items-center fxc:gap-3">
                <p className="fxc:text-muted-foreground fxc:max-w-2xl fxc:text-sm">
                    {__("Prices that change on their own, with no code to type: a sale on a category, a lower price from a quantity up, or a price for a customer role. When several rules fit, the customer gets the lowest price; rules do not add up. A checkout fee adds its own line to the total instead.")}
                </p>
                {onTemplates && (
                    <Button variant="ghost" className="fxc:ms-auto" onClick={onTemplates}>
                        <LayoutTemplate aria-hidden className="fxc:size-4" />
                        {__("Templates")}
                    </Button>
                )}
                <Button className={onTemplates ? undefined : "fxc:ms-auto"} onClick={() => setEditing({ rule: null })}>
                    <Plus aria-hidden className="fxc:size-4" />
                    {__("Add rule")}
                </Button>
            </div>

            <Card>
                {list.isPending ? (
                    <p className="fxc:text-muted-foreground fxc:px-5 fxc:py-4">{__("Loading…")}</p>
                ) : list.isError ? (
                    <p className="fxc:text-destructive fxc:px-5 fxc:py-4">{list.error.message}</p>
                ) : items.length === 0 ? (
                    <div className="fxc:space-y-4 fxc:px-5 fxc:py-8 fxc:text-center">
                        <p className="fxc:text-muted-foreground">{__("No pricing rules yet. Start from one of these:")}</p>
                        <div className="fxc:flex fxc:flex-wrap fxc:justify-center fxc:gap-2">
                            {RULE_PRESETS.filter((p) => p.key !== "custom").map((preset) => (
                                <button
                                    key={preset.key}
                                    type="button"
                                    className="fxc:hover:bg-muted fxc:flex fxc:max-w-60 fxc:items-start fxc:gap-3 fxc:rounded-md fxc:border fxc:px-3 fxc:py-2 fxc:text-start fxc:text-sm fxc:outline-none fxc:focus-visible:ring-2 fxc:focus-visible:ring-primary-accent"
                                    onClick={() => setEditing({ rule: null, preset })}
                                >
                                    <RuleIcon rule={preset.values} />
                                    <span>
                                        <span className="fxc:block fxc:font-medium">{preset.name}</span>
                                        <span className="fxc:text-muted-foreground fxc:block fxc:text-xs">{preset.description}</span>
                                    </span>
                                </button>
                            ))}
                        </div>
                        {onTemplates && (
                            <Button variant="link" onClick={onTemplates}>
                                {__("See all templates, coupons included")}
                            </Button>
                        )}
                    </div>
                ) : (
                    <ul>
                        {items.map((rule) => {
                            const state = stateOf(rule);
                            return (
                                <li key={rule.id} className="fxc:flex fxc:flex-wrap fxc:items-center fxc:gap-4 fxc:border-b fxc:px-5 fxc:py-3 fxc:last:border-b-0">
                                    <RuleIcon rule={rule} />
                                    <div className="fxc:min-w-0 fxc:flex-1">
                                        <div className="fxc:flex fxc:items-center fxc:gap-2 fxc:text-sm fxc:font-medium">
                                            <span className="fxc:truncate">{rule.name}</span>
                                            {state && <Badge variant={state.tone}>{state.label}</Badge>}
                                        </div>
                                        <div className="fxc:text-muted-foreground fxc:text-xs">{summary(rule, roles)}</div>
                                    </div>
                                    <div className="fxc:flex fxc:items-center fxc:gap-1">
                                        <Switch
                                            aria-label={sprintf(__("Apply %s"), rule.name)}
                                            checked={rule.enabled}
                                            disabled={save.isPending}
                                            onCheckedChange={(v) => toggle(rule, v)}
                                        />
                                        <Button variant="ghost" size="icon" className="fxc:ms-2" aria-label={sprintf(__("Edit %s"), rule.name)} onClick={() => setEditing({ rule })}>
                                            <Pencil aria-hidden className="fxc:size-4" />
                                        </Button>
                                        <Button variant="ghost" size="icon" aria-label={sprintf(__("Delete %s"), rule.name)} onClick={() => setDeleting(rule)}>
                                            <Trash2 aria-hidden className="fxc:size-4" />
                                        </Button>
                                    </div>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </Card>

            <PriceRuleDialog
                open={editing !== null}
                rule={editing?.rule ?? null}
                preset={editing?.preset}
                roles={roles}
                onOpenChange={(open) => !open && setEditing(null)}
            />

            <Dialog open={deleting !== null} onOpenChange={(open) => !open && !remove.isPending && setDeleting(null)}>
                <DialogContent className="fxc:max-w-md">
                    <DialogHeader>
                        <DialogTitle>{sprintf(__("Delete %s?"), deleting?.name ?? "")}</DialogTitle>
                        <DialogDescription>{__("Prices go back to normal at once, carts included. To pause the rule, turn it off instead.")}</DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" disabled={remove.isPending} onClick={() => setDeleting(null)}>
                            {__("Cancel")}
                        </Button>
                        <Button
                            variant="destructive"
                            disabled={remove.isPending}
                            onClick={() =>
                                deleting !== null &&
                                remove.mutate(deleting.id, {
                                    onSuccess: () => {
                                        showToast.success(__("Rule deleted."));
                                        setDeleting(null);
                                    },
                                    onError: (error) => showToast.error(error.message),
                                })
                            }
                        >
                            {remove.isPending ? __("Deleting…") : __("Delete rule")}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
