import { useState } from "react";
import { ArrowDown, ArrowUp, ListPlus, Pencil, Plus, Search, Trash2 } from "lucide-react";

import { showToast } from "@/shared/components/showToast";
import { Badge } from "@/shared/components/ui/badge";
import { Button } from "@/shared/components/ui/button";
import { Card } from "@/shared/components/ui/card";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/shared/components/ui/dialog";
import { Input } from "@/shared/components/ui/input";
import { Switch } from "@/shared/components/ui/switch";
import { __, _n, sprintf } from "@/shared/lib/i18n";
import { PageTitle } from "../../components/PageTitle";
import { OptionSetDialog, SET_PRESETS, typeOf, type SetPreset } from "./OptionSetDialog";
import { useDeleteOptionSet, useOptionSets, useReorderOptionSets, useSaveOptionSet, type OptionSet } from "./useOptionSets";

/** "3 fields · Products in 2 categories" */
function summary(set: OptionSet): string {
    const inputs = set.fields.filter((f) => f.type !== "heading").length;
    const fields = sprintf(_n("%d field", "%d fields", inputs), inputs);
    const where =
        set.applies_to === "products"
            ? sprintf(_n("%d product", "%d products", set.product_ids.length), set.product_ids.length)
            : set.applies_to === "categories"
              ? sprintf(_n("Products in %d category", "Products in %d categories", set.category_ids.length), set.category_ids.length)
              : __("All products");
    return `${fields} · ${where}`;
}

/** The search box shows once the list is longer than a screen. */
const SEARCH_FROM = 8;

function matches(set: OptionSet, words: string[]): boolean {
    const text = [set.name, ...set.fields.map((f) => f.label)].join(" ").toLocaleLowerCase();
    return words.every((word) => text.includes(word));
}

