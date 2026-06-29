

export type Source = {
    id: string | number; 
name: string; 
service_class: string; 
options: Record<string, string>; 
enabled: boolean; 
created_at: string; 
updated_at: string
    
};

export type SourceForm = {
    name: string; 
service_class: string; 
options: Record<string, string>; 
enabled: boolean
};
