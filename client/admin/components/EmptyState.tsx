import { type ReactNode } from "react";
import { SearchX, type LucideIcon } from "lucide-react";

import { __ } from "@/shared/lib/i18n";

/**
 * What an empty list shows: an icon, a title and one line on how the list
 * fills up. With `filtered` the list is empty only because of a search or a
 * filter, so it says that instead.
 */
export function EmptyState({ icon: Icon, title, description, filtered = false, action }: { icon: LucideIcon; title: string; description?: string; filtered?: boolean; action?: ReactNode }) {
    const Shown = filtered ? SearchX : Icon;
    return (
        <div className="fxc:flex fxc:flex-col fxc:items-center fxc:px-6 fxc:py-12 fxc:text-center">
            <div className="fxc:bg-brand-50 fxc:text-primary fxc:mb-4 fxc:flex fxc:size-12 fxc:items-center fxc:justify-center fxc:rounded-full">
                <Shown aria-hidden className="fxc:size-6" />
            </div>
            <p className="fxc:text-foreground fxc:text-base fxc:font-semibold">{title}</p>
            <p className="fxc:text-muted-foreground fxc:mt-1 fxc:max-w-md fxc:text-sm">{filtered ? __("Try another search, or clear the filters.") : description}</p>
            {action && !filtered && <div className="fxc:mt-5">{action}</div>}
        </div>
    );
}