/** Extra fields on the product page: engraving, gift wrap, a custom size. */
export function OptionSetsPage() {
    const list = useOptionSets();
    const save = useSaveOptionSet();
    const remove = useDeleteOptionSet();
    const reorder = useReorderOptionSets();
    const [editing, setEditing] = useState<{ set: OptionSet | null; preset?: SetPreset } | null>(null);
    const [deleting, setDeleting] = useState<OptionSet | null>(null);
    const [search, setSearch] = useState("");

    const items = list.data?.items ?? [];
    const words = search.toLocaleLowerCase().split(/\s+/).filter(Boolean);
    // Moving only makes sense in the whole list, so the arrows wait while a search is on.
    const searching = words.length > 0;
    const shown = searching ? items.filter((set) => matches(set, words)) : items;

    const toggle = (set: OptionSet, enabled: boolean) =>
        save.mutate(
            { id: set.id, input: { enabled } },
            {
                onSuccess: () => showToast.success(enabled ? __("Option set turned on.") : __("Option set turned off.")),
                onError: (error) => showToast.error(error.message),
            },
        );

    const move = (index: number, delta: number) => {
        const ids = items.map((s) => s.id);
        [ids[index], ids[index + delta]] = [ids[index + delta], ids[index]];
        reorder.mutate(ids, { onError: (error) => showToast.error(error.message) });
    };

    return (
        <div>
            <PageTitle
                title={__("Product options")}
                description={__("Fields a customer fills in before adding a product to the cart, such as an engraving text or a gift message. A choice can add to the price.")}
                actions={
                    <Button onClick={() => setEditing({ set: null })}>
                        <Plus aria-hidden className="fxc:size-4" />
                        {__("Add option set")}
                    </Button>
                }
            />

            {items.length >= SEARCH_FROM && (
                <div className="fxc:relative fxc:mb-4 fxc:w-64">
                    <Search aria-hidden className="fxc:text-muted-foreground fxc:pointer-events-none fxc:absolute fxc:top-2.5 fxc:left-3 fxc:size-4" />
                    <Input
                        aria-label={__("Search option sets")}
                        placeholder={__("Set or field name")}
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        className="fxc:pl-9!"
                    />
                </div>
            )}

            <Card>
                {list.isPending ? (
                    <p className="fxc:text-muted-foreground fxc:px-5 fxc:py-4">{__("Loading…")}</p>
                ) : list.isError ? (
                    <p className="fxc:text-destructive fxc:px-5 fxc:py-4">{list.error.message}</p>
                ) : items.length === 0 ? (
                    <div className="fxc:space-y-4 fxc:px-5 fxc:py-8 fxc:text-center">
                        <p className="fxc:text-muted-foreground">{__("No option sets yet. Start from one of these:")}</p>
                        <div className="fxc:flex fxc:flex-wrap fxc:justify-center fxc:gap-2">
                            {SET_PRESETS.filter((p) => p.key !== "custom").map((preset) => (
                                <button
                                    key={preset.key}
                                    type="button"
                                    className="fxc:hover:bg-muted fxc:flex fxc:max-w-60 fxc:items-start fxc:gap-3 fxc:rounded-md fxc:border fxc:px-3 fxc:py-2 fxc:text-start fxc:text-sm fxc:outline-none fxc:focus-visible:ring-2 fxc:focus-visible:ring-primary-accent"
                                    onClick={() => setEditing({ set: null, preset })}
                                >
                                    <span className="fxc:bg-muted fxc:text-muted-foreground fxc:flex fxc:size-9 fxc:shrink-0 fxc:items-center fxc:justify-center fxc:rounded-md">
                                        <ListPlus aria-hidden className="fxc:size-4" />
                                    </span>
                                    <span>
                                        <span className="fxc:block fxc:font-medium">{preset.name}</span>
                                        <span className="fxc:text-muted-foreground fxc:block fxc:text-xs">{preset.description}</span>
                                    </span>
                                </button>
                            ))}
                        </div>
                    </div>
                ) : shown.length === 0 ? (
                    <p className="fxc:text-muted-foreground fxc:px-5 fxc:py-4">{__("No option set matches your search.")}</p>
                ) : (
                    <ul>
                        {shown.map((set) => {
                            const index = items.indexOf(set);
                            return (
                                <li key={set.id} className="fxc:flex fxc:flex-wrap fxc:items-center fxc:gap-4 fxc:border-b fxc:px-5 fxc:py-3 fxc:last:border-b-0">
                                    <div className="fxc:flex fxc:-space-x-2">
                                        {set.fields.slice(0, 3).map((field) => {
                                            const Icon = typeOf(field.type).icon;
                                            return (
                                                <span
                                                    key={field.id}
                                                    className="fxc:bg-muted fxc:text-muted-foreground fxc:ring-background fxc:flex fxc:size-8 fxc:items-center fxc:justify-center fxc:rounded-full fxc:ring-2"
                                                >
                                                    <Icon aria-hidden className="fxc:size-4" />
                                                </span>
                                            );
                                        })}
                                    </div>
                                    <div className="fxc:min-w-0 fxc:flex-1">
                                        <div className="fxc:flex fxc:items-center fxc:gap-2 fxc:text-sm fxc:font-medium">
                                            <span className="fxc:truncate">{set.name}</span>
                                            {!set.enabled && <Badge variant="muted">{__("Off")}</Badge>}
                                        </div>
                                        <div className="fxc:text-muted-foreground fxc:truncate fxc:text-xs">
                                            {summary(set)}
                                            {" · "}
                                            {set.fields
                                                .filter((f) => f.type !== "heading")
                                                .map((f) => f.label)
                                                .join(", ")}
                                        </div>
                                    </div>
                                    <div className="fxc:flex fxc:items-center fxc:gap-1">
                                        <Button variant="ghost" size="icon" aria-label={sprintf(__("Move %s up"), set.name)} disabled={searching || index === 0 || reorder.isPending} onClick={() => move(index, -1)}>
                                            <ArrowUp aria-hidden className="fxc:size-4" />
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            aria-label={sprintf(__("Move %s down"), set.name)}
                                            disabled={searching || index === items.length - 1 || reorder.isPending}
                                            onClick={() => move(index, 1)}
                                        >
                                            <ArrowDown aria-hidden className="fxc:size-4" />
                                        </Button>
                                        <Switch
                                            aria-label={sprintf(__("Show %s"), set.name)}
                                            className="fxc:ms-2"
                                            checked={set.enabled}
                                            disabled={save.isPending}
                                            onCheckedChange={(v) => toggle(set, v)}
                                        />
                                        <Button variant="ghost" size="icon" className="fxc:ms-2" aria-label={sprintf(__("Edit %s"), set.name)} onClick={() => setEditing({ set })}>
                                            <Pencil aria-hidden className="fxc:size-4" />
                                        </Button>
                                        <Button variant="ghost" size="icon" aria-label={sprintf(__("Delete %s"), set.name)} onClick={() => setDeleting(set)}>
                                            <Trash2 aria-hidden className="fxc:size-4" />
                                        </Button>
                                    </div>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </Card>
            {items.length > 1 && !searching && <p className="fxc:text-muted-foreground fxc:mt-3 fxc:text-xs">{__("When several sets fit a product, their fields show in this order.")}</p>}

            <OptionSetDialog
                open={editing !== null}
                set={editing?.set ?? null}
                preset={editing?.preset}
                onOpenChange={(open) => !open && setEditing(null)}
            />

            <Dialog open={deleting !== null} onOpenChange={(open) => !open && !remove.isPending && setDeleting(null)}>
                <DialogContent className="fxc:max-w-md">
                    <DialogHeader>
                        <DialogTitle>{sprintf(__("Delete %s?"), deleting?.name ?? "")}</DialogTitle>
                        <DialogDescription>
                            {__("The fields leave the product pages at once. Items already in a cart keep their labels but lose the extra charge. To pause the set, turn it off instead.")}
                        </DialogDescription>
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
                                        showToast.success(__("Option set deleted."));
                                        setDeleting(null);
                                    },
                                    onError: (error) => showToast.error(error.message),
                                })
                            }
                        >
                            {remove.isPending ? __("Deleting…") : __("Delete set")}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
