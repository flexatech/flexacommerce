import * as React from "react";

import { cn } from "@/shared/lib/cn";

const Input = React.forwardRef<HTMLInputElement, React.ComponentProps<"input">>(
    ({ className, type = "text", ...props }, ref) => {
        return (
            <input
                type={type}
                ref={ref}
                className={cn(
                    "flexacommerce-control fxc:border-input fxc:bg-background fxc:text-foreground fxc:placeholder:text-muted-foreground fxc:flex fxc:h-9 fxc:w-full fxc:rounded-md fxc:border fxc:px-3 fxc:py-1 fxc:text-sm fxc:shadow-sm fxc:transition-colors fxc:outline-none",
                    "fxc:focus-visible:border-primary fxc:focus-visible:ring-2 fxc:focus-visible:ring-primary-accent",
                    "fxc:disabled:cursor-not-allowed fxc:disabled:opacity-50",
                    className,
                )}
                {...props}
            />
        );
    },
);
Input.displayName = "Input";

export { Input };
