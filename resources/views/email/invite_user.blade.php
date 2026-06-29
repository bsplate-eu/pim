<x-mail::message>

# {{ ___('crafter', 'Invite user') }}

You were invited by user {{ $userFullName }} to join {{ config('app.name') }}.

Please follow the link bellow to create your account.

<x-mail::button :url="route('crafter.invite-user.create', $email)">
{{ ___('crafter', 'Sign in') }}
</x-mail::button>

</x-mail::message>
