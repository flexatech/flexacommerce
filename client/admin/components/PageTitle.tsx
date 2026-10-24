import { type ReactNode } from "react";

export function PageTitle({ title, description, actions }: { title: string; description?: string; actions?: ReactNode }) {
    return (
        <div className="fxc:mb-6 fxc:flex fxc:items-start fxc:justify-between fxc:gap-4">
            <div>
                <h1 className="fxc:text-foreground fxc:text-xl fxc:font-semibold">{title}</h1>
                {description && <p className="fxc:text-muted-foreground fxc:mt-1 fxc:text-sm">{description}</p>}
            </div>
            {actions && <div className="fxc:flex fxc:shrink-0 fxc:items-center fxc:gap-3">{actions}</div>}
        </div>
    );
}
