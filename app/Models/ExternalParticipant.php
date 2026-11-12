<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExternalParticipant extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'email', 'phone', 'organization', 'position', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Scope active participants.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope inactive participants.
     */
    public function scopeInactive(Builder $query): Builder
    {
        return $query->where('is_active', false);
    }

    /**
     * Filter by organization.
     */
    public function scopeOrganization(Builder $query, string $organization): Builder
    {
        return $query->where('organization', $organization);
    }
}
