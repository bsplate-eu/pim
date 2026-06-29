

export type Pricelist = {
    id: string | number;
slug: string;
name: string;
currency: string;
price_formula?: string | null;
price_formula_mode?: string | null;
created_at: string;
updated_at: string

};

export type PriceRow = {
    product_id: number;
    product_code: string;
    name: string;
    price: number | string;
    auto_price: number | string;
    manual_price: number | string;
    purchase_price: number | string;
    source_id: number | null;
};

export type SourceOption = {
    id: number;
    name: string;
};

export type PricelistForm = {
    name: string;
    currency: string;
    rows: PriceRow[];
    price_formula: string;
    price_formula_mode: string;
};
