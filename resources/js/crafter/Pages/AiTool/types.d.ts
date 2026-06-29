

export type AiTool = {
    id: string | number; 
name: Record<string, string>; 
description: Record<string, string>; 
provider: string; 
config: Record<string, string>; 
enabled: boolean; 
order: integer; 
created_at: string; 
updated_at: string
    
};

export type AiToolForm = {
    name: Record<string, string>; 
description: Record<string, string>; 
provider: string; 
config: Record<string, string>; 
enabled: boolean; 
order: integer
};
