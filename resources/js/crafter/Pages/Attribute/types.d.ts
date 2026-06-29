export type Attribute = {
    id: string | number;
    name: Record<string, string>;
    order: number;
    attribute_values: Record<AttributeValue>;
    created_at: string;
    updated_at: string

};

export type AttributeForm = {
    name: Record<string, string>
    attribute_values: Record<AttributeValue>;
};
