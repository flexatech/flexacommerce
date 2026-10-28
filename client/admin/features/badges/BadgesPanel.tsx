import { useState } from "react";
import { ArrowDown, ArrowUp, Pencil, Plus, Trash2 } from "lucide-react";

import { showToast } from "@/shared/components/showToast";
import { Badge } from "@/shared/components/ui/badge";
import { Button } from "@/shared/components/ui/button";
import { Card } from "@/shared/components/ui/card";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/shared/components/ui/dialog";
import { Switch } from "@/shared/components/ui/switch";
import { __, sprintf } from "@/shared/lib/i18n";
import { BadgeChip, sampleLabel } from "./BadgeChip";
import { BadgeDialog, PRESETS, type Preset } from "./BadgeDialog";
import { useBadgeRoles, useBadges, useDeleteBadge, useReorderBadges, useSaveBadge, type BadgeCondition, type ProductBadge, type Role } from "./useBadges";

export const CONDITION_LABELS: Record<BadgeCondition, string> = {
    always: __("Always"),
    on_sale: __("On sale"),
    new: __("New product"),
    featured: __("Featured"),
    low_stock: __("Low stock"),
    out_of_stock: __("Out of stock"),
};

/** One line on who gets the badge and where. */
function summary(badge: ProductBadge, roles: Role[]): string {
    const when = badge.condition === "new" ? sprintf(__("Added in the last %d days"), badge.new_days) : CONDITION_LABELS[badge.condition];
    const who =
        badge.applies_to === "products"
            ? sprintf(__("%d products"), badge.product_ids.length)
            : badge.applies_to === "categories"
              ? sprintf(__("%d categories"), badge.category_ids.length)
              : __("All products");
    const where = badge.places === "single" ? __("Product page") : badge.places === "loop" ? __("Product lists") : __("Everywhere");
    const names = badge.roles.map((r) => roles.find((role) => role.value === r)?.label ?? r);
    const shoppers =
        badge.customers === "logged_in"
            ? __("Logged-in customers")
            : badge.customers === "guests"
              ? __("Guests only")
              : badge.customers === "roles"
                ? names.join(", ")
                : null;
    return [when, who, where, shoppers].filter((part) => part !== null).join(" · ");
}

/** Off, scheduled or ended; null when it shows now. */
function stateOf(badge: ProductBadge): { label: string; tone: "muted" | "destructive" } | null {
    const now = Date.now();
    if (!badge.enabled) {
        return { label: __("Off"), tone: "muted" };
    }
    if (badge.ends_at !== null && Date.parse(badge.ends_at) <= now) {
        return { label: __("Ended"), tone: "destructive" };
    }
    if (badge.starts_at !== null && Date.parse(badge.starts_at) > now) {
        return { label: __("Scheduled"), tone: "muted" };
    }
    return null;
}

