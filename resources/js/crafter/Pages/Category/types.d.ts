

export type Category = {
    id: string | number; 
_lft: integer; 
_rgt: integer; 
parent_id: integer; 
name: Record<string, string>; 
created_at: string; 
updated_at: string
    
};

export type CategoryForm = {
    parent_id: integer; 
name: Record<string, string>
};
