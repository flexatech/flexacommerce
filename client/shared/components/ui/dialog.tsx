import * as React from "react";
import * as DialogPrimitive from "@radix-ui/react-dialog";
import { X } from "lucide-react";

import { cn } from "@/shared/lib/cn";
import { __ } from "@/shared/lib/i18n";

const Dialog = DialogPrimitive.Root;
const DialogTrigger = DialogPrimitive.Trigger;
const DialogClose = DialogPrimitive.Close;

/**
 * The portal target is document.body, which carries the `.flexacommerce-ui`
 * scope class (added via admin_body_class), so utilities keep working here.
 */
const DialogContent = React.forwardRef<
    React.ElementRef<typeof DialogPrimitive.Content>,
    React.ComponentPropsWithoutRef<typeof DialogPrimitive.Content>
>(({ className, children, ...props }, ref) => (
    <DialogPrimitive.Portal>
        <DialogPrimitive.Overlay className="fxc:fixed fxc:inset-0 fxc:z-[160001] fxc:bg-black/50 fxc:data-[state=open]:animate-in fxc:data-[state=open]:fade-in-0 fxc:data-[state=closed]:animate-out fxc:data-[state=closed]:fade-out-0" />
        <DialogPrimitive.Content
            ref={ref}
            className={cn(
                "fxc:bg-card fxc:text-card-foreground fxc:fixed fxc:top-1/2 fxc:left-1/2 fxc:z-[160002] fxc:grid fxc:w-full fxc:max-w-md fxc:-translate-x-1/2 fxc:-translate-y-1/2 fxc:gap-4 fxc:rounded-xl fxc:border fxc:p-6 fxc:shadow-lg",
                "fxc:data-[state=open]:animate-in fxc:data-[state=open]:fade-in-0 fxc:data-[state=open]:zoom-in-95 fxc:data-[state=closed]:animate-out fxc:data-[state=closed]:fade-out-0 fxc:data-[state=closed]:zoom-out-95",
                className,
            )}
            {...props}
        >
            {children}
            <DialogPrimitive.Close className="fxc:text-muted-foreground fxc:hover:text-foreground fxc:focus-visible:ring-primary-accent fxc:absolute fxc:top-4 fxc:right-4 fxc:cursor-pointer fxc:rounded-sm fxc:bg-transparent fxc:opacity-70 fxc:transition-opacity fxc:outline-none fxc:hover:opacity-100 fxc:focus-visible:ring-2">
                <X aria-hidden className="fxc:h-4 fxc:w-4" />
                <span className="fxc:sr-only">{__("Close")}</span>
            </DialogPrimitive.Close>
        </DialogPrimitive.Content>
    </DialogPrimitive.Portal>
));
DialogContent.displayName = "DialogContent";

function DialogHeader({ className, ...props }: React.HTMLAttributes<HTMLDivElement>) {
    return <div className={cn("fxc:flex fxc:flex-col fxc:gap-1.5", className)} {...props} />;
}

function DialogFooter({ className, ...props }: React.HTMLAttributes<HTMLDivElement>) {
    return (
        <div
            className={cn("fxc:flex fxc:flex-col-reverse fxc:gap-2 fxc:sm:flex-row fxc:sm:justify-end", className)}
            {...props}
        />
    );
}

const DialogTitle = React.forwardRef<
    React.ElementRef<typeof DialogPrimitive.Title>,
    React.ComponentPropsWithoutRef<typeof DialogPrimitive.Title>
>(({ className, ...props }, ref) => (
    <DialogPrimitive.Title
        ref={ref}
        className={cn("fxc:text-foreground fxc:text-lg fxc:font-semibold", className)}
        {...props}
    />
));
DialogTitle.displayName = "DialogTitle";

const DialogDescription = React.forwardRef<
    React.ElementRef<typeof DialogPrimitive.Description>,
    React.ComponentPropsWithoutRef<typeof DialogPrimitive.Description>
>(({ className, ...props }, ref) => (
    <DialogPrimitive.Description
        ref={ref}
        className={cn("fxc:text-muted-foreground fxc:text-sm", className)}
        {...props}
    />
));
DialogDescription.displayName = "DialogDescription";

export {
    Dialog,
    DialogTrigger,
    DialogClose,
    DialogContent,
    DialogHeader,
    DialogFooter,
    DialogTitle,
    DialogDescription,
};
