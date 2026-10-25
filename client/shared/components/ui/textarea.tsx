import * as React from "react";

import { cn } from "@/shared/lib/cn";

const Textarea = React.forwardRef<HTMLTextAreaElement, React.ComponentProps<"textarea">>(({ className, ...props }, ref) => {
    return (
        <textarea
            ref={ref}
            rows={3}
            className={cn(
                "flexacommerce-control fxc:border-input fxc:bg-background fxc:text-foreground fxc:placeholder:text-muted-foreground fxc:flex fxc:w-full fxc:rounded-md fxc:border fxc:px-3 fxc:py-2 fxc:text-sm fxc:shadow-sm fxc:transition-colors fxc:outline-none",
                "fxc:focus-visible:border-primary fxc:focus-visible:ring-2 fxc:focus-visible:ring-primary-accent",
                "fxc:disabled:cursor-not-allowed fxc:disabled:opacity-50",
                className,
            )}
            {...props}
        />
    );
});
Textarea.displayName = "Textarea";

export { Textarea };