/** The Badges tab of Promotions: labels drawn over product images, in the order they stack. */
export function BadgesPanel() {
    const list = useBadges();
    const roles = useBadgeRoles();
    const save = useSaveBadge();
    const reorder = useReorderBadges();
    const remove = useDeleteBadge();
    const [editing, setEditing] = useState<{ badge: ProductBadge | null; preset?: Preset } | null>(null);
    const [deleting, setDeleting] = useState<ProductBadge | null>(null);

    const items = list.data ?? [];

    const move = (index: number, delta: number) => {
        const ids = items.map((b) => b.id);
        const [id] = ids.splice(index, 1);
        ids.splice(index + delta, 0, id);
        reorder.mutate(ids, { onError: (error) => showToast.error(error.message) });
    };

    const toggle = (badge: ProductBadge, enabled: boolean) =>
        save.mutate(
            { id: badge.id, input: { enabled } },
            {
                onSuccess: () => showToast.success(enabled ? __("Badge turned on.") : __("Badge turned off.")),
                onError: (error) => showToast.error(error.message),
            },
        );

    return (
        <div className="fxc:space-y-4">
            <div className="fxc:flex fxc:flex-wrap fxc:items-center fxc:gap-3">
                <p className="fxc:text-muted-foreground fxc:max-w-2xl fxc:text-sm">
                    {__("Labels such as Sale or New over product images, on the product page and in product lists. A product can get several; badges in the same corner stack in this order.")}
                </p>
                <Button className="fxc:ms-auto" onClick={() => setEditing({ badge: null })}>
                    <Plus aria-hidden className="fxc:size-4" />
                    {__("Add badge")}
                </Button>
            </div>

            <Card>
                {list.isPending ? (
                    <p className="fxc:text-muted-foreground fxc:px-5 fxc:py-4">{__("Loading…")}</p>
                ) : list.isError ? (
                    <p className="fxc:text-destructive fxc:px-5 fxc:py-4">{list.error.message}</p>
                ) : items.length === 0 ? (
                    <div className="fxc:space-y-4 fxc:px-5 fxc:py-8 fxc:text-center">
                        <p className="fxc:text-muted-foreground">{__("No badges yet. Start from one of these:")}</p>
                        <div className="fxc:flex fxc:flex-wrap fxc:justify-center fxc:gap-2">
                            {PRESETS.filter((p) => p.key !== "custom").map((preset) => (
                                <button
                                    key={preset.key}
                                    type="button"
                                    className="fxc:hover:bg-muted fxc:flex fxc:items-center fxc:gap-2 fxc:rounded-md fxc:border fxc:px-3 fxc:py-2 fxc:text-sm fxc:outline-none fxc:focus-visible:ring-2 fxc:focus-visible:ring-primary-accent"
                                    onClick={() => setEditing({ badge: null, preset })}
                                >
                                    <BadgeChip badge={{ shape: "rounded", ...preset.values }} />
                                    <span>{preset.name}</span>
                                </button>
                            ))}
                        </div>
                    </div>
                ) : (
                    <ul>
                        {items.map((badge, index) => {
                            const state = stateOf(badge);
                            return (
                                <li key={badge.id} className="fxc:flex fxc:flex-wrap fxc:items-center fxc:gap-4 fxc:border-b fxc:px-5 fxc:py-3 fxc:last:border-b-0">
                                    <div className="fxc:w-28 fxc:shrink-0">
                                        <BadgeChip badge={badge} className={!badge.enabled ? "fxc:opacity-50" : undefined} />
                                    </div>
                                    <div className="fxc:min-w-0 fxc:flex-1">
                                        <div className="fxc:flex fxc:items-center fxc:gap-2 fxc:text-sm fxc:font-medium">
                                            <span className="fxc:truncate">{badge.label}</span>
                                            {state && <Badge variant={state.tone}>{state.label}</Badge>}
                                        </div>
                                        <div className="fxc:text-muted-foreground fxc:text-xs">{summary(badge, roles)}</div>
                                    </div>
                                    <div className="fxc:flex fxc:items-center fxc:gap-1">
                                        <Switch
                                            aria-label={sprintf(__("Show %s"), sampleLabel(badge.label))}
                                            checked={badge.enabled}
                                            disabled={save.isPending}
                                            onCheckedChange={(v) => toggle(badge, v)}
                                        />
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            className="fxc:ms-2"
                                            aria-label={sprintf(__("Move %s up"), badge.label)}
                                            disabled={index === 0 || reorder.isPending}
                                            onClick={() => move(index, -1)}
                                        >
                                            <ArrowUp aria-hidden className="fxc:size-4" />
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            aria-label={sprintf(__("Move %s down"), badge.label)}
                                            disabled={index === items.length - 1 || reorder.isPending}
                                            onClick={() => move(index, 1)}
                                        >
                                            <ArrowDown aria-hidden className="fxc:size-4" />
                                        </Button>
                                        <Button variant="ghost" size="icon" aria-label={sprintf(__("Edit %s"), badge.label)} onClick={() => setEditing({ badge })}>
                                            <Pencil aria-hidden className="fxc:size-4" />
                                        </Button>
                                        <Button variant="ghost" size="icon" aria-label={sprintf(__("Delete %s"), badge.label)} onClick={() => setDeleting(badge)}>
                                            <Trash2 aria-hidden className="fxc:size-4" />
                                        </Button>
                                    </div>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </Card>

            <BadgeDialog
                open={editing !== null}
                badge={editing?.badge ?? null}
                preset={editing?.preset}
                onOpenChange={(open) => !open && setEditing(null)}
            />

            <Dialog open={deleting !== null} onOpenChange={(open) => !open && !remove.isPending && setDeleting(null)}>
                <DialogContent className="fxc:max-w-md">
                    <DialogHeader>
                        <DialogTitle>{sprintf(__("Delete %s?"), deleting?.label ?? "")}</DialogTitle>
                        <DialogDescription>{__("The badge leaves every product at once. To hide it for now, turn it off instead.")}</DialogDescription>
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
                                        showToast.success(__("Badge deleted."));
                                        setDeleting(null);
                                    },
                                    onError: (error) => showToast.error(error.message),
                                })
                            }
                        >
                            {remove.isPending ? __("Deleting…") : __("Delete badge")}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
