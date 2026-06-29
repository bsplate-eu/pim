import type { UploadedFile } from "crafter/types";

export type IntegrationProduct = {
    id: string | number;
    external_id: string;
    category: string;
    name: Record<string, string>;
    secondary_name: string;
    product_code: string;
    price: decimal;
    year_start: string;
    year_stop: string;
    width: decimal;
    weight: decimal;
    oil: boolean;
    enabled: boolean;
    engine: string;
    gearbox: string;
    related_products: string;
    comment: string;
    protection: Record<string, string>;
    images: Record<string, string>;
    created_at: string;
    updated_at: string
    media?: UploadedFile[];
};

export type IntegrationProductForm = {
    external_id: string;
    category: string;
    name: Record<string, string>;
    secondary_name: string;
    product_code: string;
    price: decimal;
    year_start: string;
    year_stop: string;
    width: decimal;
    weight: decimal;
    oil: boolean;
    enabled: boolean;
    engine: string;
    gearbox: string;
    related_products: string;
    comment: string;
    protection: Record<string, string>;
// images: Record<string, string>;
    images: Array<UploadedFile>
};
