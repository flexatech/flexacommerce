import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useState } from "react";
import { PackagePlus, Trash2 } from "lucide-react";

import { showToast } from "@/shared/components/showToast";
import { Button } from "@/shared/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/shared/components/ui/card";
import { api } from "@/shared/lib/api";
import { __, sprintf } from "@/shared/lib/i18n";
import { productsUrl } from "./useProductCsv";

type StoreType = "physical" | "digital" | "both";
type Part = "shipping" | "payments" | "tax" | "pages";

interface SampleStatus {
    installed: boolean;
    products: number;
    coupons: number;
    type: StoreType | "";
    parts: Part[];
    shop_url: string;
}

interface Installed {
    products: number;
    skipped: number;
    reviews: number;
    coupons: number;
    errors: string[];
}

const KEY = ["products", "sample"];

const TYPES: { value: StoreType; label: string; help: string }[] = [
    { value: "physical", label: __("Physical products"), help: __("Clothing, decor, a subscription box and bundles, shipped to the customer.") },
    { value: "digital", label: __("Digital products"), help: __("Music, an ebook, wallpapers, presets and a gift card. Checkout asks for no shipping address.") },
    { value: "both", label: __("Both"), help: __("Every sample product, so a cart can mix items to ship with downloads.") },
];

const PARTS: { value: Part; label: string; help: string }[] = [
    {
        value: "shipping",
        label: __("Shipping"),
        help: __("A zone for your store country with flat rate (extra for bulky items), free shipping from 50 and local pickup. Skipped for a digital store."),
    },
    { value: "payments", label: __("Payments"), help: __("Turns on bank transfer with a sample account. A digital store also turns off cash on delivery.") },
    { value: "tax", label: __("Tax"), help: __("Turns on tax with one 10% rate for your store country.") },
    { value: "pages", label: __("Pages and menu"), help: __("A terms page, a wishlist page and a menu with the shop, its categories, cart, checkout and account.") },
];

/** Adds or removes the sample store: products from sample-data/sample_products.csv and, on request, the store settings around them. */
export function SamplePanel() {
    const client = useQueryClient();
    const [type, setType] = useState<StoreType>("both");
    const [parts, setParts] = useState<Part[]>(["shipping", "payments", "pages"]);
    const status = useQuery({ queryKey: KEY, queryFn: () => api.get<SampleStatus>("admin/products/sample") });
    const install = useMutation({
        mutationFn: () => api.post<Installed>("admin/products/sample", { type, parts }),
        onSuccess: (counts) => {
            showToast.success(sprintf(__("Added %1$d sample products and %2$d coupons."), counts.products, counts.coupons));
            counts.errors.forEach((error) => showToast.error(error));
        },
        onError: (e) => showToast.error(e.message),
        onSettled: () => client.invalidateQueries({ queryKey: KEY }),
    });
    const remove = useMutation({
        mutationFn: () => api.delete<{ products: number }>("admin/products/sample"),
        onSuccess: (counts) => showToast.success(sprintf(__("Removed %d sample products."), counts.products)),
        onError: (e) => showToast.error(e.message),
        onSettled: () => client.invalidateQueries({ queryKey: KEY }),
    });

    const confirmRemove = () => {
        if (
            window.confirm(
                __("Remove the demo store? Its products, pictures, reviews, coupons, pages and menu go, and the settings it changed are put back. Your own products and any setting you changed since stay."),
            )
        ) {
            remove.mutate();
        }
    };

    const toggle = (part: Part, on: boolean) => setParts((current) => (on ? [...current, part] : current.filter((p) => p !== part)));

    const data = status.data;
    const installed = data?.installed ?? false;
    const busy = install.isPending || remove.isPending;
    const typeLabel = TYPES.find((t) => t.value === data?.type)?.label ?? "";

    return (
        <Card>
            <CardHeader>
                <CardTitle>{__("Demo store")}</CardTitle>
                <CardDescription>
                    {installed
                        ? sprintf(__("The demo store is in (%1$s): %2$d products and %3$d coupons (SAMPLE10, SAMPLE5, FREESHIP)."), typeLabel, data?.products ?? 0, data?.coupons ?? 0)
                        : __("A small store to try things out, with pictures, reviews and coupons. A product whose SKU you already use is skipped, and a setting you already made is kept.")}
                </CardDescription>
            </CardHeader>
            <CardContent className="fxc:space-y-5">
                {!installed && (
                    <>
                        <fieldset className="fxc:space-y-2">
                            <legend className="fxc:mb-2 fxc:text-sm fxc:font-medium fxc:text-slate-900 fxc:dark:text-slate-100">{__("What does the store sell?")}</legend>
                            {TYPES.map((option) => (
                                <label key={option.value} className="fxc:flex fxc:items-start fxc:gap-2 fxc:text-sm">
                                    <input
                                        type="radio"
                                        name="fxc-sample-type"
                                        className="flexacommerce-check fxc:mt-0.5"
                                        checked={type === option.value}
                                        onChange={() => setType(option.value)}
                                    />
                                    <span>
                                        <span className="fxc:font-medium fxc:text-slate-900 fxc:dark:text-slate-100">{option.label}</span>
                                        <span className="fxc:block fxc:text-slate-600 fxc:dark:text-slate-400">{option.help}</span>
                                    </span>
                                </label>
                            ))}
                        </fieldset>
                        <fieldset className="fxc:space-y-2">
                            <legend className="fxc:mb-2 fxc:text-sm fxc:font-medium fxc:text-slate-900 fxc:dark:text-slate-100">{__("Also set up")}</legend>
                            {PARTS.map((option) => (
                                <label key={option.value} className="fxc:flex fxc:items-start fxc:gap-2 fxc:text-sm">
                                    <input
                                        type="checkbox"
                                        className="flexacommerce-check fxc:mt-0.5"
                                        checked={parts.includes(option.value)}
                                        disabled={option.value === "shipping" && type === "digital"}
                                        onChange={(e) => toggle(option.value, e.target.checked)}
                                    />
                                    <span>
                                        <span className="fxc:font-medium fxc:text-slate-900 fxc:dark:text-slate-100">{option.label}</span>
                                        <span className="fxc:block fxc:text-slate-600 fxc:dark:text-slate-400">{option.help}</span>
                                    </span>
                                </label>
                            ))}
                        </fieldset>
                    </>
                )}
                <div className="fxc:flex fxc:flex-wrap fxc:items-center fxc:gap-3">
                    {installed ? (
                        <>
                            {data?.shop_url && (
                                <Button asChild>
                                    <a href={data.shop_url} target="_blank" rel="noreferrer">
                                        {__("Open the shop")}
                                    </a>
                                </Button>
                            )}
                            <Button asChild variant="outline">
                                <a href={productsUrl()}>{__("View products")}</a>
                            </Button>
                            <Button variant="outline" onClick={confirmRemove} disabled={busy}>
                                <Trash2 aria-hidden className="fxc:size-4" />
                                {remove.isPending ? __("Removing…") : __("Remove demo store")}
                            </Button>
                        </>
                    ) : (
                        <Button onClick={() => install.mutate()} disabled={busy || status.isPending}>
                            <PackagePlus aria-hidden className="fxc:size-4" />
                            {install.isPending ? __("Adding…") : __("Add demo store")}
                        </Button>
                    )}
                </div>
            </CardContent>
        </Card>
    );
}
