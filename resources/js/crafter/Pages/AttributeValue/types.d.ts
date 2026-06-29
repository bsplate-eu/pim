

export type AttributeValue = {
    id: string | number; 
attribute_id: string; 
name: Record<string, string>; 
created_at: string; 
updated_at: string
    
};

export type AttributeValueForm = {
    attribute_id: string; 
name: Record<string, string>
};
