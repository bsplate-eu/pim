import type {UploadedFile} from "crafter/types";

export type Product = {
    id: string | number;
    source_id: number;
    external_id: string;
    ean: string;
    category_ids: Array<number>;
    category: string;
    name: Record<string, string>;
    product_code: string;
    width: number;
    weight: number;
    info_1: string;
    info_2: string;
    info_3: string;
    meta_url: string|null,
    meta_title: string|null,
    meta_description: string|null,
    meta_keywords: string|null,
    attribute_values: Object;
    images: Array<UploadedFile>;
    pricelists: Array<Object>;
    enabled: boolean;
    created_at: string;
    updated_at: string;
    media?: UploadedFile[];
};

export type ProductForm = {
    source_id: number;
    external_id: string;
    ean: string;
    category_ids: Array<number>;
    category: string;
    name: Record<string, string>;
    product_code: string;
    width: number;
    weight: number;
    info_1: string|null;
    info_2: string|null;
    info_3: string|null;
    meta_url: string|null,
    meta_title: string|null,
    meta_description: string|null,
    meta_keywords: string|null,
    attribute_values: Object;
    images: Array<UploadedFile>;
    pricelists: Array<Object>;
    enabled: boolean;
};
