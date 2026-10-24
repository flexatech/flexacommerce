import { useState } from "react";
import { ArrowDown, ArrowUp, Check, Pencil, Plus, Trash2, X } from "lucide-react";
import { useMutation, useQueryClient } from "@tanstack/react-query";

import { showToast } from "@/shared/components/showToast";
import { Badge } from "@/shared/components/ui/badge";
import { Button } from "@/shared/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/shared/components/ui/card";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/shared/components/ui/dialog";
import { Input } from "@/shared/components/ui/input";
import { api } from "@/shared/lib/api";
import { __, _n, sprintf } from "@/shared/lib/i18n";
import { PageTitle } from "../../components/PageTitle";
import type { AttributeTerm, StoreAttribute } from "../product/types";
import { ATTRIBUTES_KEY, useStoreAttributes } from "../product/useProduct";

type Listing = { items: StoreAttribute[] };

/** Every attributes route answers with the full listing, which replaces the cache. */
function useAttributeMutation<V>(request: (vars: V) => Promise<Listing>) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: request,
        onSuccess: (listing) => qc.setQueryData(ATTRIBUTES_KEY, listing.items),
        onError: (error) => showToast.error(error.message),
    });
}

export function AttributesPage() {
    const query = useStoreAttributes();
    const [name, setName] = useState("");
    const [deleting, setDeleting] = useState<{ term: AttributeTerm; usage: number; isValue: boolean } | null>(null);

    const create = useAttributeMutation((vars: { name: string }) => api.post<Listing>("admin/attributes", vars));
    const reorder = useAttributeMutation((ids: number[]) => api.post<Listing>("admin/attributes/reorder", { ids }));
    const remove = useAttributeMutation((id: number) => api.delete<Listing>(`admin/attributes/${id}`));

    const items = query.data ?? [];

    const submit = () => {
        const clean = name.trim();
        if (clean === "") {
            return;
        }
        create.mutate({ name: clean }, { onSuccess: () => setName("") });
    };

    const move = (index: number, delta: number) => {
        const ids = items.map((item) => item.id);
        const target = index + delta;
        if (target < 0 || target >= ids.length) {
            return;
        }
        [ids[index], ids[target]] = [ids[target], ids[index]];
        reorder.mutate(ids);
    };

    return (
        <div className="fxc:space-y-6">
            <PageTitle
                title={__("Attributes")}
                description={__("Store attributes can be shared by many products and used to filter the shop. Products can also have their own custom attributes.")}
            />

            <Card>
                <CardHeader>
                    <CardTitle>{__("Add an attribute")}</CardTitle>
                    <CardDescription>{__("For example Color or Size. Add its values after creating it.")}</CardDescription>
                </CardHeader>
                <CardContent>
                    <form
                        className="fxc:flex fxc:max-w-lg fxc:gap-2"
                        onSubmit={(event) => {
                            event.preventDefault();
                            submit();
                        }}
                    >
                        <Input value={name} onChange={(event) => setName(event.target.value)} placeholder={__("Attribute name")} aria-label={__("Attribute name")} />
                        <Button type="submit" disabled={create.isPending || name.trim() === ""}>
                            <Plus aria-hidden />
                            {__("Add")}
                        </Button>
                    </form>
                </CardContent>
            </Card>

            {query.isPending ? (
                <p className="fxc:text-muted-foreground">{__("Loading attributes…")}</p>
            ) : items.length === 0 ? (
                <p className="fxc:text-muted-foreground">{__("No store attributes yet.")}</p>
            ) : (
                <div className="fxc:space-y-4">
                    {items.map((attribute, index) => (
                        <AttributeCard
                            key={attribute.id}
                            attribute={attribute}
                            first={index === 0}
                            last={index === items.length - 1}
                            onMove={(delta) => move(index, delta)}
                            onDelete={(term, isValue) => setDeleting({ term, isValue, usage: isValue ? 0 : attribute.usage })}
                        />
                    ))}
                </div>
            )}

            <Dialog open={deleting !== null} onOpenChange={(open) => !open && !remove.isPending && setDeleting(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{deleting?.isValue ? __("Delete this value?") : __("Delete this attribute?")}</DialogTitle>
                        <DialogDescription>
                            {deleting && !deleting.isValue && deleting.usage > 0
                                ? sprintf(
                                      _n(
                                          "%1$s is used by %2$d product. It is removed from that product, and its variations that depend on it are deleted or disabled on the next save.",
                                          "%1$s is used by %2$d products. It is removed from those products, and their variations that depend on it are deleted or disabled on the next save.",
                                          deleting.usage,
                                      ),
                                      deleting.term.name,
                                      deleting.usage,
                                  )
                                : sprintf(__("%s will be deleted. This cannot be undone."), deleting?.term.name ?? "")}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="ghost" onClick={() => setDeleting(null)} disabled={remove.isPending}>
                            {__("Cancel")}
                        </Button>
                        <Button
                            variant="destructive"
                            disabled={remove.isPending}
                            onClick={() => deleting && remove.mutate(deleting.term.id, { onSuccess: () => setDeleting(null) })}
                        >
                            {remove.isPending ? __("Deleting…") : __("Delete")}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}

function AttributeCard({
    attribute,
    first,
    last,
    onMove,
    onDelete,
}: {
    attribute: StoreAttribute;
    first: boolean;
    last: boolean;
    onMove: (delta: number) => void;
    onDelete: (term: AttributeTerm, isValue: boolean) => void;
}) {
    const [value, setValue] = useState("");
    const addValue = useAttributeMutation((name: string) => api.post<Listing>(`admin/attributes/${attribute.id}/values`, { name }));

    const submit = () => {
        const clean = value.trim();
        if (clean !== "") {
            addValue.mutate(clean, { onSuccess: () => setValue("") });
        }
    };

    return (
        <Card>
            <div className="fxc:border-border fxc:flex fxc:items-center fxc:gap-3 fxc:border-b fxc:px-5 fxc:py-3">
                <div className="fxc:min-w-0 fxc:flex-1">
                    <EditableName term={attribute} className="fxc:text-base fxc:font-semibold" />
                </div>
                <Badge>{sprintf(_n("%d product", "%d products", attribute.usage), attribute.usage)}</Badge>
                <Button variant="ghost" size="icon" aria-label={__("Move up")} disabled={first} onClick={() => onMove(-1)}>
                    <ArrowUp aria-hidden />
                </Button>
                <Button variant="ghost" size="icon" aria-label={__("Move down")} disabled={last} onClick={() => onMove(1)}>
                    <ArrowDown aria-hidden />
                </Button>
                <Button variant="ghost" size="icon" aria-label={__("Delete attribute")} onClick={() => onDelete(attribute, false)}>
                    <Trash2 aria-hidden />
                </Button>
            </div>
            <CardContent className="fxc:space-y-3">
                {attribute.values.length === 0 ? (
                    <p className="fxc:text-muted-foreground fxc:text-sm">{__("No values yet.")}</p>
                ) : (
                    <ul className="fxc:flex fxc:flex-wrap fxc:gap-2">
                        {attribute.values.map((term) => (
                            <li key={term.id} className="fxc:bg-muted fxc:flex fxc:items-center fxc:gap-1 fxc:rounded-md fxc:py-1 fxc:pr-1 fxc:pl-3">
                                <EditableName term={term} className="fxc:text-sm" />
                                <Button variant="ghost" size="icon" className="fxc:size-7" aria-label={sprintf(__("Delete %s"), term.name)} onClick={() => onDelete(term, true)}>
                                    <X aria-hidden />
                                </Button>
                            </li>
                        ))}
                    </ul>
                )}
                <form
                    className="fxc:flex fxc:max-w-md fxc:gap-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        submit();
                    }}
                >
                    <Input value={value} onChange={(event) => setValue(event.target.value)} placeholder={__("New value")} aria-label={__("New value")} />
                    <Button type="submit" variant="outline" disabled={addValue.isPending || value.trim() === ""}>
                        {__("Add value")}
                    </Button>
                </form>
            </CardContent>
        </Card>
    );
}

function EditableName({ term, className }: { term: AttributeTerm; className?: string }) {
    const [editing, setEditing] = useState(false);
    const [name, setName] = useState(term.name);
    const rename = useAttributeMutation((next: string) => api.patch<Listing>(`admin/attributes/${term.id}`, { name: next }));

    if (!editing) {
        return (
            <span className="fxc:flex fxc:items-center fxc:gap-1">
                <span className={className}>{term.name}</span>
                <Button
                    variant="ghost"
                    size="icon"
                    className="fxc:size-7"
                    aria-label={sprintf(__("Rename %s"), term.name)}
                    onClick={() => {
                        setName(term.name);
                        setEditing(true);
                    }}
                >
                    <Pencil aria-hidden className="fxc:size-3.5" />
                </Button>
            </span>
        );
    }

    const save = () => {
        const clean = name.trim();
        if (clean === "" || clean === term.name) {
            setEditing(false);
            return;
        }
        rename.mutate(clean, { onSuccess: () => setEditing(false) });
    };

    return (
        <form
            className="fxc:flex fxc:items-center fxc:gap-1"
            onSubmit={(event) => {
                event.preventDefault();
                save();
            }}
        >
            <Input autoFocus value={name} onChange={(event) => setName(event.target.value)} className="fxc:h-8 fxc:w-48" aria-label={__("Name")} />
            <Button type="submit" variant="ghost" size="icon" className="fxc:size-8" aria-label={__("Save name")} disabled={rename.isPending}>
                <Check aria-hidden />
            </Button>
            <Button variant="ghost" size="icon" className="fxc:size-8" aria-label={__("Cancel")} onClick={() => setEditing(false)}>
                <X aria-hidden />
            </Button>
        </form>
    );
}
