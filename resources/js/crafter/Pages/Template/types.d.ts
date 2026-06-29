export type Template = {
    id: string | number;
    slug: string;
    name: string;
    title: string;
    locale: string;
    description: string;
    short_description: string;
    meta_title: string;
    meta_description: string;
    created_at: string;
    updated_at: string

};

export type TemplateForm = {
    name: string;
    locale: string;
    title: string;
    description: string;
    meta_title: string;
    meta_description: string;
    short_description: string;
};
