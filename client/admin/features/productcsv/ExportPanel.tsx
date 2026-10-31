import { Download } from "lucide-react";
import { useState } from "react";

import { showToast } from "@/shared/components/showToast";
import { Button } from "@/shared/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/shared/components/ui/card";
import { Label } from "@/shared/components/ui/label";
import { Switch } from "@/shared/components/ui/switch";
import { __, sprintf } from "@/shared/lib/i18n";
import { useExport } from "./useProductCsv";

export function ExportPanel() {
    const [meta, setMeta] = useState(false);
    const run = useExport();

    const start = () =>
        run.mutate(meta, {
            onSuccess: ({ rows }) => showToast.success(sprintf(__("Exported %d rows."), rows)),
            onError: (e) => showToast.error(e.message),
        });

    return (
        <Card>
            <CardHeader>
                <CardTitle>{__("Export products")}</CardTitle>
                <CardDescription>
                    {__("Every product, with one row per variation. Prices, stock, categories, tags, images, attributes and downloadable files are included.")}
                </CardDescription>
            </CardHeader>
            <CardContent className="fxc:space-y-4">
                <div className="fxc:flex fxc:items-center fxc:gap-3">
                    <Switch id="fxc-csv-meta" checked={meta} onCheckedChange={setMeta} disabled={run.isPending} />
                    <Label htmlFor="fxc-csv-meta">{__("Include custom fields")}</Label>
                </div>
                <p className="fxc:text-muted-foreground fxc:text-xs">{__("Adds a Meta: column for each custom field whose name does not start with an underscore.")}</p>
                <div className="fxc:flex fxc:items-center fxc:gap-3">
                    <Button onClick={start} disabled={run.isPending}>
                        <Download aria-hidden className="fxc:size-4" />
                        {run.isPending ? __("Exporting…") : __("Download CSV")}
                    </Button>
                    {run.progress && <span className="fxc:text-muted-foreground fxc:text-sm">{sprintf(__("Page %1$d of %2$d"), run.progress.done, run.progress.total)}</span>}
                </div>
            </CardContent>
        </Card>
    );
}
