import { useState } from "react";
import { AlertTriangle } from "lucide-react";

import { showToast } from "@/shared/components/showToast";
import { Button } from "@/shared/components/ui/button";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/shared/components/ui/dialog";
import { Input } from "@/shared/components/ui/input";
import { Label } from "@/shared/components/ui/label";
import { __ } from "@/shared/lib/i18n";
import { useSaveSettings } from "./useSettings";

// Must match SettingsEndpoint::CONFIRM_WORD.
const CONFIRM_WORD = "DELETE";

export function DataRemoval({ enabled }: { enabled: boolean }) {
    const [open, setOpen] = useState(false);
    const [confirm, setConfirm] = useState("");
    const save = useSaveSettings();

    const close = () => {
        if (save.isPending) {
            return;
        }
        setOpen(false);
        setConfirm("");
        save.reset();
    };

    const turnOn = () => {
        if (confirm.trim() !== CONFIRM_WORD) {
            return;
        }
        save.mutate(
            { remove_data_on_uninstall: true, confirm: CONFIRM_WORD },
            {
                onSuccess: () => {
                    setOpen(false);
                    setConfirm("");
                    showToast.success(__("Store data will be deleted when the plugin is uninstalled."));
                },
            },
        );
    };

    const turnOff = () =>
        save.mutate(
            { remove_data_on_uninstall: false },
            {
                onSuccess: () => showToast.success(__("Store data will be kept when the plugin is uninstalled.")),
                onError: (error) => showToast.error(error.message),
            },
        );

    return (
        <section className="fxc:border-destructive/30 fxc:bg-destructive/5 fxc:space-y-4 fxc:rounded-xl fxc:border fxc:p-5">
            <header className="fxc:flex fxc:items-start fxc:gap-3">
                <AlertTriangle aria-hidden className="fxc:text-destructive fxc:mt-0.5 fxc:h-5 fxc:w-5" />
                <div>
                    <h2 className="fxc:text-destructive fxc:text-base fxc:font-semibold">{__("Data removal")}</h2>
                    <p className="fxc:text-muted-foreground fxc:text-sm">
                        {enabled
                            ? __("On. Deleting the plugin will drop every FlexaCommerce table and option, including all orders.")
                            : __("Off. Deleting the plugin leaves your products, orders and settings in the database.")}
                    </p>
                </div>
            </header>

            {enabled ? (
                <Button variant="outline" onClick={turnOff} disabled={save.isPending}>
                    {__("Keep data on uninstall")}
                </Button>
            ) : (
                <Button variant="destructive" onClick={() => setOpen(true)}>
                    {__("Delete data on uninstall…")}
                </Button>
            )}

            <Dialog open={open} onOpenChange={(o) => (o ? setOpen(true) : close())}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{__("Delete all store data on uninstall?")}</DialogTitle>
                        <DialogDescription>
                            {__(
                                "When the plugin is deleted from the Plugins screen, every order, product variant, customer record and setting it stores will be removed. Nothing is deleted now.",
                            )}
                        </DialogDescription>
                    </DialogHeader>

                    <div>
                        <Label htmlFor="fxc-remove-confirm" className="fxc:mb-2 fxc:block">
                            {__("Type")} <code className="fxc:text-destructive fxc:font-mono">{CONFIRM_WORD}</code> {__("to confirm")}
                        </Label>
                        <Input
                            id="fxc-remove-confirm"
                            value={confirm}
                            onChange={(e) => setConfirm(e.target.value)}
                            autoComplete="off"
                            autoFocus
                        />
                        {save.isError && <p className="fxc:text-destructive fxc:mt-2 fxc:text-sm">{save.error.message}</p>}
                    </div>

                    <DialogFooter>
                        <Button variant="ghost" onClick={close} disabled={save.isPending}>
                            {__("Cancel")}
                        </Button>
                        <Button variant="destructive" onClick={turnOn} disabled={save.isPending || confirm.trim() !== CONFIRM_WORD}>
                            {save.isPending ? __("Saving…") : __("Turn on data removal")}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </section>
    );
}
