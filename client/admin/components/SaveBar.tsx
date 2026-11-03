import { CircleCheck, CircleDot } from "lucide-react";

import { Button } from "@/shared/components/ui/button";
import { cn } from "@/shared/lib/cn";
import { __ } from "@/shared/lib/i18n";

/**
 * The save action of a settings panel, after the cards. Once a field
 * changes it sticks to the bottom of the window, so Save stays in reach on a
 * long form; with nothing to save it stays in place.
 */
export function SaveBar({ dirty, pending, onSave, onDiscard, label }: { dirty: boolean; pending: boolean; onSave: () => void; onDiscard?: () => void; label?: string }) {
    return (
        <div
            className={cn(
                "fxc:bg-background/95 fxc:flex fxc:items-center fxc:gap-3 fxc:rounded-lg fxc:border fxc:px-4 fxc:py-3 fxc:backdrop-blur fxc:transition-shadow",
                dirty && "fxc:border-primary/40 fxc:sticky fxc:bottom-4 fxc:z-30 fxc:shadow-lg",
            )}
        >
            <span className={cn("fxc:flex fxc:flex-1 fxc:items-center fxc:gap-2 fxc:text-sm", dirty ? "fxc:text-foreground fxc:font-medium" : "fxc:text-muted-foreground")}>
                {dirty ? <CircleDot aria-hidden className="fxc:text-primary fxc:size-4" /> : <CircleCheck aria-hidden className="fxc:size-4" />}
                {dirty ? __("Unsaved changes") : __("All changes saved")}
            </span>
            {dirty && onDiscard && (
                <Button variant="ghost" onClick={onDiscard} disabled={pending}>
                    {__("Discard")}
                </Button>
            )}
            <Button onClick={onSave} disabled={!dirty || pending}>
                {pending ? __("Saving…") : (label ?? __("Save changes"))}
            </Button>
        </div>
    );
}
