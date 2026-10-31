import { useState } from "react";
import { Link2, Pencil, Trash2 } from "lucide-react";

import { showToast } from "@/shared/components/showToast";
import { Button } from "@/shared/components/ui/button";
import { Card, CardHeader, CardTitle } from "@/shared/components/ui/card";
import { Input } from "@/shared/components/ui/input";
import { Label } from "@/shared/components/ui/label";
import { __, sprintf } from "@/shared/lib/i18n";
import { formatDate } from "./format";
import type { OrderDetail, OrderDownload } from "./types";
import { useGrantDownloads, useRevokeDownload, useUpdateDownload } from "./useOrders";

const OPEN_STATUSES = ["processing", "completed"];

/**
 * The files the customer may download from this order (ADR-058). The admin
 * can change the downloads left and the expiry, take a file away, or grant
 * the files the products sell now.
 */
export function DownloadsCard({ order }: { order: OrderDetail }) {
    const grant = useGrantDownloads(order.id);
    const sellsFiles = order.items.some((item) => item.downloadable);
    if (order.downloads.length === 0 && !sellsFiles) {
        return null;
    }
    const canGrant = order.can.edit && sellsFiles && OPEN_STATUSES.includes(order.status);

    return (
        <Card>
            <CardHeader className="fxc:flex fxc:flex-row fxc:items-center fxc:justify-between fxc:gap-3">
                <CardTitle>{__("Downloads")}</CardTitle>
                {canGrant && (
                    <Button
                        variant="outline"
                        size="sm"
                        disabled={grant.isPending}
                        onClick={() =>
                            grant.mutate(undefined, {
                                onSuccess: () => showToast.success(__("Download access is up to date.")),
                                onError: (e) => showToast.error(e.message),
                            })
                        }
                    >
                        {__("Grant missing files")}
                    </Button>
                )}
            </CardHeader>
            {order.downloads.length === 0 ? (
                <p className="fxc:text-muted-foreground fxc:px-5 fxc:pb-4 fxc:text-sm">
                    {OPEN_STATUSES.includes(order.status)
                        ? __("No download links yet. Grant the files to send the customer their links.")
                        : __("Links are created when the order is paid.")}
                </p>
            ) : (
                <ul className="fxc:text-sm">
                    {order.downloads.map((download) => (
                        <DownloadRow key={download.id} order={order} download={download} />
                    ))}
                </ul>
            )}
        </Card>
    );
}

function DownloadRow({ order, download }: { order: OrderDetail; download: OrderDownload }) {
    const update = useUpdateDownload(order.id);
    const revoke = useRevokeDownload(order.id);
    const [editing, setEditing] = useState(false);
    const [remaining, setRemaining] = useState("");
    const [expires, setExpires] = useState("");

    const startEdit = () => {
        setRemaining(download.downloads_remaining === null ? "" : String(download.downloads_remaining));
        setExpires(download.access_expires === null ? "" : download.access_expires.slice(0, 10));
        setEditing(true);
    };
    const save = () => {
        const left = remaining.trim();
        if (left !== "" && !/^\d+$/.test(left)) {
            showToast.error(__("Enter a whole number of downloads, or leave it empty for no limit."));
            return;
        }
        update.mutate(
            { permission: download.id, downloads_remaining: left === "" ? null : Number(left), access_expires: expires === "" ? null : expires },
            {
                onSuccess: () => {
                    setEditing(false);
                    showToast.success(__("Download access saved."));
                },
                onError: (e) => showToast.error(e.message),
            },
        );
    };
    // Opening the link here would use one of the customer's downloads, so the admin copies it to send on.
    const copy = () => {
        navigator.clipboard.writeText(download.url).then(
            () => showToast.success(__("Link copied.")),
            () => showToast.error(__("The link could not be copied.")),
        );
    };
    const remove = () => {
        if (!window.confirm(__("Remove this download? The customer's link stops working."))) {
            return;
        }
        revoke.mutate(download.id, {
            onSuccess: () => showToast.success(__("Download removed.")),
            onError: (e) => showToast.error(e.message),
        });
    };

    const name = download.file_name !== "" ? download.file_name : __("File removed from the product");
    const left = download.downloads_remaining === null ? __("No limit") : sprintf(__("%d left"), download.downloads_remaining);
    const until = download.access_expires === null ? __("Never expires") : sprintf(__("Expires %s"), formatDate(download.access_expires, false));

    return (
        <li className="fxc:border-b fxc:last:border-b-0 fxc:px-5 fxc:py-3">
            <div className="fxc:flex fxc:items-start fxc:gap-3">
                <div className="fxc:min-w-0 fxc:flex-1">
                    <div className="fxc:font-medium fxc:break-words">
                        {name}
                        {download.product_name !== "" && <span className="fxc:text-muted-foreground fxc:font-normal"> · {download.product_name}</span>}
                    </div>
                    <div className="fxc:text-muted-foreground fxc:text-xs">
                        {left} · {until} · {sprintf(__("Downloaded %d times"), download.download_count)}
                        {download.last_download_at !== null && <> · {sprintf(__("last %s"), formatDate(download.last_download_at))}</>}
                    </div>
                </div>
                {order.can.edit && !editing && (
                    <div className="fxc:flex fxc:shrink-0 fxc:gap-1">
                        {download.url !== "" && (
                            <Button variant="ghost" size="icon" aria-label={sprintf(__("Copy the link to %s"), name)} onClick={copy}>
                                <Link2 aria-hidden className="fxc:size-4" />
                            </Button>
                        )}
                        <Button variant="ghost" size="icon" aria-label={sprintf(__("Edit access to %s"), name)} onClick={startEdit}>
                            <Pencil aria-hidden className="fxc:size-4" />
                        </Button>
                        <Button variant="ghost" size="icon" aria-label={sprintf(__("Remove %s"), name)} disabled={revoke.isPending} onClick={remove}>
                            <Trash2 aria-hidden className="fxc:size-4" />
                        </Button>
                    </div>
                )}
            </div>
            {editing && (
                <div className="fxc:mt-3 fxc:grid fxc:gap-3 fxc:sm:grid-cols-[1fr_1fr_auto] fxc:sm:items-end">
                    <div>
                        <Label htmlFor={`fxc-download-left-${download.id}`} className="fxc:mb-2 fxc:block">
                            {__("Downloads left")}
                        </Label>
                        <Input
                            id={`fxc-download-left-${download.id}`}
                            inputMode="numeric"
                            value={remaining}
                            placeholder={__("No limit")}
                            onChange={(e) => setRemaining(e.target.value)}
                        />
                    </div>
                    <div>
                        <Label htmlFor={`fxc-download-expires-${download.id}`} className="fxc:mb-2 fxc:block">
                            {__("Access until")}
                        </Label>
                        <Input id={`fxc-download-expires-${download.id}`} type="date" value={expires} onChange={(e) => setExpires(e.target.value)} />
                    </div>
                    <div className="fxc:flex fxc:gap-2">
                        <Button size="sm" disabled={update.isPending} onClick={save}>
                            {__("Save")}
                        </Button>
                        <Button variant="ghost" size="sm" onClick={() => setEditing(false)}>
                            {__("Cancel")}
                        </Button>
                    </div>
                </div>
            )}
        </li>
    );
}
