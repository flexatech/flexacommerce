import { FolderOpen, Plus, Trash2 } from "lucide-react";

import { canPickImage, pickFile } from "../../components/pickImage";
import { Button } from "@/shared/components/ui/button";
import { Input } from "@/shared/components/ui/input";
import { __, sprintf } from "@/shared/lib/i18n";
import { Field, IntInput } from "./fields";
import type { FieldErrors, RowChange } from "./RowFields";
import type { DownloadFile, VariantRow } from "./types";

/**
 * Files a downloadable row sells, with the download limit and expiry
 * (ADR-058). A file keeps its id across saves so customers who bought it
 * keep their links; a removed file loses them.
 */
export function DownloadFields({ row, onChange, errors, path }: { row: VariantRow; onChange: RowChange; errors: FieldErrors; path: string }) {
    const { downloads } = row;
    const setFiles = (files: DownloadFile[]) => onChange({ downloads: { ...downloads, files } });
    const setFile = (index: number, patch: Partial<DownloadFile>) => setFiles(downloads.files.map((file, i) => (i === index ? { ...file, ...patch } : file)));
    const choose = (index: number) =>
        pickFile(__("Choose a file to sell"), (picked) => {
            const current = downloads.files[index];
            if (index === downloads.files.length) {
                setFiles([...downloads.files, { id: "", name: picked.title || picked.filename, file: picked.url }]);
            } else if (current) {
                setFile(index, { file: picked.url, name: current.name === "" ? picked.title || picked.filename : current.name });
            }
        });
    const picker = canPickImage();

    return (
        <div className="fxc:space-y-4">
            {downloads.files.length === 0 ? (
                <p className="fxc:text-muted-foreground fxc:text-sm">{__("Add the files a customer gets after paying. Files uploaded here are kept out of reach of direct links.")}</p>
            ) : (
                <ul className="fxc:space-y-3">
                    {downloads.files.map((file, index) => (
                        <li key={file.id || `new-${index}`} className="fxc:border-border fxc:grid fxc:gap-3 fxc:rounded-md fxc:border fxc:p-3 fxc:sm:grid-cols-[1fr_2fr_auto] fxc:sm:items-end">
                            <Field label={__("File name")} error={errors[`${path}.downloads.files.${index}.name`]}>
                                {(id) => <Input id={id} value={file.name} maxLength={200} onChange={(event) => setFile(index, { name: event.target.value })} />}
                            </Field>
                            <Field label={__("File URL")} error={errors[`${path}.downloads.files.${index}.file`]}>
                                {(id) => (
                                    <div className="fxc:flex fxc:gap-2">
                                        <Input id={id} value={file.file} placeholder="https://" onChange={(event) => setFile(index, { file: event.target.value })} />
                                        {picker && (
                                            <Button variant="outline" size="sm" className="fxc:h-9 fxc:shrink-0" onClick={() => choose(index)}>
                                                <FolderOpen aria-hidden className="fxc:size-4" />
                                                {__("Choose")}
                                            </Button>
                                        )}
                                    </div>
                                )}
                            </Field>
                            <Button
                                variant="ghost"
                                size="icon"
                                aria-label={sprintf(__("Remove %s"), file.name || __("file"))}
                                onClick={() => setFiles(downloads.files.filter((_, i) => i !== index))}
                            >
                                <Trash2 aria-hidden className="fxc:size-4" />
                            </Button>
                        </li>
                    ))}
                </ul>
            )}
            {errors[`${path}.downloads.files`] && <p className="fxc:text-destructive fxc:text-xs">{errors[`${path}.downloads.files`]}</p>}
            <div className="fxc:flex fxc:flex-wrap fxc:gap-2">
                {picker && (
                    <Button variant="outline" size="sm" onClick={() => choose(downloads.files.length)}>
                        <FolderOpen aria-hidden className="fxc:size-4" />
                        {__("Upload or choose a file")}
                    </Button>
                )}
                <Button variant="outline" size="sm" onClick={() => setFiles([...downloads.files, { id: "", name: "", file: "" }])}>
                    <Plus aria-hidden className="fxc:size-4" />
                    {__("Add a file URL")}
                </Button>
            </div>
            <div className="fxc:grid fxc:gap-4 fxc:sm:grid-cols-2">
                <Field label={__("Download limit")} error={errors[`${path}.downloads.limit`]} help={__("Per item bought. Leave empty for no limit.")}>
                    {(id) => (
                        <IntInput id={id} value={downloads.limit} placeholder={__("No limit")} onChange={(limit) => onChange({ downloads: { ...downloads, limit } })} />
                    )}
                </Field>
                <Field label={__("Download expiry")} error={errors[`${path}.downloads.expiry_days`]} help={__("Days after payment. Leave empty to never expire.")}>
                    {(id) => (
                        <IntInput
                            id={id}
                            value={downloads.expiry_days}
                            placeholder={__("Never")}
                            onChange={(expiry_days) => onChange({ downloads: { ...downloads, expiry_days } })}
                        />
                    )}
                </Field>
            </div>
        </div>
    );
}
