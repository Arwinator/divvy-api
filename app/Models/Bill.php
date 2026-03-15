<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Bill extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'group_id',
        'creator_id',
        'title',
        'total_amount',
        'bill_date',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2',
            'bill_date' => 'date',
        ];
    }

    /**
     * Get the group that the bill belongs to.
     */
    public function group()
    {
        return $this->belongsTo(Group::class);
    }

    /**
     * Get the creator of the bill.
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    /**
     * Get the shares for the bill.
     */
    public function shares()
    {
        return $this->hasMany(Share::class);
    }

    /**
     * Get the total amount paid for the bill.
     */
    public function getTotalPaidAttribute()
    {
        return $this->shares()
            ->where('status', 'paid')
            ->sum('amount');
    }

    /**
     * Check if the bill is fully settled.
     */
    public function getIsFullySettledAttribute()
    {
        return $this->total_amount == $this->total_paid;
    }
}
