import { AlignLeft, AtSign, Calendar, ChevronsUpDown, CircleDot, Hash, Phone, SquareCheck, Type } from "lucide-react";

import { __ } from "@/shared/lib/i18n";
import type { CustomType, Section } from "./useCheckoutLayout";

export const CUSTOM_TYPES: { value: CustomType; label: string; icon: typeof Type }[] = [
    { value: "text", label: __("Short text"), icon: Type },
    { value: "textarea", label: __("Long text"), icon: AlignLeft },
    { value: "select", label: __("Dropdown"), icon: ChevronsUpDown },
    { value: "radio", label: __("Radio buttons"), icon: CircleDot },
    { value: "checkbox", label: __("Checkbox"), icon: SquareCheck },
    { value: "number", label: __("Number"), icon: Hash },
    { value: "date", label: __("Date"), icon: Calendar },
    { value: "email", label: __("Email"), icon: AtSign },
    { value: "tel", label: __("Phone"), icon: Phone },
];

export const typeName = (type: CustomType | undefined) => CUSTOM_TYPES.find((t) => t.value === type)?.label ?? __("Short text");

export const SECTION_NAMES: Record<Section, string> = {
    billing: __("Billing details"),
    shipping: __("Shipping address"),
    additional: __("Additional information"),
};
