<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Group extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'name',
        'creator_id',
    ];

    /**
     * Get the creator of the group.
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    /**
     * Get the members of the group.
     */
    public function members()
    {
        return $this->belongsToMany(User::class, 'group_members')
            ->withPivot('joined_at');
    }

    /**
     * Get the bills for the group.
     */
    public function bills()
    {
        return $this->hasMany(Bill::class);
    }

    /**
     * Get the invitations for the group.
     */
    public function invitations()
    {
        return $this->hasMany(GroupInvitation::class);
    }
}
