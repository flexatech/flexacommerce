import { type ReactNode } from "react";
import { type LucideIcon } from "lucide-react";

import { Label } from "@/shared/components/ui/label";

interface SettingRowProps {
    icon: LucideIcon;
    label: string;
    htmlFor?: string;
    description?: string;
    control: ReactNode;
}

export function SettingRow({ icon: Icon, label, htmlFor, description, control }: SettingRowProps) {
    return (
        <div className="fxc:flex fxc:items-center fxc:gap-3 fxc:border-b fxc:px-5 fxc:py-4 fxc:last:border-b-0">
            <div className="fxc:bg-muted fxc:text-primary fxc:flex fxc:h-9 fxc:w-9 fxc:shrink-0 fxc:items-center fxc:justify-center fxc:rounded-lg">
                <Icon aria-hidden className="fxc:h-4 fxc:w-4" />
            </div>
            <div className="fxc:min-w-0 fxc:flex-1">
                <Label htmlFor={htmlFor} className="fxc:block">
                    {label}
                </Label>
                {description && <p className="fxc:text-muted-foreground fxc:text-xs">{description}</p>}
            </div>
            <div className="fxc:shrink-0">{control}</div>
        </div>
    );
}
