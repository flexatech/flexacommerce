import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { PackagePlus, Trash2 } from "lucide-react";

import { showToast } from "@/shared/components/showToast";
import { Button } from "@/shared/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/shared/components/ui/card";
import { api } from "@/shared/lib/api";
import { __, sprintf } from "@/shared/lib/i18n";
import { productsUrl } from "./useProductCsv";

interface SampleStatus {
    installed: boolean;
    products: number;
    coupons: number;
}

interface Installed {
    products: number;
    skipped: number;
    reviews: number;
    coupons: number;
    errors: string[];
}

const KEY = ["products", "sample"];

/** Adds or removes the shipped sample store (sample-data/sample_products.csv plus pictures, reviews and coupons). */
export function SamplePanel() {
    const client = useQueryClient();
    const status = useQuery({ queryKey: KEY, queryFn: () => api.get<SampleStatus>("admin/products/sample") });
    const install = useMutation({
        mutationFn: () => api.post<Installed>("admin/products/sample"),
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
        if (window.confirm(__("Remove the sample products, their pictures, reviews and coupons? Your own products stay."))) {
            remove.mutate();
        }
    };

    const installed = status.data?.installed ?? false;
    const busy = install.isPending || remove.isPending;

    return (
        <Card>
            <CardHeader>
                <CardTitle>{__("Sample products")}</CardTitle>
                <CardDescription>
                    {installed
                        ? sprintf(__("The sample store is in: %1$d products and %2$d coupons (SAMPLE10, SAMPLE5, FREESHIP)."), status.data?.products ?? 0, status.data?.coupons ?? 0)
                        : __(
                              "A small clothing store to try things out: simple, variable, grouped, external, bundle, downloadable, subscription and gift card products, with pictures, reviews and three coupons. A product whose SKU you already use is skipped.",
                          )}
                </CardDescription>
            </CardHeader>
            <CardContent className="fxc:flex fxc:flex-wrap fxc:items-center fxc:gap-3">
                {installed ? (
                    <>
                        <Button asChild>
                            <a href={productsUrl()}>{__("View products")}</a>
                        </Button>
                        <Button variant="outline" onClick={confirmRemove} disabled={busy}>
                            <Trash2 aria-hidden className="fxc:size-4" />
                            {remove.isPending ? __("Removing…") : __("Remove sample products")}
                        </Button>
                    </>
                ) : (
                    <Button variant="outline" onClick={() => install.mutate()} disabled={busy || status.isPending}>
                        <PackagePlus aria-hidden className="fxc:size-4" />
                        {install.isPending ? __("Adding…") : __("Add sample products")}
                    </Button>
                )}
            </CardContent>
        </Card>
    );
}
