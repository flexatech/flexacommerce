import * as React from "react";

import { cn } from "@/shared/lib/cn";

export interface SelectOption {
    label: string;
    value: string;
}

interface SelectProps extends React.ComponentProps<"select"> {
    options: SelectOption[];
}

const Select = React.forwardRef<HTMLSelectElement, SelectProps>(
    ({ className, options, ...props }, ref) => {
        return (
            <select
                ref={ref}
                className={cn(
                    "flexacommerce-control fxc:border-input fxc:bg-background fxc:text-foreground fxc:h-9 fxc:rounded-md fxc:border fxc:px-3 fxc:text-sm fxc:shadow-sm fxc:outline-none fxc:transition-colors",
                    "fxc:focus-visible:border-primary fxc:focus-visible:ring-2 fxc:focus-visible:ring-primary-accent",
                    className,
                )}
                {...props}
            >
                {options.map((opt) => (
                    <option key={opt.value} value={opt.value}>
                        {opt.label}
                    </option>
                ))}
            </select>
        );
    },
);
Select.displayName = "Select";

export { Select };
