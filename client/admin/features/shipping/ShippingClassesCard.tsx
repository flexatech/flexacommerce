import { useEffect, useMemo, useState } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { Plus, Trash2 } from "lucide-react";

import { showToast } from "@/shared/components/showToast";
import { Badge } from "@/shared/components/ui/badge";
import { Button } from "@/shared/components/ui/button";
import { Card, CardDescription, CardHeader, CardTitle } from "@/shared/components/ui/card";
import { Input } from "@/shared/components/ui/input";
import { __, _n, sprintf } from "@/shared/lib/i18n";
import { useSaveSettings } from "../settings/useSettings";
import { slugify } from "../taxes/ClassesCard";
import type { ShippingClass } from "./useShipping";

const RESERVED = ["", "parent", "inherit"];

type Draft = { slug: string; name: string };

const plain = (classes: ShippingClass[]): Draft[] => classes.map(({ slug, name }) => ({ slug, name }));

/** Add, rename and remove shipping classes; saved on its own, apart from the zones. */
export function ShippingClassesCard({ classes }: { classes: ShippingClass[] }) {
    const qc = useQueryClient();
    const save = useSaveSettings();
    const [draft, setDraft] = useState<Draft[]>(() => plain(classes));
    const [name, setName] = useState("");

    useEffect(() => setDraft(plain(classes)), [classes]);

    const dirty = useMemo(() => JSON.stringify(draft) !== JSON.stringify(plain(classes)), [draft, classes]);
    const slug = slugify(name);
    const taken = RESERVED.includes(slug) || draft.some((c) => c.slug === slug);
    const count = (s: string) => classes.find((c) => c.slug === s)?.products ?? 0;

    const add = () => {
        if (!taken) {
            setDraft([...draft, { slug, name: name.trim() }]);
            setName("");
        }
    };

    const submit = () => {
        const cleaned = draft.map((c) => ({ ...c, name: c.name.trim() === "" ? c.slug : c.name.trim() }));
        save.mutate(
            { shipping_classes: cleaned },
            {
                onSuccess: () => {
                    showToast.success(__("Shipping classes saved."));
                    void qc.invalidateQueries({ queryKey: ["shipping", "zones"] });
                },
                onError: (e) => showToast.error(e.message),
            },
        );
    };

    return (
        <Card>
            <CardHeader>
                <div className="fxc:flex fxc:items-start fxc:justify-between fxc:gap-4">
                    <div className="fxc:space-y-1.5">
                        <CardTitle>{__("Shipping classes")}</CardTitle>
                        <CardDescription>
                            {__("Group products that cost more or less to ship, such as bulky or fragile items. Pick a class on the product, then set its cost in a flat rate or table rate method.")}
                        </CardDescription>
                    </div>
                    <div className="fxc:flex fxc:shrink-0 fxc:items-center fxc:gap-2">
                        {dirty && (
                            <Button variant="ghost" onClick={() => setDraft(plain(classes))} disabled={save.isPending}>
                                {__("Discard")}
                            </Button>
                        )}
                        <Button onClick={submit} disabled={!dirty || save.isPending}>
                            {save.isPending ? __("Saving…") : __("Save classes")}
                        </Button>
                    </div>
                </div>
            </CardHeader>
            {draft.length > 0 && (
                <ul>
                    {draft.map((c, index) => (
                        <li key={c.slug} className="fxc:flex fxc:items-center fxc:gap-3 fxc:border-t fxc:px-5 fxc:py-3">
                            <Input
                                aria-label={__("Class name")}
                                className="fxc:max-w-xs fxc:flex-1"
                                value={c.name}
                                onChange={(e) => setDraft(draft.map((x, i) => (i === index ? { ...x, name: e.target.value } : x)))}
                            />
                            <code className="fxc:text-muted-foreground fxc:text-xs">{c.slug}</code>
                            <span className="fxc:ml-auto">
                                <Badge>{sprintf(_n("%d product", "%d products", count(c.slug)), count(c.slug))}</Badge>
                            </span>
                            <Button variant="ghost" size="icon" aria-label={sprintf(__("Remove %s"), c.name)} onClick={() => setDraft(draft.filter((_, i) => i !== index))}>
                                <Trash2 aria-hidden />
                            </Button>
                        </li>
                    ))}
                </ul>
            )}
            <form
                className="fxc:flex fxc:flex-wrap fxc:items-center fxc:gap-2 fxc:border-t fxc:px-5 fxc:py-3"
                onSubmit={(e) => {
                    e.preventDefault();
                    add();
                }}
            >
                <Input
                    aria-label={__("New shipping class name")}
                    placeholder={__("New class name, for example Bulky")}
                    className="fxc:max-w-xs"
                    maxLength={100}
                    value={name}
                    onChange={(e) => setName(e.target.value)}
                />
                <Button type="submit" variant="outline" disabled={name.trim() === "" || taken}>
                    <Plus aria-hidden />
                    {__("Add class")}
                </Button>
                {name.trim() !== "" && taken && (
                    <span className="fxc:text-destructive fxc:text-xs">
                        {slug === "" ? __("Use at least one Latin letter or digit in the name.") : __("This name is taken.")}
                    </span>
                )}
            </form>
            {dirty && draft.length < classes.length && (
                <p className="fxc:text-muted-foreground fxc:border-t fxc:px-5 fxc:py-3 fxc:text-xs">
                    {__("Products in a removed class ship as if they had no class.")}
                </p>
            )}
        </Card>
    );
}
