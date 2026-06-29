<?php

namespace App\Models;

use App\Helpers\Initials;
use App\Media\AutoProcessMediaTrait;
use App\Media\HasMediaPreviewsTrait;
use App\Media\InteractsWithMedia;
use App\Media\ProcessMediaTrait;
use Database\Factories\AdminUserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Permission\Traits\HasRoles;
use NotificationChannels\WebPush\HasPushSubscriptions;

/**
 * @property string $first_name
 * @property string $last_name
 * @property string $email
 * @property string $password
 */
class AdminUser extends Authenticatable implements MustVerifyEmail, HasMedia
{
    use HasApiTokens;
    use HasFactory;
    use Notifiable;
    use InteractsWithMedia;
    use ProcessMediaTrait;
    use AutoProcessMediaTrait;
    use HasMediaPreviewsTrait;
    use HasRoles;
    use SoftDeletes;
    use HasPushSubscriptions; // [argo-mail-pkg] Web Push — subskrypcje push na koncie panelu

    protected $guard = 'crafter';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'password',
        'locale',
        'active',
        'last_active_at',
        'invitation_sent_at',
        'invitation_accepted_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_active_at' => 'datetime',
        'password' => 'hashed',
    ];

    protected $appends = ['resource_url', 'avatar', 'avatar_url', 'media_details'];

    protected static function newFactory()
    {
        return AdminUserFactory::new();
    }

    /**
     * Get the user's initials.
     *
     * @return \Illuminate\Database\Eloquent\Casts\Attribute
     */
    protected function initials(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => Initials::new()->generate($this->first_name . ' ' . $this->last_name)
        );
    }

    /**
     * Get the user's avatar media object.
     *
     * @return \Illuminate\Database\Eloquent\Casts\Attribute
     */
    protected function avatar(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $this->getMedia('avatar')
        );
    }

    /**
     * Get the user's avatar URL.
     *
     * @return \Illuminate\Database\Eloquent\Casts\Attribute
     */
    protected function avatarUrl(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $this->getFirstMediaUrl('avatar', 'thumb')
        );
    }

    /**
     * Get the user's resource url.
     *
     * @return \Illuminate\Database\Eloquent\Casts\Attribute
     */
    protected function resourceUrl(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                if ($this->id) {
                    return route('crafter.admin-users.show', $this->id);
                }

                return null;
            }
        );
    }

    public function wasInvited()
    {
        return $this->invitation_sent_at !== null && $this->invitation_accepted_at === null;
    }

    public function assignedTasks(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(ArgoTask::class, 'argo_task_assignees', 'admin_user_id', 'argo_task_id')
            ->withPivot(['assigned_by', 'assigned_at'])
            ->withTimestamps();
    }

    public function registerMediaCollections(): void
    {
        $this
            ->addMediaCollection('avatar')
            ->acceptsMimeTypes(['image/png', 'image/jpeg', 'image/jpg', 'image/gif', 'image/svg+xml'])
            ->maxFileSize(2 * 1024 * 1024)
            ->singleFile();
    }

    public function registerMediaConversions(Media $media = null): void
    {
        $this->autoRegisterPreviews();

        $this->addMediaConversion('thumb')
            ->crop('crop-center', 40, 40)
            ->performOnCollections('avatar');
    }
}
