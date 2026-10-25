import { useState } from "react";
import { Plus, Trash2 } from "lucide-react";

import { Badge } from "@/shared/components/ui/badge";
import { Button } from "@/shared/components/ui/button";
import { Card, CardDescription, CardHeader, CardTitle } from "@/shared/components/ui/card";
import { Input } from "@/shared/components/ui/input";
import { __, _n, sprintf } from "@/shared/lib/i18n";
import type { TaxClassSummary } from "./useTaxes";

type TaxClass = { slug: string; name: string };

/** Same rule as sanitize_title for the plain names people type here. */
function slugify(name: string): string {
    return name
        .toLowerCase()
        .normalize("NFKD")
        .replace(/[̀-ͯ]/g, "")
        .replace(/[^a-z0-9]+/g, "-")
        .replace(/^-+|-+$/g, "")
        .slice(0, 50);
}

const RESERVED = ["", "parent", "inherit"];

export function ClassesCard({
    classes,
    counts,
    onChange,
}: {
    classes: TaxClass[];
    counts: TaxClassSummary[];
    onChange: (classes: TaxClass[]) => void;
}) {
    const [name, setName] = useState("");
    const slug = slugify(name);
    const taken = RESERVED.includes(slug) || classes.some((c) => c.slug === slug);

    const add = () => {
        if (!taken) {
            onChange([...classes, { slug, name: name.trim() }]);
            setName("");
        }
    };

    const count = (s: string) => counts.find((c) => c.slug === s)?.rates ?? 0;

    return (
        <Card>
            <CardHeader>
                <CardTitle>{__("Tax classes")}</CardTitle>
                <CardDescription>
                    {__("Each product picks a class; each class has its own rates. Standard always exists. Removing a class deletes its rates when you save.")}
                </CardDescription>
            </CardHeader>
            <ul>
                <li className="fxc:flex fxc:items-center fxc:gap-3 fxc:border-b fxc:px-5 fxc:py-3">
                    <span className="fxc:flex-1 fxc:text-sm fxc:font-medium">{__("Standard")}</span>
                    <Badge>{sprintf(_n("%d rate", "%d rates", count("")), count(""))}</Badge>
                </li>
                {classes.map((c, index) => (
                    <li key={c.slug} className="fxc:flex fxc:items-center fxc:gap-3 fxc:border-b fxc:px-5 fxc:py-3">
                        <Input
                            aria-label={__("Class name")}
                            className="fxc:max-w-xs fxc:flex-1"
                            value={c.name}
                            onChange={(e) => onChange(classes.map((x, i) => (i === index ? { ...x, name: e.target.value } : x)))}
                        />
                        <code className="fxc:text-muted-foreground fxc:text-xs">{c.slug}</code>
                        <span className="fxc:ml-auto">
                            <Badge>{sprintf(_n("%d rate", "%d rates", count(c.slug)), count(c.slug))}</Badge>
                        </span>
                        <Button
                            variant="ghost"
                            size="icon"
                            aria-label={sprintf(__("Remove %s"), c.name)}
                            onClick={() => onChange(classes.filter((_, i) => i !== index))}
                        >
                            <Trash2 aria-hidden />
                        </Button>
                    </li>
                ))}
            </ul>
            <form
                className="fxc:flex fxc:items-center fxc:gap-2 fxc:px-5 fxc:py-3"
                onSubmit={(e) => {
                    e.preventDefault();
                    add();
                }}
            >
                <Input
                    aria-label={__("New class name")}
                    placeholder={__("New class name, for example Food")}
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
        </Card>
    );
}
