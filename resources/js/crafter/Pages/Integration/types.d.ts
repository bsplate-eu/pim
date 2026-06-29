export type Integration = {
    id: string | number;
    type: string;
    name: string;
    manufacturer: string;
    key: string;
    url: string;
    integration_sources: Array<IntegrationSource>;
    enabled: boolean;
    created_at: string;
    updated_at: string

};

export type IntegrationForm = {
    type: string;
    name: string;
    manufacturer: string;
    key: string;
    url: string;
    integration_sources: Array<IntegrationSource>;
    enabled: boolean;
};

export type OverrideRow = {
    product_id: number;
    external_id: string | number | null;
    product_code: string;
    price: number | string | null;
    name: string | null;
    override_name: string | null;
    ean: string | null;
    override_ean: string | null;
    enabled: number | null;
    override_enabled: number | string | null;
};

export type IntegrationProductsForm = {
    rows: OverrideRow[];
};

export type IntegrationSource = {
    id: string | number;
    source_id: number;
    template_id: number;
    pricelist_id: number;
    tax: number;
    multiplier: decimal;
};
