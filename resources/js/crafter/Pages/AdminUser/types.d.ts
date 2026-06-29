export type AdminUserForm = AdminUserPasswordForm &
  AdminUserProfileForm & {
    locale: string;
    active: boolean;
    role_id: number | null;
  };

export type AdminUserPasswordForm = {
  password: string;
  password_confirmation: string;
};

export type AdminUserProfileForm = {
  first_name: string;
  last_name: string;
  email: string;
  locale: string;
  avatar: [];
};


export type AdminUserInviteUserForm = {
    email: string
    role_id: string,
}

export type InviteUserForm = {
    first_name: string;
    last_name: string;
    email: string;
    locale: string;
    password: string;
    password_confirmation: string;
    avatar: [];
};
