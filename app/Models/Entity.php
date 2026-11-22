<?php

namespace App\Models;

use Database\Factories\EntityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Entity extends Model
{
    /** @use HasFactory<EntityFactory> */
    use HasFactory;

    use HasOrganizationCode;

    protected $fillable = ['code', 'name', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    public function divisions(): HasManyThrough
    {
        return $this->hasManyThrough(Division::class, Department::class);
    }

    public function employees(): HasManyThrough
    {
        return $this->hasManyThrough(Employee::class, Department::class);
    }
}
